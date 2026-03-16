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
