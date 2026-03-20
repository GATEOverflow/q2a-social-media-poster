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
        $this->drawBranding($img, $w, $h, $padding, $fontBold, $white);

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
        return $this->saveImage($img, 'smp_quote_' . date('Ymd') . '_' . uniqid());
    }

    /**
     * Generate a visually rich exam announcement image for Instagram.
     * Features: gradient background (teal-blue), exam icon, bold title, site branding.
     *
     * @param string $title Exam title
     * @param int|null $postId Post ID for unique filename
     * @return string|null Public URL of the generated image, or null on failure
     */
    public function generateExamImage(string $title, ?int $postId = null): ?string
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

        // --- Gradient background (dark teal → deep blue) ---
        $topR = 0;   $topG = 77;  $topB = 64;    // #004d40
        $botR = 13;  $botG = 71;  $botB = 161;    // #0d47a1
        for ($y = 0; $y < $h; $y++) {
            $ratio = $y / max($h - 1, 1);
            $r = (int)($topR + ($botR - $topR) * $ratio);
            $g = (int)($topG + ($botG - $topG) * $ratio);
            $b = (int)($topB + ($botB - $botB) * $ratio);
            $lineCol = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w - 1, $y, $lineCol);
        }

        // --- Decorative geometric elements ---
        $circleCol = imagecolorallocatealpha($img, 255, 255, 255, 118);
        imagefilledellipse($img, (int)($w * 0.90), (int)($h * 0.10), 250, 250, $circleCol);
        imagefilledellipse($img, (int)($w * 0.05), (int)($h * 0.90), 180, 180, $circleCol);

        // Accent bar at top
        $accentCol = imagecolorallocate($img, 0, 200, 170); // teal accent
        imagefilledrectangle($img, 0, 0, $w - 1, 8, $accentCol);

        $padding = 80;
        $innerW = $w - 2 * $padding;
        $yOffset = 60;

        // --- Fonts ---
        $fontRegular = $this->fontPath;
        $fontBold = str_replace('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', $this->fontPath);
        if (!file_exists($fontBold)) {
            $fontBold = $fontRegular;
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $lightGray = imagecolorallocate($img, 180, 200, 220);
        $accent = imagecolorallocate($img, 0, 230, 190); // bright teal

        // --- Exam icon (pencil/paper symbol using text) ---
        $iconSize = 60;
        $iconText = "\xe2\x9c\x8f"; // pencil ✏
        $iconBox = imagettfbbox($iconSize, 0, $fontRegular, $iconText);
        $iconW = abs($iconBox[2] - $iconBox[0]);
        imagettftext($img, $iconSize, 0, (int)(($w - $iconW) / 2), $yOffset + $iconSize, $accent, $fontRegular, $iconText);
        $yOffset += $iconSize + 30;

        // --- "NEW EXAM" badge ---
        $badgeSize = 20;
        $badgeText = 'NEW EXAM';
        $badgeBox = imagettfbbox($badgeSize, 0, $fontBold, $badgeText);
        $badgeW = abs($badgeBox[2] - $badgeBox[0]);
        $badgeH = abs($badgeBox[7] - $badgeBox[1]);
        $badgePadX = 25;
        $badgePadY = 12;
        $badgeX = (int)(($w - $badgeW - 2 * $badgePadX) / 2);
        // Badge background pill
        $pillCol = imagecolorallocatealpha($img, 0, 200, 170, 40);
        imagefilledrectangle($img, $badgeX, $yOffset, $badgeX + $badgeW + 2 * $badgePadX, $yOffset + $badgeH + 2 * $badgePadY, $pillCol);
        imagettftext($img, $badgeSize, 0, $badgeX + $badgePadX, $yOffset + $badgePadY + $badgeSize, $white, $fontBold, $badgeText);
        $yOffset += $badgeH + 2 * $badgePadY + 40;

        // --- Exam title (centered, large, bold, white) ---
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');
        $titleSize = $this->fontSize + 8;
        $titleLen = mb_strlen($title);
        if ($titleLen > 100) {
            $titleSize = max(24, $this->fontSize);
        } elseif ($titleLen > 60) {
            $titleSize = $this->fontSize + 4;
        }

        $wrappedTitle = $this->wrapText($title, $titleSize, $innerW);
        $titleLineH = $titleSize + 14;
        $totalTitleH = count($wrappedTitle) * $titleLineH;

        // Center title vertically in available space
        $bottomReserved = 180;
        $availH = $h - $yOffset - $bottomReserved;
        if ($totalTitleH < $availH) {
            $yOffset += (int)(($availH - $totalTitleH) / 2);
        }

        foreach ($wrappedTitle as $line) {
            if ($yOffset > $h - $bottomReserved) break;
            $bbox = imagettfbbox($titleSize, 0, $fontBold, $line);
            $lineW = abs($bbox[2] - $bbox[0]);
            $lineX = ($w - $lineW) / 2;
            imagettftext($img, $titleSize, 0, (int)$lineX, $yOffset + $titleSize, $white, $fontBold, $line);
            $yOffset += $titleLineH;
        }

        // --- Decorative accent line ---
        $lineY = $h - $bottomReserved + 20;
        $lineHalfW = 50;
        imageline($img, (int)(($w - $lineHalfW * 2) / 2), $lineY, (int)(($w + $lineHalfW * 2) / 2), $lineY, $accent);

        // --- Bottom branding ---
        $this->drawBranding($img, $w, $h, $padding, $fontBold, $white);

        // --- Save ---
        return $this->saveImage($img, 'smp_exam_' . ($postId ?: uniqid()) . '_' . time());
    }

    /**
     * Generate a visually rich job posting image for Instagram.
     * Features: gradient background (warm orange-red), briefcase icon, bold title, site branding.
     *
     * @param string $title Job title
     * @param int|null $postId Post ID for unique filename
     * @return string|null Public URL of the generated image, or null on failure
     */
    public function generateJobImage(string $title, ?int $postId = null): ?string
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

        // --- Gradient background (warm dark red → deep indigo) ---
        $topR = 136; $topG = 14;  $topB = 79;    // #880e4f
        $botR = 49;  $botG = 27;  $botB = 146;    // #311b92
        for ($y = 0; $y < $h; $y++) {
            $ratio = $y / max($h - 1, 1);
            $r = (int)($topR + ($botR - $topR) * $ratio);
            $g = (int)($topG + ($botG - $topG) * $ratio);
            $b = (int)($topB + ($botB - $topB) * $ratio);
            $lineCol = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w - 1, $y, $lineCol);
        }

        // --- Decorative elements ---
        $circleCol = imagecolorallocatealpha($img, 255, 255, 255, 118);
        imagefilledellipse($img, (int)($w * 0.88), (int)($h * 0.12), 280, 280, $circleCol);
        imagefilledellipse($img, (int)($w * 0.08), (int)($h * 0.85), 200, 200, $circleCol);

        // Accent bar at top
        $accentCol = imagecolorallocate($img, 255, 167, 38); // orange accent
        imagefilledrectangle($img, 0, 0, $w - 1, 8, $accentCol);

        $padding = 80;
        $innerW = $w - 2 * $padding;
        $yOffset = 60;

        // --- Fonts ---
        $fontRegular = $this->fontPath;
        $fontBold = str_replace('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', $this->fontPath);
        if (!file_exists($fontBold)) {
            $fontBold = $fontRegular;
        }

        $white = imagecolorallocate($img, 255, 255, 255);
        $lightGray = imagecolorallocate($img, 220, 200, 220);
        $accent = imagecolorallocate($img, 255, 183, 77); // warm amber

        // --- Briefcase icon ---
        $iconSize = 60;
        $iconText = "\xf0\x9f\x92\xbc"; // briefcase 💼
        // Fallback to a simple text icon if emoji not supported
        $iconBox = @imagettfbbox($iconSize, 0, $fontRegular, $iconText);
        if ($iconBox === false) {
            $iconText = "\xe2\x98\x85"; // star ★
            $iconBox = imagettfbbox($iconSize, 0, $fontRegular, $iconText);
        }
        $iconW = abs($iconBox[2] - $iconBox[0]);
        imagettftext($img, $iconSize, 0, (int)(($w - $iconW) / 2), $yOffset + $iconSize, $accent, $fontRegular, $iconText);
        $yOffset += $iconSize + 30;

        // --- "JOB OPENING" badge ---
        $badgeSize = 20;
        $badgeText = 'JOB OPENING';
        $badgeBox = imagettfbbox($badgeSize, 0, $fontBold, $badgeText);
        $badgeW = abs($badgeBox[2] - $badgeBox[0]);
        $badgeH = abs($badgeBox[7] - $badgeBox[1]);
        $badgePadX = 25;
        $badgePadY = 12;
        $badgeX = (int)(($w - $badgeW - 2 * $badgePadX) / 2);
        $pillCol = imagecolorallocatealpha($img, 255, 167, 38, 40);
        imagefilledrectangle($img, $badgeX, $yOffset, $badgeX + $badgeW + 2 * $badgePadX, $yOffset + $badgeH + 2 * $badgePadY, $pillCol);
        imagettftext($img, $badgeSize, 0, $badgeX + $badgePadX, $yOffset + $badgePadY + $badgeSize, $white, $fontBold, $badgeText);
        $yOffset += $badgeH + 2 * $badgePadY + 40;

        // --- Job title (centered, large, bold, white) ---
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');
        $titleSize = $this->fontSize + 8;
        $titleLen = mb_strlen($title);
        if ($titleLen > 100) {
            $titleSize = max(24, $this->fontSize);
        } elseif ($titleLen > 60) {
            $titleSize = $this->fontSize + 4;
        }

        $wrappedTitle = $this->wrapText($title, $titleSize, $innerW);
        $titleLineH = $titleSize + 14;
        $totalTitleH = count($wrappedTitle) * $titleLineH;

        // Center title vertically
        $bottomReserved = 180;
        $availH = $h - $yOffset - $bottomReserved;
        if ($totalTitleH < $availH) {
            $yOffset += (int)(($availH - $totalTitleH) / 2);
        }

        foreach ($wrappedTitle as $line) {
            if ($yOffset > $h - $bottomReserved) break;
            $bbox = imagettfbbox($titleSize, 0, $fontBold, $line);
            $lineW = abs($bbox[2] - $bbox[0]);
            $lineX = ($w - $lineW) / 2;
            imagettftext($img, $titleSize, 0, (int)$lineX, $yOffset + $titleSize, $white, $fontBold, $line);
            $yOffset += $titleLineH;
        }

        // --- "APPLY NOW" call to action ---
        $ctaY = $h - $bottomReserved + 15;
        $ctaSize = 18;
        $ctaText = 'APPLY NOW';
        $ctaBox = imagettfbbox($ctaSize, 0, $fontBold, $ctaText);
        $ctaW = abs($ctaBox[2] - $ctaBox[0]);
        $ctaH = abs($ctaBox[7] - $ctaBox[1]);
        $ctaPadX = 30;
        $ctaPadY = 10;
        $ctaX = (int)(($w - $ctaW - 2 * $ctaPadX) / 2);
        // CTA button background
        imagefilledrectangle($img, $ctaX, $ctaY, $ctaX + $ctaW + 2 * $ctaPadX, $ctaY + $ctaH + 2 * $ctaPadY, $accentCol);
        $ctaTextCol = imagecolorallocate($img, 50, 20, 60);
        imagettftext($img, $ctaSize, 0, $ctaX + $ctaPadX, $ctaY + $ctaPadY + $ctaSize, $ctaTextCol, $fontBold, $ctaText);

        // --- Bottom branding ---
        $this->drawBranding($img, $w, $h, $padding, $fontBold, $white);

        // --- Save ---
        return $this->saveImage($img, 'smp_job_' . ($postId ?: uniqid()) . '_' . time());
    }

    /**
     * Draw site branding (logo + site name) centered at the bottom of an image.
     */
    private function drawBranding($img, int $w, int $h, int $padding, string $fontBold, $white): void
    {
        $brandY = $h - 90;
        $sepCol = imagecolorallocatealpha($img, 255, 255, 255, 100);
        imageline($img, $padding, $brandY, $w - $padding, $brandY, $sepCol);
        $brandY += 20;

        $siteName = qa_opt('site_title') ?: qa_opt('site_name') ?: '';
        $brandFontSize = 18;
        $brandX = $padding;

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
            if (!empty($siteName)) {
                $siteBox = imagettfbbox($brandFontSize, 0, $fontBold, $siteName);
                $siteNameW = abs($siteBox[2] - $siteBox[0]);
                $brandX = (int)(($w - $siteNameW) / 2);
            }
        }

        if (!empty($siteName)) {
            imagettftext($img, $brandFontSize, 0, $brandX, $brandY + $brandFontSize, $white, $fontBold, $siteName);
        }
    }

    /**
     * Save an image to the smp-images upload directory and return its public URL.
     */
    private function saveImage($img, string $filenameBase): ?string
    {
        $uploadDir = QA_BASE_DIR . 'qa-uploads/smp-images/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = $filenameBase . '.png';
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
