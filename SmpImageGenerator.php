<?php

/**
 * Generates images from text for Instagram posts.
 * Uses GD library to render question text onto an image.
 */
class SmpImageGenerator
{
    // Regex pattern to match a balanced {…} group (up to 3 levels of nesting)
    private const BRACE_PATTERN = '\\{(?:[^{}]|\\{(?:[^{}]|\\{[^{}]*\\})*\\})*\\}';

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
     * @param string $text The question text (may contain HTML and MathJax/LaTeX)
     * @param string $title Optional title to display prominently
     * @param int|null $postId Optional post ID for unique filename
     * @return string|null Public URL of the generated image, or null on failure
     */
    public function generateFromText(string $text, string $title = '', ?int $postId = null): ?string
    {
        // Extract options and question body as HTML (preserving MathJax)
        $questionHtml = '';
        $optionsHtml = [];
        $this->parseQuestionHtmlRaw($text, $questionHtml, $optionsHtml);

        $titleClean = htmlspecialchars(html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');

        $w = $this->width;
        $h = $this->height;

        $siteName = htmlspecialchars(qa_opt('site_title') ?: qa_opt('site_name') ?: '', ENT_QUOTES, 'UTF-8');
        $siteHost = htmlspecialchars(parse_url(qa_opt('site_url') ?: '', PHP_URL_HOST) ?: '', ENT_QUOTES, 'UTF-8');

        // Build options HTML
        $optionsDivs = '';
        foreach ($optionsHtml as $opt) {
            $label = htmlspecialchars($opt['label'], ENT_QUOTES, 'UTF-8');
            $optionsDivs .= '<div class="option"><div class="option-label">' . $label
                . '</div><div class="option-text">' . $opt['html'] . '</div></div>' . "\n";
        }

        // Build the full HTML page
        $html = $this->buildQotdHtml($questionHtml, $optionsDivs, $siteName, $siteHost, $w, $h);

        // Pre-render math server-side via Node.js KaTeX
        $html = $this->renderMathServerSide($html);

        // Write to temp file
        $tempHtml = tempnam(sys_get_temp_dir(), 'smp_qotd_') . '.html';
        $tempPng = tempnam(sys_get_temp_dir(), 'smp_qotd_') . '.png';
        file_put_contents($tempHtml, $html);

        // Run wkhtmltoimage (no JS needed — math is pre-rendered)
        $cmd = sprintf(
            'wkhtmltoimage --disable-javascript --width %d --height %d --quality 95 --disable-smart-width --quiet %s %s 2>&1',
            $w, $h,
            escapeshellarg($tempHtml),
            escapeshellarg($tempPng)
        );

        exec($cmd, $output, $exitCode);
        @unlink($tempHtml);

        if ($exitCode !== 0 || !file_exists($tempPng)) {
            @unlink($tempPng);
            return $this->generateFromTextGd($text, $title, $postId);
        }

        $img = imagecreatefrompng($tempPng);
        @unlink($tempPng);

        if (!$img) {
            return $this->generateFromTextGd($text, $title, $postId);
        }

        return $this->saveImage($img, 'smp_qotd_' . ($postId ?: uniqid()) . '_' . time());
    }

    /**
     * Pre-render $...$ and $$...$$ math to HTML using Node.js KaTeX.
     */
    private function renderMathServerSide(string $html): string
    {
        $script = __DIR__ . '/katex-render.js';
        if (!file_exists($script)) {
            return $html;
        }

        $proc = proc_open(
            ['node', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );

        if (!is_resource($proc)) {
            return $html;
        }

        fwrite($pipes[0], $html);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($proc);
        return ($exitCode === 0 && !empty($output)) ? $output : $html;
    }

    /**
     * Build the HTML page for QOTD image rendering.
     */
    private function buildQotdHtml(string $questionHtml, string $optionsDivs, string $siteName, string $siteHost, int $w, int $h): string
    {
        // Use local KaTeX CSS file (fonts load relative to it)
        $katexCssPath = __DIR__ . '/node_modules/katex/dist/katex.min.css';
        $katexCssUrl = file_exists($katexCssPath) ? 'file://' . $katexCssPath : 'https://cdn.jsdelivr.net/npm/katex@0.16.9/dist/katex.min.css';

        return '<!DOCTYPE html>
<html><head><meta charset="utf-8">
<link rel="stylesheet" href="' . $katexCssUrl . '">
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{width:' . $w . 'px;height:' . $h . 'px;background:linear-gradient(180deg,#0f172a 0%,#1e3a5f 100%);font-family:"Segoe UI","DejaVu Sans",Arial,sans-serif;color:#e8eaed;position:relative;overflow:hidden}
.accent-bar{position:absolute;top:0;left:0;right:0;height:6px;background:#3B82F6}
.circle1{position:absolute;top:-20px;right:-20px;width:200px;height:200px;border-radius:50%;background:rgba(255,255,255,0.05)}
.circle2{position:absolute;bottom:-20px;left:-20px;width:150px;height:150px;border-radius:50%;background:rgba(255,255,255,0.05)}
.content{padding:50px 60px 100px;position:relative;z-index:1}
.badge{text-align:center;margin-bottom:28px}
.badge span{display:inline-block;background:rgba(59,130,246,0.35);color:#fff;font-size:18px;font-weight:700;letter-spacing:2.5px;padding:10px 28px;border-radius:20px}
.question-card{background:rgba(255,255,255,0.08);border-radius:16px;padding:32px 36px;border-left:5px solid #3B82F6;margin-bottom:24px}
.question-card p,.question-card{font-size:28px;line-height:1.55;color:#e8eaed}
.question-card ol,.question-card ul{margin:12px 0 12px 28px;font-size:26px;line-height:1.5;color:#dadce0}
.question-card ol li,.question-card ul li{margin-bottom:6px}
.question-card pre,.question-card code{font-family:"DejaVu Sans Mono","Courier New",monospace;background:rgba(255,255,255,0.06);border-radius:8px;padding:2px 8px;font-size:24px;color:#93c5fd}
.question-card pre{display:block;padding:14px 18px;margin:12px 0;overflow-x:hidden;white-space:pre-wrap}
.question-card table{border-collapse:collapse;margin:12px 0;font-size:24px}
.question-card td,.question-card th{border:1px solid rgba(255,255,255,0.15);padding:8px 14px}
.question-card img{max-width:100%;border-radius:8px}
.options{margin:0}
.option{background:rgba(255,255,255,0.06);border-radius:14px;padding:18px 24px;margin-bottom:14px;display:table;width:100%}
.option-label{display:table-cell;width:44px;height:44px;min-width:44px;border-radius:50%;background:#3B82F6;text-align:center;vertical-align:middle;font-weight:700;font-size:20px;color:#fff}
.option-text{display:table-cell;vertical-align:middle;padding-left:20px;font-size:24px;line-height:1.45;color:#dadce0}
.branding{position:absolute;bottom:30px;left:60px;right:60px;border-top:1px solid rgba(255,255,255,0.15);padding-top:15px}
.branding .site{font-size:20px;font-weight:700;color:rgba(255,255,255,0.7);float:left}
.branding .url{font-size:15px;color:rgba(255,255,255,0.4);float:right;line-height:28px}
.katex{font-size:1.1em!important;color:#e8eaed}
.katex-display{margin:0.3em 0!important}
.katex .mfrac .frac-line{border-bottom-color:#e8eaed!important}
</style></head><body>
<div class="accent-bar"></div>
<div class="circle1"></div>
<div class="circle2"></div>
<div class="content">
<div class="badge"><span>QUESTION OF THE DAY</span></div>
<div class="question-card">' . $questionHtml . '</div>
<div class="options">' . $optionsDivs . '</div>
</div>
<div class="branding">
<span class="site">' . $siteName . '</span>
<span class="url">' . $siteHost . '</span>
</div>
</body></html>';
    }

    /**
     * Parse question HTML keeping raw HTML (for wkhtmltoimage rendering).
     * Extracts options from <ol><li> as structured data with HTML preserved.
     */
    private function parseQuestionHtmlRaw(string $html, string &$questionHtml, array &$options): void
    {
        $options = [];

        // Find ALL <ol>...</ol> blocks
        if (preg_match_all('/<ol\b[^>]*>.*?<\/ol>/is', $html, $allOlMatches, PREG_OFFSET_CAPTURE)) {
            // Only the LAST <ol> is treated as answer options
            $lastOl = end($allOlMatches[0]);
            $lastOlHtml = $lastOl[0];
            $lastOlOffset = $lastOl[1];

            $style = 'upper-alpha';
            if (preg_match('/list-style-type:\s*([a-z-]+)/i', $lastOlHtml, $sm)) {
                $style = strtolower(trim($sm[1], "; \t"));
            }

            preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $lastOlHtml, $liMatches);
            if (!empty($liMatches[1])) {
                foreach ($liMatches[1] as $i => $liContent) {
                    $label = $this->getOptionLabel($style, $i);
                    $options[] = ['label' => $label, 'html' => trim($liContent)];
                }
            }

            // Remove only the last <ol> from the body; keep all others
            $questionHtml = substr($html, 0, $lastOlOffset)
                . substr($html, $lastOlOffset + strlen($lastOlHtml));
        } else {
            $questionHtml = $html;
        }

        $questionHtml = trim($questionHtml);
    }

    /**
     * Fallback: generate QOTD image using GD when wkhtmltoimage is unavailable.
     */
    private function generateFromTextGd(string $text, string $title = '', ?int $postId = null): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }

        $text = $this->convertMathJaxToUnicode($text);
        $title = $this->convertMathJaxToUnicode($title);

        $questionBody = '';
        $options = [];
        $this->parseQuestionHtml($text, $questionBody, $options);
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES, 'UTF-8');

        $w = $this->width;
        $h = $this->height;

        $img = imagecreatetruecolor($w, $h);
        if (!$img) return null;
        imagesavealpha($img, true);

        $topR = 15; $topG = 23; $topB = 42;
        $botR = 30; $botG = 58; $botB = 95;
        for ($y = 0; $y < $h; $y++) {
            $ratio = $y / max($h - 1, 1);
            $r = (int)($topR + ($botR - $topR) * $ratio);
            $g = (int)($topG + ($botG - $topG) * $ratio);
            $b = (int)($topB + ($botB - $topB) * $ratio);
            $lineCol = imagecolorallocate($img, $r, $g, $b);
            imageline($img, 0, $y, $w - 1, $y, $lineCol);
        }

        $circleCol = imagecolorallocatealpha($img, 255, 255, 255, 118);
        imagefilledellipse($img, (int)($w * 0.90), (int)($h * 0.08), 200, 200, $circleCol);
        imagefilledellipse($img, (int)($w * 0.08), (int)($h * 0.92), 150, 150, $circleCol);

        $primaryCol = imagecolorallocate($img, 59, 130, 246);
        imagefilledrectangle($img, 0, 0, $w - 1, 6, $primaryCol);

        $padding = 60;
        $innerW = $w - 2 * $padding;
        $yOffset = 40;

        $fontRegular = $this->fontPath;
        $fontBold = str_replace('DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', $this->fontPath);
        if (!file_exists($fontBold)) $fontBold = $fontRegular;
        $white = imagecolorallocate($img, 255, 255, 255);

        // Badge
        $badgeText = 'QUESTION OF THE DAY';
        $badgeSize = 14;
        $badgeBox = imagettfbbox($badgeSize, 0, $fontBold, $badgeText);
        $badgeW = abs($badgeBox[2] - $badgeBox[0]);
        $badgeH = abs($badgeBox[7] - $badgeBox[1]);
        $badgePadX = 20; $badgePadY = 8;
        $badgeX = (int)(($w - $badgeW - 2 * $badgePadX) / 2);
        $pillBg = imagecolorallocatealpha($img, 59, 130, 246, 60);
        $this->drawRoundedRect($img, $badgeX, (int)$yOffset, $badgeX + $badgeW + 2 * $badgePadX, (int)($yOffset + $badgeH + 2 * $badgePadY), 14, $pillBg);
        imagettftext($img, $badgeSize, 0, $badgeX + $badgePadX, (int)($yOffset + $badgePadY + $badgeSize), $white, $fontBold, $badgeText);
        $yOffset += $badgeH + 2 * $badgePadY + 25;

        // Question card
        $cardBg = imagecolorallocatealpha($img, 255, 255, 255, 108);
        $cardPadding = 28;
        $qFontSize = 22;
        $qLen = mb_strlen($questionBody);
        if ($qLen > 300) $qFontSize = 18;
        elseif ($qLen > 200) $qFontSize = 20;
        $qInnerW = $innerW - 2 * $cardPadding;
        $wrappedQ = $this->wrapText($questionBody, $qFontSize, $qInnerW);
        $qLineH = $qFontSize + 10;
        $maxQLines = empty($options) ? 18 : (count($options) <= 4 ? 7 : 5);
        if (count($wrappedQ) > $maxQLines) {
            $wrappedQ = array_slice($wrappedQ, 0, $maxQLines);
            $wrappedQ[$maxQLines - 1] .= '...';
        }
        $qCardH = count($wrappedQ) * $qLineH + 2 * $cardPadding;
        $this->drawRoundedRect($img, $padding, (int)$yOffset, $padding + $innerW, (int)($yOffset + $qCardH), 16, $cardBg);
        $accentBar = imagecolorallocate($img, 66, 133, 244);
        $this->drawRoundedRect($img, $padding, (int)$yOffset, $padding + 4, (int)($yOffset + $qCardH), 2, $accentBar);
        $qTextCol = imagecolorallocate($img, 232, 234, 237);
        $qTxtY = $yOffset + $cardPadding;
        foreach ($wrappedQ as $line) {
            imagettftext($img, $qFontSize, 0, $padding + $cardPadding + 8, (int)($qTxtY + $qFontSize), $qTextCol, $fontRegular, $line);
            $qTxtY += $qLineH;
        }
        $yOffset += $qCardH + 20;

        // Options
        if (!empty($options)) {
            $optFontSize = 18; $optLineH = $optFontSize + 8; $optPadY = 16;
            $circleD = 38; $optGap = 12; $optTextXOffset = $circleD + 24; $labelSize = 15;
            $maxOptLen = 0;
            foreach ($options as $opt) $maxOptLen = max($maxOptLen, mb_strlen($opt['text']));
            if ($maxOptLen > 80 || count($options) > 4) { $optFontSize = 16; $optLineH = $optFontSize + 8; }
            $optInnerW = $innerW - $optTextXOffset - 24;
            $optSurfaceBg = imagecolorallocatealpha($img, 255, 255, 255, 112);
            $optTextCol = imagecolorallocate($img, 220, 230, 245);
            $circleBg = imagecolorallocate($img, 59, 130, 246);
            foreach ($options as $opt) {
                $wrappedOpt = $this->wrapText($opt['text'], $optFontSize, $optInnerW);
                $boxH = max($circleD + 8, count($wrappedOpt) * $optLineH + 2 * $optPadY);
                if ($yOffset + $boxH + $optGap > $h - 80) break;
                $this->drawRoundedRect($img, $padding, (int)$yOffset, $padding + $innerW, (int)($yOffset + $boxH), 12, $optSurfaceBg);
                $circleCX = $padding + 16 + (int)($circleD / 2);
                $circleCY = (int)($yOffset + $boxH / 2);
                imagefilledellipse($img, $circleCX, $circleCY, $circleD, $circleD, $circleBg);
                $lblBox = imagettfbbox($labelSize, 0, $fontBold, $opt['label']);
                $lblTxtW = abs($lblBox[2] - $lblBox[0]); $lblTxtH = abs($lblBox[7] - $lblBox[1]);
                imagettftext($img, $labelSize, 0, $circleCX - (int)($lblTxtW / 2), $circleCY + (int)($lblTxtH / 2), $white, $fontBold, $opt['label']);
                $optTxtX = $padding + $optTextXOffset; $optTxtY = $yOffset + $optPadY;
                foreach ($wrappedOpt as $optLine) {
                    imagettftext($img, $optFontSize, 0, $optTxtX, (int)($optTxtY + $optFontSize), $optTextCol, $fontRegular, $optLine);
                    $optTxtY += $optLineH;
                }
                $yOffset += $boxH + $optGap;
            }
        }

        $this->drawBranding($img, $w, $h, $padding, $fontBold, $white);
        return $this->saveImage($img, 'smp_qotd_' . ($postId ?: uniqid()) . '_' . time());
    }

    /**
     * Draw a filled rounded rectangle using GD.
     */
    private function drawRoundedRect($img, int $x1, int $y1, int $x2, int $y2, int $radius, $color): void
    {
        $radius = min($radius, (int)(($x2 - $x1) / 2), (int)(($y2 - $y1) / 2));
        if ($radius < 1) {
            imagefilledrectangle($img, $x1, $y1, $x2, $y2, $color);
            return;
        }
        // Center rectangles
        imagefilledrectangle($img, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        // Four corner circles
        imagefilledellipse($img, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($img, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    /**
     * Parse question HTML into question body text and structured options.
     */
    private function parseQuestionHtml(string $html, string &$questionBody, array &$options): void
    {
        $options = [];

        // Extract options from <ol><li> before stripping
        $htmlWithoutOl = preg_replace_callback('/<ol\b[^>]*>(.*?)<\/ol>/is', function ($olMatch) use (&$options) {
            $olTag = $olMatch[0];
            $style = 'upper-alpha';
            if (preg_match('/list-style-type:\s*([a-z-]+)/i', $olTag, $sm)) {
                $style = strtolower(trim($sm[1], "; \t"));
            }

            preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $olMatch[1], $liMatches);
            if (!empty($liMatches[1])) {
                foreach ($liMatches[1] as $i => $liContent) {
                    $label = $this->getOptionLabel($style, $i);
                    // Convert MathJax inside option, then strip tags
                    $optText = $this->convertMathJaxToUnicode($liContent);
                    $optText = trim(html_entity_decode(strip_tags($optText), ENT_QUOTES, 'UTF-8'));
                    $options[] = ['label' => $label, 'text' => $optText];
                }
            }
            return ''; // remove <ol> from body
        }, $html);

        // Question body = everything except the options
        $questionBody = html_entity_decode(strip_tags($htmlWithoutOl), ENT_QUOTES, 'UTF-8');
        $questionBody = preg_replace('/\n{2,}/', "\n", trim($questionBody));
        // Remove any trailing whitespace/newlines
        $questionBody = trim($questionBody);
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
     * Convert HTML ordered lists (<ol><li>) to text with A), B), C) labels.
     * Handles list-style-type: upper-alpha, lower-alpha, decimal, roman.
     */
    private function convertHtmlOptionsToText(string $html): string
    {
        // Match <ol ...> ... </ol> blocks
        return preg_replace_callback('/<ol\b[^>]*>(.*?)<\/ol>/is', function ($olMatch) {
            $olTag = $olMatch[0];
            $olContent = $olMatch[1];

            // Determine starting style
            $style = 'upper-alpha'; // default
            if (preg_match('/list-style-type:\s*([a-z-]+)/i', $olTag, $sm)) {
                $style = strtolower(trim($sm[1], "; \t"));
            }

            // Extract <li> items
            preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $olContent, $liMatches);
            if (empty($liMatches[1])) {
                return $olMatch[0]; // no list items found, return as-is
            }

            $result = "\n";
            foreach ($liMatches[1] as $i => $liContent) {
                $label = $this->getOptionLabel($style, $i);
                $liText = trim($liContent);
                $result .= $label . ') ' . $liText . "\n";
            }
            return $result;
        }, $html);
    }

    /**
     * Get the option label for a given index based on list style.
     */
    private function getOptionLabel(string $style, int $index): string
    {
        switch ($style) {
            case 'upper-alpha':
            case 'upper-latin':
                return chr(65 + $index); // A, B, C, D
            case 'lower-alpha':
            case 'lower-latin':
                return chr(97 + $index); // a, b, c, d
            case 'upper-roman':
                $romans = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X'];
                return $romans[$index] ?? (string)($index + 1);
            case 'lower-roman':
                $romans = ['i', 'ii', 'iii', 'iv', 'v', 'vi', 'vii', 'viii', 'ix', 'x'];
                return $romans[$index] ?? (string)($index + 1);
            case 'decimal':
                return (string)($index + 1);
            default:
                return chr(65 + $index); // default to A, B, C, D
        }
    }

    /**
     * Convert MathJax/LaTeX notation to Unicode approximation for image rendering.
     * Handles $...$ inline math and common LaTeX commands.
     */
    private function convertMathJaxToUnicode(string $text): string
    {
        // Process display math $$...$$ first
        $text = preg_replace_callback('/\$\$(.+?)\$\$/s', function ($m) {
            return $this->latexToUnicode($m[1]);
        }, $text);

        // Process inline math: $...$ (but not $$...$$)
        $text = preg_replace_callback('/(?<!\$)\$(?!\$)(.+?)(?<!\$)\$(?!\$)/s', function ($m) {
            return $this->latexToUnicode($m[1]);
        }, $text);

        // Process \(...\) inline math
        $text = preg_replace_callback('/\\\\\((.+?)\\\\\)/s', function ($m) {
            return $this->latexToUnicode($m[1]);
        }, $text);

        return $text;
    }

    /**
     * Convert a LaTeX math expression to a Unicode approximation.
     */
    private function latexToUnicode(string $latex): string
    {
        $s = trim($latex);

        // Handle \begin{...}...\end{...} environments
        $s = preg_replace_callback('/\\\\begin\{([^{}]+)\}(.*?)\\\\end\{\1\}/s', function ($m) {
            $env = $m[1];
            $body = $m[2];
            if (in_array($env, ['pmatrix', 'bmatrix', 'vmatrix', 'matrix', 'Bmatrix', 'Vmatrix'])) {
                $rows = preg_split('/\\\\\\\\/', $body);
                $rowTexts = [];
                foreach ($rows as $row) {
                    $cells = array_map('trim', explode('&', trim($row)));
                    $rowTexts[] = implode(' ', array_map(function($c) { return $this->latexToUnicode($c); }, $cells));
                }
                $bracket = ($env === 'pmatrix') ? ['(', ')'] : (($env === 'bmatrix') ? ['[', ']'] : ['|', '|']);
                return $bracket[0] . implode('; ', $rowTexts) . $bracket[1];
            }
            if ($env === 'cases') {
                $rows = preg_split('/\\\\\\\\/', $body);
                $parts = [];
                foreach ($rows as $row) {
                    $cells = array_map('trim', explode('&', trim($row)));
                    $parts[] = implode(' ', array_map(function($c) { return $this->latexToUnicode($c); }, $cells));
                }
                return '{ ' . implode(', ' , $parts) . ' }';
            }
            return $this->latexToUnicode($body);
        }, $s);

        // Fractions: \frac{...}{...} — supports nested braces
        $braceRe = self::BRACE_PATTERN;
        $s = preg_replace_callback('/\\\\frac\s*(' . $braceRe . ')\s*(' . $braceRe . ')/', function ($m) {
            $num = $this->latexToUnicode(substr($m[1], 1, -1));
            $den = $this->latexToUnicode(substr($m[2], 1, -1));
            return '(' . $num . '/' . $den . ')';
        }, $s);

        // Binomial: \binom{...}{...} — supports nested braces
        $s = preg_replace_callback('/\\\\binom\s*(' . $braceRe . ')\s*(' . $braceRe . ')/', function ($m) {
            return 'C(' . $this->latexToUnicode(substr($m[1], 1, -1)) . ',' . $this->latexToUnicode(substr($m[2], 1, -1)) . ')';
        }, $s);

        // Superscript: x^{n} or x^n → xⁿ (common cases)
        $superMap = ['0'=>'⁰','1'=>'¹','2'=>'²','3'=>'³','4'=>'⁴','5'=>'⁵','6'=>'⁶','7'=>'⁷','8'=>'⁸','9'=>'⁹',
            'n'=>'ⁿ','i'=>'ⁱ','+'=>'⁺','-'=>'⁻','('=>'⁽',')'=>'⁾','*'=>'*'];
        $s = preg_replace_callback('/\^\{([^{}]+)\}/', function ($m) use ($superMap) {
            $exp = $m[1];
            // If it's a simple expression, use Unicode superscripts
            $result = '';
            $chars = preg_split('//u', $exp, -1, PREG_SPLIT_NO_EMPTY);
            $allMapped = true;
            foreach ($chars as $c) {
                if (isset($superMap[$c])) {
                    $result .= $superMap[$c];
                } else {
                    $allMapped = false;
                    break;
                }
            }
            return $allMapped ? $result : '^(' . $exp . ')';
        }, $s);
        // Single char superscript: x^2 → x²
        $s = preg_replace_callback('/\^([0-9n])/', function ($m) use ($superMap) {
            return $superMap[$m[1]] ?? '^' . $m[1];
        }, $s);

        // Subscript: x_{n} or x_n → x_n (keep as-is, Unicode subscripts limited)
        $subMap = ['0'=>'₀','1'=>'₁','2'=>'₂','3'=>'₃','4'=>'₄','5'=>'₅','6'=>'₆','7'=>'₇','8'=>'₈','9'=>'₉',
            'a'=>'ₐ','e'=>'ₑ','i'=>'ᵢ','n'=>'ₙ','o'=>'ₒ','r'=>'ᵣ','s'=>'ₛ','t'=>'ₜ','u'=>'ᵤ','x'=>'ₓ',
            '+'=>'₊','-'=>'₋','('=>'₍',')'=>'₎'];
        $s = preg_replace_callback('/_\{([^{}]+)\}/', function ($m) use ($subMap) {
            $sub = $m[1];
            $result = '';
            $chars = preg_split('//u', $sub, -1, PREG_SPLIT_NO_EMPTY);
            $allMapped = true;
            foreach ($chars as $c) {
                if (isset($subMap[$c])) {
                    $result .= $subMap[$c];
                } else {
                    $allMapped = false;
                    break;
                }
            }
            return $allMapped ? $result : '_(' . $sub . ')';
        }, $s);
        $s = preg_replace_callback('/_([0-9])/', function ($m) use ($subMap) {
            return $subMap[$m[1]] ?? '_' . $m[1];
        }, $s);

        // Square root: \sqrt{x} → √(x), \sqrt[n]{x} → ⁿ√(x)
        $s = preg_replace_callback('/\\\\sqrt\[([^\]]+)\]\{([^{}]+)\}/', function ($m) {
            return $this->latexToUnicode($m[1]) . '√(' . $this->latexToUnicode($m[2]) . ')';
        }, $s);
        $s = preg_replace('/\\\\sqrt\{([^{}]+)\}/', '√($1)', $s);

        // Greek letters
        $greek = [
            '\\alpha'=>'α', '\\beta'=>'β', '\\gamma'=>'γ', '\\delta'=>'δ', '\\epsilon'=>'ε',
            '\\zeta'=>'ζ', '\\eta'=>'η', '\\theta'=>'θ', '\\iota'=>'ι', '\\kappa'=>'κ',
            '\\lambda'=>'λ', '\\mu'=>'μ', '\\nu'=>'ν', '\\xi'=>'ξ', '\\pi'=>'π',
            '\\rho'=>'ρ', '\\sigma'=>'σ', '\\tau'=>'τ', '\\upsilon'=>'υ', '\\phi'=>'φ',
            '\\chi'=>'χ', '\\psi'=>'ψ', '\\omega'=>'ω',
            '\\Gamma'=>'Γ', '\\Delta'=>'Δ', '\\Theta'=>'Θ', '\\Lambda'=>'Λ',
            '\\Xi'=>'Ξ', '\\Pi'=>'Π', '\\Sigma'=>'Σ', '\\Phi'=>'Φ', '\\Psi'=>'Ψ', '\\Omega'=>'Ω',
        ];
        $s = str_replace(array_keys($greek), array_values($greek), $s);

        // Common math symbols (longer commands must come first to avoid prefix conflicts)
        $symbols = [
            '\\leftrightarrow'=>'↔', '\\Leftrightarrow'=>'⇔',
            '\\rightarrow'=>'→', '\\leftarrow'=>'←', '\\Rightarrow'=>'⇒', '\\Leftarrow'=>'⇐',
            '\\subseteq'=>'⊆', '\\supseteq'=>'⊇',
            '\\emptyset'=>'∅',
            '\\infty'=>'∞', '\\partial'=>'∂', '\\nabla'=>'∇',
            '\\forall'=>'∀', '\\exists'=>'∃',
            '\\notin'=>'∉', '\\subset'=>'⊂', '\\supset'=>'⊃',
            '\\approx'=>'≈', '\\equiv'=>'≡', '\\propto'=>'∝',
            '\\times'=>'×', '\\div'=>'÷',
            '\\leq'=>'≤', '\\geq'=>'≥', '\\neq'=>'≠',
            '\\sim'=>'∼', '\\neg'=>'¬', '\\mid'=>'|',
            '\\int'=>'∫', '\\sum'=>'Σ', '\\prod'=>'Π',
            '\\cup'=>'∪', '\\cap'=>'∩',
            '\\cdot'=>'·', '\\ldots'=>'…', '\\cdots'=>'⋯', '\\vdots'=>'⋮',
            '\\lfloor'=>'⌊', '\\rfloor'=>'⌋', '\\lceil'=>'⌈', '\\rceil'=>'⌉',
            '\\land'=>'∧', '\\lor'=>'∨', '\\oplus'=>'⊕', '\\otimes'=>'⊗',
            '\\in'=>'∈',
            '\\pm'=>'±', '\\mp'=>'∓',
            '\\le'=>'≤', '\\ge'=>'≥', '\\ne'=>'≠',
            '\\to'=>'→', '\\gets'=>'←',
        ];
        $s = str_replace(array_keys($symbols), array_values($symbols), $s);

        // \text{...} and \textbf{...} and \mathrm{...} — just extract content (nested braces)
        $s = preg_replace_callback('/\\\\(?:text|textbf|textrm|mathrm|mathbf|mathit|operatorname)(' . self::BRACE_PATTERN . ')/', function ($m) {
            return substr($m[1], 1, -1);
        }, $s);

        // \left and \right — remove
        $s = str_replace(['\\left', '\\right'], '', $s);

        // \{ and \} → { and }
        $s = str_replace(['\\{', '\\}', '\\,', '\\;', '\\:', '\\!', '\\ '], ['{', '}', ' ', ' ', ' ', '', ' '], $s);

        // Remove remaining \command patterns (unknown commands)
        $s = preg_replace('/\\\\[a-zA-Z]+/', '', $s);

        // Clean up extra spaces
        $s = preg_replace('/\s+/', ' ', trim($s));

        return $s;
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

        // Clean up surrounding quotes (plain and Unicode smart quotes)
        // Remove all leading/trailing quote characters — the image adds decorative ones
        $rawQuote = preg_replace('/^[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}]+/u', '', $rawQuote);
        $rawQuote = preg_replace('/[\s"\'\x{201C}\x{201D}\x{2018}\x{2019}\x{00AB}\x{00BB}]+$/u', '', $rawQuote);
        $rawQuote = trim($rawQuote);

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
            $b = (int)($topB + ($botB - $topB) * $ratio);
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
