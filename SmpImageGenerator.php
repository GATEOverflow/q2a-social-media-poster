<?php

/**
 * Generates images from text for Instagram posts.
 * Uses GD library to render question text onto an image.
 */
class SmpImageGenerator
{
    private int $width;
    private int $height;
    private array $bgColor;
    private array $textColor;
    private int $fontSize;
    private string $fontPath;
    private ?string $logoUrl;

    public function __construct()
    {
        $this->width = (int)(qa_opt(SmpConstants::OPT_IMAGE_WIDTH) ?: 1080);
        $this->height = (int)(qa_opt(SmpConstants::OPT_IMAGE_HEIGHT) ?: 1080);
        $this->bgColor = $this->hexToRgb(qa_opt(SmpConstants::OPT_IMAGE_BG_COLOR) ?: '#FFFFFF');
        $this->textColor = $this->hexToRgb(qa_opt(SmpConstants::OPT_IMAGE_TEXT_COLOR) ?: '#333333');
        $this->fontSize = (int)(qa_opt(SmpConstants::OPT_IMAGE_FONT_SIZE) ?: 28);
        $this->logoUrl = qa_opt(SmpConstants::OPT_IMAGE_LOGO_URL) ?: null;

        // Use a bundled font or system font
        $this->fontPath = __DIR__ . '/fonts/DejaVuSans.ttf';
        if (!file_exists($this->fontPath)) {
            // Fallback to common system fonts
            $systemFonts = [
                '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
                '/usr/share/fonts/TTF/DejaVuSans.ttf',
                '/usr/share/fonts/dejavu/DejaVuSans.ttf',
            ];
            foreach ($systemFonts as $sf) {
                if (file_exists($sf)) {
                    $this->fontPath = $sf;
                    break;
                }
            }
        }
    }

    /**
     * Generate an image from question text and return its public URL.
     *
     * @param string $text The question text (HTML stripped)
     * @param string $title Optional title to display prominently
     * @param int|null $postId Optional post ID for unique filename
     * @return string|null Public URL of the generated image, or null on failure
     */
    public function generateFromText(string $text, string $title = '', ?int $postId = null): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        // Strip HTML tags and decode entities
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');

        $img = imagecreatetruecolor($this->width, $this->height);
        if (!$img) {
            return null;
        }

        // Background
        $bg = imagecolorallocate($img, $this->bgColor[0], $this->bgColor[1], $this->bgColor[2]);
        imagefilledrectangle($img, 0, 0, $this->width - 1, $this->height - 1, $bg);

        $textCol = imagecolorallocate($img, $this->textColor[0], $this->textColor[1], $this->textColor[2]);

        $padding = 60;
        $yOffset = $padding;

        // Draw logo if configured
        if ($this->logoUrl && file_exists($this->logoUrl)) {
            $logoInfo = getimagesize($this->logoUrl);
            if ($logoInfo) {
                $logo = $this->loadImage($this->logoUrl, $logoInfo[2]);
                if ($logo) {
                    $logoW = $logoInfo[0];
                    $logoH = $logoInfo[1];
                    $maxLogoH = 80;
                    if ($logoH > $maxLogoH) {
                        $ratio = $maxLogoH / $logoH;
                        $logoW = (int)($logoW * $ratio);
                        $logoH = $maxLogoH;
                    }
                    $logoX = ($this->width - $logoW) / 2;
                    imagecopyresampled($img, $logo, (int)$logoX, $yOffset, 0, 0, $logoW, $logoH, $logoInfo[0], $logoInfo[1]);
                    imagedestroy($logo);
                    $yOffset += $logoH + 30;
                }
            }
        }

        // Draw title
        if (!empty($title)) {
            $titleSize = $this->fontSize + 6;
            $wrappedTitle = $this->wrapText($title, $titleSize, $this->width - 2 * $padding);
            foreach ($wrappedTitle as $line) {
                if ($yOffset > $this->height - $padding) break;
                imagettftext($img, $titleSize, 0, $padding, $yOffset + $titleSize, $textCol, $this->fontPath, $line);
                $yOffset += $titleSize + 10;
            }
            $yOffset += 20;
        }

        // Draw separator line
        $sepColor = imagecolorallocate($img, 200, 200, 200);
        imageline($img, $padding, $yOffset, $this->width - $padding, $yOffset, $sepColor);
        $yOffset += 20;

        // Draw body text
        $wrappedText = $this->wrapText($text, $this->fontSize, $this->width - 2 * $padding);
        foreach ($wrappedText as $line) {
            if ($yOffset > $this->height - $padding - 40) {
                // Add ellipsis if text is truncated
                imagettftext($img, $this->fontSize, 0, $padding, $yOffset + $this->fontSize, $textCol, $this->fontPath, '...');
                break;
            }
            imagettftext($img, $this->fontSize, 0, $padding, $yOffset + $this->fontSize, $textCol, $this->fontPath, $line);
            $yOffset += $this->fontSize + 8;
        }

        // Draw site name at bottom
        $siteName = qa_opt('site_title') ?: qa_opt('site_name') ?: '';
        if (!empty($siteName)) {
            $footerColor = imagecolorallocate($img, 150, 150, 150);
            $footerSize = $this->fontSize - 6;
            imageline($img, $padding, $this->height - $padding - 30, $this->width - $padding, $this->height - $padding - 30, $sepColor);
            imagettftext($img, $footerSize, 0, $padding, $this->height - $padding, $footerColor, $this->fontPath, $siteName);
        }

        // Save image
        $uploadDir = QA_BASE_DIR . 'qa-uploads/smp-images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'smp_' . ($postId ?: uniqid()) . '_' . time() . '.png';
        $filepath = $uploadDir . $filename;

        imagepng($img, $filepath, 8);
        imagedestroy($img);

        if (!file_exists($filepath)) {
            return null;
        }

        // Return public URL
        $siteUrl = rtrim(qa_opt('site_url'), '/');
        return $siteUrl . '/qa-uploads/smp-images/' . $filename;
    }

    /**
     * Word-wrap text to fit within a given pixel width.
     */
    private function wrapText(string $text, int $fontSize, int $maxWidth): array
    {
        $lines = [];
        $paragraphs = explode("\n", $text);

        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if ($paragraph === '') {
                $lines[] = '';
                continue;
            }

            $words = explode(' ', $paragraph);
            $currentLine = '';

            foreach ($words as $word) {
                $testLine = $currentLine === '' ? $word : $currentLine . ' ' . $word;
                $bbox = imagettfbbox($fontSize, 0, $this->fontPath, $testLine);
                $lineWidth = abs($bbox[2] - $bbox[0]);

                if ($lineWidth > $maxWidth && $currentLine !== '') {
                    $lines[] = $currentLine;
                    $currentLine = $word;
                } else {
                    $currentLine = $testLine;
                }
            }

            if ($currentLine !== '') {
                $lines[] = $currentLine;
            }
        }

        return $lines;
    }

    /**
     * Load an image from file based on type.
     */
    private function loadImage(string $path, int $type)
    {
        switch ($type) {
            case IMAGETYPE_PNG:
                return imagecreatefrompng($path);
            case IMAGETYPE_JPEG:
                return imagecreatefromjpeg($path);
            case IMAGETYPE_GIF:
                return imagecreatefromgif($path);
            default:
                return null;
        }
    }

    /**
     * Generate a visually rich quote image for Instagram.
     * Features: gradient background, decorative quote marks, centered quote,
     * attribution line, and site branding (logo + title) at the bottom.
     *
     * @param string $quoteText Full quote text (may include attribution and hashtags)
     * @return string|null Public URL of the generated image, or null on failure
     */
    public function generateQuoteImage(string $quoteText): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $w = $this->width;
        $h = $this->height;

        $img = imagecreatetruecolor($w, $h);
        if (!$img) {
            return null;
        }

        // --- Gradient background (deep purple → dark blue) ---
        $topR = 42;  $topG = 17;  $topB = 82;   // #2a1152
        $botR = 13;  $botG = 27;  $botB = 62;    // #0d1b3e
        for ($y = 0; $y < $h; $y++) {
            $ratio = $y / max($h - 1, 1);
            $r = (int)($topR + ($botR - $topR) * $ratio);
            $g = (int)($topG + ($botG - $topG) * $ratio);
            $b = (int)($topB + ($botB - $topB) * $ratio);
            $lineCol = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w - 1, $y, $lineCol);
        }

        // --- Subtle decorative overlay circles ---
        $circleCol = imagecolorallocatealpha($img, 255, 255, 255, 120);
        imagefilledellipse($img, (int)($w * 0.85), (int)($h * 0.15), 300, 300, $circleCol);
        imagefilledellipse($img, (int)($w * 0.10), (int)($h * 0.80), 200, 200, $circleCol);

        $padding = 80;
        $innerW = $w - 2 * $padding;
        $yOffset = $padding;

        // --- Fonts ---
        $fontRegular = $this->fontPath;
        $fontBold = str_replace('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', $this->fontPath);
        if (!file_exists($fontBold)) {
            $fontBold = $fontRegular;
        }
        $fontSerif = str_replace('DejaVuSans.ttf', 'DejaVuSerif.ttf', $this->fontPath);
        if (!file_exists($fontSerif)) {
            $fontSerif = $fontRegular;
        }
        $fontSerifBold = str_replace('DejaVuSans.ttf', 'DejaVuSerif-Bold.ttf', $this->fontPath);
        if (!file_exists($fontSerifBold)) {
            $fontSerifBold = $fontSerif;
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $lightGray = imagecolorallocate($img, 200, 200, 220);
        $accent = imagecolorallocate($img, 199, 146, 234);  // soft lavender accent

        // --- "QUOTE OF THE DAY" header ---
        $headerSize = 16;
        $headerText = 'QUOTE OF THE DAY';
        $headerBox = imagettfbbox($headerSize, 0, $fontBold, $headerText);
        $headerW = abs($headerBox[2] - $headerBox[0]);
        $headerX = ($w - $headerW) / 2;
        imagettftext($img, $headerSize, 0, (int)$headerX, $yOffset + $headerSize, $accent, $fontBold, $headerText);
        $yOffset += $headerSize + 10;

        // Draw a small accent line under header
        $lineHalfW = 40;
        $lineY = $yOffset;
        imageline($img, (int)(($w - $lineHalfW * 2) / 2), $lineY, (int)(($w + $lineHalfW * 2) / 2), $lineY, $accent);
        $yOffset += 35;

        // --- Parse quote: separate body, attribution, and hashtags ---
        $rawQuote = html_entity_decode(strip_tags($quoteText), ENT_QUOTES, 'UTF-8');

        // Extract hashtags (everything starting with #word at end)
        $hashtags = '';
        if (preg_match('/\s*((?:#\w+\s*){1,})\s*$/', $rawQuote, $m)) {
            $hashtags = trim($m[1]);
            $rawQuote = trim(substr($rawQuote, 0, -strlen($m[0])));
        }

        // Extract attribution: look for — or - followed by name at end
        $attribution = '';
        if (preg_match('/\s*[—–\-]\s*(.{3,80})$/u', $rawQuote, $m)) {
            $attribution = trim($m[1]);
            $rawQuote = trim(substr($rawQuote, 0, -strlen($m[0])));
        }

        // Clean up surrounding quotes
        $rawQuote = trim($rawQuote, " \t\n\r\0\x0B\"'\xe2\x80\x9c\xe2\x80\x9d\xe2\x80\x98\xe2\x80\x99");

        // --- Large opening quotation mark ---
        $quoteMarkSize = 90;
        $quoteMarkText = "\xe2\x80\x9c"; // left double quotation mark "
        $qmBox = imagettfbbox($quoteMarkSize, 0, $fontSerifBold, $quoteMarkText);
        $qmW = abs($qmBox[2] - $qmBox[0]);
        imagettftext($img, $quoteMarkSize, 0, (int)(($w - $qmW) / 2), $yOffset + $quoteMarkSize - 20, $accent, $fontSerifBold, $quoteMarkText);
        $yOffset += $quoteMarkSize + 10;

        // --- Quote body text (centered, white, serif) ---
        $quoteFontSize = $this->fontSize + 2;
        // Adjust font size if quote is very long
        $quoteLen = mb_strlen($rawQuote);
        if ($quoteLen > 300) {
            $quoteFontSize = max(20, $this->fontSize - 4);
        } elseif ($quoteLen > 200) {
            $quoteFontSize = max(22, $this->fontSize - 2);
        }

        $wrappedQuote = $this->wrapText($rawQuote, $quoteFontSize, $innerW);
        $lineHeight = $quoteFontSize + 12;
        $totalQuoteH = count($wrappedQuote) * $lineHeight;

        // Calculate vertical centering of quote in available space
        $bottomReserved = 200; // space for attribution + branding
        $availH = $h - $yOffset - $bottomReserved;
        if ($totalQuoteH < $availH) {
            $yOffset += (int)(($availH - $totalQuoteH) / 2);
        }

        foreach ($wrappedQuote as $line) {
            if ($yOffset > $h - $bottomReserved) {
                imagettftext($img, $quoteFontSize, 0, $padding, $yOffset + $quoteFontSize, $white, $fontSerif, '...');
                $yOffset += $lineHeight;
                break;
            }
            // Center each line
            $bbox = imagettfbbox($quoteFontSize, 0, $fontSerif, $line);
            $lineW = abs($bbox[2] - $bbox[0]);
            $lineX = ($w - $lineW) / 2;
            imagettftext($img, $quoteFontSize, 0, (int)$lineX, $yOffset + $quoteFontSize, $white, $fontSerif, $line);
            $yOffset += $lineHeight;
        }

        // --- Closing quotation mark ---
        $closeMarkSize = 50;
        $closeMarkText = "\xe2\x80\x9d"; // right double quotation mark "
        $cmBox = imagettfbbox($closeMarkSize, 0, $fontSerifBold, $closeMarkText);
        $cmW = abs($cmBox[2] - $cmBox[0]);
        $yOffset += 5;
        imagettftext($img, $closeMarkSize, 0, (int)(($w + $innerW / 2 - $cmW) / 2), $yOffset + $closeMarkSize - 15, $accent, $fontSerifBold, $closeMarkText);
        $yOffset += $closeMarkSize;

        // --- Attribution ---
        if (!empty($attribution)) {
            $attrSize = $this->fontSize - 4;
            $attrText = "\xe2\x80\x94 " . $attribution; // — dash
            $attrBox = imagettfbbox($attrSize, 0, $fontRegular, $attrText);
            $attrW = abs($attrBox[2] - $attrBox[0]);
            $attrX = ($w - $attrW) / 2;
            $attrY = min($yOffset + 10, $h - 140);
            imagettftext($img, $attrSize, 0, (int)$attrX, (int)($attrY + $attrSize), $lightGray, $fontRegular, $attrText);
        }

        // --- Bottom branding strip ---
        $brandY = $h - 90;

        // Subtle separator line
        $sepCol = imagecolorallocatealpha($img, 255, 255, 255, 100);
        imageline($img, $padding, $brandY, $w - $padding, $brandY, $sepCol);
        $brandY += 20;

        $siteName = qa_opt('site_title') ?: qa_opt('site_name') ?: '';
        $brandFontSize = 18;
        $brandX = $padding;

        // Draw logo if available
        if ($this->logoUrl && file_exists($this->logoUrl)) {
            $logoInfo = getimagesize($this->logoUrl);
            if ($logoInfo) {
                $logo = $this->loadImage($this->logoUrl, $logoInfo[2]);
                if ($logo) {
                    $logoW = $logoInfo[0];
                    $logoH = $logoInfo[1];
                    $maxLogoH = 40;
                    if ($logoH > $maxLogoH) {
                        $ratio = $maxLogoH / $logoH;
                        $logoW = (int)($logoW * $ratio);
                        $logoH = $maxLogoH;
                    }
                    // Center logo + site name together
                    $siteNameBox = imagettfbbox($brandFontSize, 0, $fontBold, $siteName);
                    $siteNameW = !empty($siteName) ? abs($siteNameBox[2] - $siteNameBox[0]) : 0;
                    $gap = !empty($siteName) ? 15 : 0;
                    $totalBrandW = $logoW + $gap + $siteNameW;
                    $brandX = (int)(($w - $totalBrandW) / 2);

                    $logoY = $brandY + (int)(($brandFontSize - $logoH) / 2);
                    imagecopyresampled($img, $logo, $brandX, $logoY, 0, 0, $logoW, $logoH, $logoInfo[0], $logoInfo[1]);
                    imagedestroy($logo);
                    $brandX += $logoW + $gap;
                }
            }
        } else {
            // Center site name only
            if (!empty($siteName)) {
                $siteBox = imagettfbbox($brandFontSize, 0, $fontBold, $siteName);
                $siteNameW = abs($siteBox[2] - $siteBox[0]);
                $brandX = (int)(($w - $siteNameW) / 2);
            }
        }

        // Draw site name
        if (!empty($siteName)) {
            imagettftext($img, $brandFontSize, 0, $brandX, $brandY + $brandFontSize, $white, $fontBold, $siteName);
        }

        // --- Hashtags in small text at very bottom ---
        if (!empty($hashtags)) {
            $hashSize = 12;
            $hashBox = imagettfbbox($hashSize, 0, $fontRegular, $hashtags);
            $hashW = abs($hashBox[2] - $hashBox[0]);
            $hashX = ($w - $hashW) / 2;
            $hashY = $h - 20;
            $hashCol = imagecolorallocatealpha($img, 199, 146, 234, 40);
            imagettftext($img, $hashSize, 0, (int)max($padding, $hashX), $hashY, $hashCol, $fontRegular, $hashtags);
        }

        // --- Save image ---
        $uploadDir = QA_BASE_DIR . 'qa-uploads/smp-images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'smp_quote_' . date('Ymd') . '_' . uniqid() . '.png';
        $filepath = $uploadDir . $filename;

        imagesavealpha($img, true);
        imagepng($img, $filepath, 8);
        imagedestroy($img);

        if (!file_exists($filepath)) {
            return null;
        }

        $siteUrl = rtrim(qa_opt('site_url'), '/');
        return $siteUrl . '/qa-uploads/smp-images/' . $filename;
    }

    /**
     * Convert hex color string to RGB array.
     */
    private function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
            return [255, 255, 255];
        }
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}
