<?php

class SmpManualPost
{
    private string $directory;

    public function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    public function suggest_requests()
    {
        return [[
            'title' => 'Manual Social Media Post',
            'request' => 'smp-manual-post',
            'nav' => null,
        ]];
    }

    public function match_request($request)
    {
        return $request === 'smp-manual-post';
    }

    public function process_request($request)
    {
        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $content = qa_content_prepare();
        $content['title'] = 'Manual Social Media Post';
        $content['head_lines'][] = '<meta name="robots" content="noindex, nofollow">';
        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            header('HTTP/1.1 403 Forbidden');
            $content['error'] = 'Administrator access is required.';
            return $content;
        }

        $poster = new SmpPoster($this->directory);
        $accounts = array_filter($poster->getAllAccountsById(), function ($account) {
            return !empty($account['enabled']);
        });
        $message = '';
        $postTitle = '';
        $imageUrl = '';
        $selected = [];
        $editorContent = '';
        $editorFormat = '';
        $editorName = qa_opt('editor_for_qs');
        $editor = qa_load_editor($editorContent, $editorFormat, $editorName);

        if (qa_clicked('smp_manual_post')) {
            $editorData = $editor->read_post('smp_manual_message');
            $editorContent = $editorData['content'];
            $editorFormat = $editorData['format'];
            $postTitle = trim(qa_post_text('smp_manual_title'));
            $imageUrl = trim(qa_post_text('smp_manual_image_url'));
            $selected = $_POST['smp_manual_accounts'] ?? [];
            if (!qa_check_form_security_code('smp-manual-post', qa_post_text('code'))) {
                $message = 'The form expired. Reload the page and try again.';
            } else {
                $postText = $this->editorText($editorContent, $editorFormat);

                if ($postText === '' || mb_strlen($postText) > 5000) {
                    $message = 'Enter a post message between 1 and 5,000 characters.';
                } elseif ($imageUrl !== '' && (!filter_var($imageUrl, FILTER_VALIDATE_URL) || parse_url($imageUrl, PHP_URL_SCHEME) !== 'https')) {
                    $message = 'The image URL must be a valid HTTPS URL.';
                } elseif (!is_array($selected) || !$selected) {
                    $message = 'Select at least one social media account.';
                } else {
                    $results = [];
                    foreach (array_unique(array_map('strval', $selected)) as $accountId) {
                        if (!isset($accounts[$accountId])) {
                            continue;
                        }
                        $account = $accounts[$accountId];
                        $platform = $account['_platform'] ?? '';
                        $accountMessage = $this->formatEditorMessage($editorContent, $editorFormat, $platform, $postText);
                        try {
                            $result = $poster->postToPlatform(
                                $platform,
                                $account,
                                $accountMessage,
                                $imageUrl !== '' ? $imageUrl : null,
                                ['title' => $postTitle]
                            );
                        } catch (Throwable $e) {
                            $result = ['success' => false, 'error' => $e->getMessage()];
                        }
                        $results[] = [
                            'account' => $account['name'] ?? $accountId,
                            'platform' => SmpConstants::getPlatforms()[$platform]['name'] ?? $platform,
                            'success' => !empty($result['success']),
                            'error' => !empty($result['success']) ? '' : ($result['error'] ?? 'Unknown error'),
                        ];
                    }
                    if (!$results) {
                        $message = 'None of the selected accounts is currently enabled.';
                    } else {
                        qa_db_query_sub(
                            'REPLACE INTO ^usermetas (userid, title, content) VALUES (#, $, $)',
                            qa_get_logged_in_userid(),
                            'smp_manual_post_result',
                            json_encode($results, JSON_UNESCAPED_UNICODE)
                        );
                        qa_redirect('smp-manual-post');
                    }
                }
            }
        } else {
            $flash = qa_db_read_one_value(qa_db_query_sub(
                'SELECT content FROM ^usermetas WHERE userid = # AND title = $',
                qa_get_logged_in_userid(),
                'smp_manual_post_result'
            ), true);
            if ($flash) {
                qa_db_query_sub(
                    'DELETE FROM ^usermetas WHERE userid = # AND title = $',
                    qa_get_logged_in_userid(),
                    'smp_manual_post_result'
                );
                $results = json_decode($flash, true) ?: [];
                $items = '';
                foreach ($results as $result) {
                    $label = htmlspecialchars($result['platform'] . ' / ' . $result['account'], ENT_QUOTES, 'UTF-8');
                    $state = !empty($result['success']) ? 'Posted' : 'Failed: ' . ($result['error'] ?? 'unknown error');
                    $color = !empty($result['success']) ? '#137333' : '#b3261e';
                    $items .= '<li><strong>' . $label . ':</strong> <span style="color:' . $color . '">' . htmlspecialchars($state, ENT_QUOTES, 'UTF-8') . '</span></li>';
                }
                $message = '<strong>Post results</strong><ul>' . $items . '</ul>';
            }
        }

        $checks = '';
        foreach (SmpConstants::getPlatforms() as $platformId => $platform) {
            $platformAccounts = array_filter($accounts, function ($account) use ($platformId) {
                return ($account['_platform'] ?? '') === $platformId;
            });
            if (!$platformAccounts) {
                continue;
            }
            $checks .= '<fieldset style="border:1px solid #dadce0;border-radius:6px;padding:10px 14px;margin:10px 0">';
            $checks .= '<legend style="font-weight:600;padding:0 6px">' . htmlspecialchars($platform['name'], ENT_QUOTES, 'UTF-8') . '</legend>';
            foreach ($platformAccounts as $accountId => $account) {
                $label = htmlspecialchars($account['name'] ?? $accountId, ENT_QUOTES, 'UTF-8');
                $id = htmlspecialchars((string)$accountId, ENT_QUOTES, 'UTF-8');
                $checked = is_array($selected) && in_array((string)$accountId, $selected, true) ? ' checked' : '';
                $checks .= '<label style="display:inline-flex;align-items:center;gap:7px;margin:5px 16px 5px 0"><input type="checkbox" name="smp_manual_accounts[]" value="' . $id . '"' . $checked . '> ' . $label . '</label>';
            }
            $checks .= '</fieldset>';
        }

        $editorField = qa_editor_load_field($editor, $content, $editorContent, $editorFormat, 'smp_manual_message', 9);
        $editorField['label'] = 'Message';
        $updateScript = method_exists($editor, 'update_script') ? $editor->update_script('smp_manual_message') : '';
        $content['form'] = [
            'tags' => 'method="post" action="' . qa_self_html() . '"',
            'style' => 'tall',
            'ok' => $message,
            'fields' => [
                'title' => [
                    'label' => 'Post title',
                    'tags' => 'name="smp_manual_title" maxlength="120"',
                    'value' => qa_html($postTitle),
                ],
                'message' => $editorField,
                'image' => [
                    'label' => 'Public image URL (optional)',
                    'tags' => 'name="smp_manual_image_url" placeholder="https://..."',
                    'value' => qa_html($imageUrl),
                ],
                'accounts' => [
                    'type' => 'custom',
                    'html' => '<label><input id="smp-manual-select-all" type="checkbox"> Select all enabled accounts</label>' . $checks
                        . '<script>document.getElementById("smp-manual-select-all")?.addEventListener("change",function(){document.querySelectorAll("input[name^=smp_manual_accounts]").forEach(function(c){c.checked=this.checked;},this)});</script>',
                ],
            ],
            'buttons' => [
                'post' => [
                    'tags' => 'name="smp_manual_post" onclick="' . qa_html($updateScript) . '"',
                    'label' => 'Post to selected accounts',
                ],
            ],
            'hidden' => ['code' => qa_html(qa_get_form_security_code('smp-manual-post'))],
        ];
        return $content;
    }

    private function editorText(string $editorContent, string $editorFormat): string
    {
        if ($editorFormat === 'html') {
            $document = new DOMDocument();
            $previousErrors = libxml_use_internal_errors(true);
            $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div>' . $editorContent . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            if ($loaded) {
                foreach ($document->getElementsByTagName('a') as $link) {
                    $url = trim($link->getAttribute('href'));
                    $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
                    if (in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) && trim($link->textContent) !== $url) {
                        $link->appendChild($document->createTextNode(' (' . $url . ')'));
                    }
                }
                $editorContent = $document->saveHTML($document->documentElement);
            }
        }
        return trim(qa_viewer_text($editorContent, $editorFormat));
    }

    private function formatEditorMessage(string $editorContent, string $editorFormat, string $platform, string $plainText): string
    {
        $telegram = $platform === SmpConstants::PLATFORM_TELEGRAM;
        if (!$telegram && $platform !== SmpConstants::PLATFORM_WHATSAPP) {
            return $plainText;
        }
        if ($editorFormat !== 'html') {
            return $telegram ? htmlspecialchars($plainText, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $plainText;
        }

        $document = new DOMDocument();
        $previousErrors = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8"><div>' . $editorContent . '</div>', LIBXML_NONET | LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        if (!$loaded || !$document->documentElement) {
            return $telegram ? htmlspecialchars($plainText, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $plainText;
        }

        $message = $this->formatEditorNode($document->documentElement, $telegram);
        return trim(preg_replace('/\n{3,}/', "\n\n", $message));
    }

    private function formatEditorNode(DOMNode $node, bool $telegram): string
    {
        if ($node instanceof DOMText) {
            $text = preg_replace('/[\t\r\n ]+/', ' ', $node->nodeValue);
            return $telegram ? htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
        }
        if (!$node instanceof DOMElement) {
            return '';
        }

        $tag = strtolower($node->tagName);
        if (in_array($tag, ['script', 'style'], true)) {
            return '';
        }
        if ($tag === 'br') {
            return "\n";
        }
        if ($tag === 'pre' || $tag === 'code') {
            $text = $node->textContent;
            if ($telegram) {
                return '<' . $tag . '>' . htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</' . $tag . '>';
            }
            return strpos($text, '```') === false ? '```' . $text . '```' : $text;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= $this->formatEditorNode($child, $telegram);
        }

        if ($tag === 'a') {
            $url = trim($node->getAttribute('href'));
            $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
            if (in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL)) {
                if (trim($text) === '') {
                    return $telegram ? htmlspecialchars($url, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $url;
                }
                if ($node->getElementsByTagName('code')->length || $node->getElementsByTagName('pre')->length) {
                    return $text . ' (' . ($telegram ? htmlspecialchars($url, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $url) . ')';
                }
                if ($telegram) {
                    return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $text . '</a>';
                }
                return trim($node->textContent) === $url ? $text : $text . ' (' . $url . ')';
            }
            return $text;
        }

        $telegramTags = ['b' => 'b', 'strong' => 'b', 'i' => 'i', 'em' => 'i', 'u' => 'u', 's' => 's', 'strike' => 's', 'del' => 's'];
        $whatsappTags = ['b' => '*', 'strong' => '*', 'i' => '_', 'em' => '_', 's' => '~', 'strike' => '~', 'del' => '~'];
        if (trim($text) !== '') {
            if (($telegram && isset($telegramTags[$tag])) || (!$telegram && isset($whatsappTags[$tag]))) {
                if ($node->getElementsByTagName('code')->length || $node->getElementsByTagName('pre')->length) {
                    return $text;
                }
            }
            if ($telegram && isset($telegramTags[$tag])) {
                return '<' . $telegramTags[$tag] . '>' . $text . '</' . $telegramTags[$tag] . '>';
            }
            if (!$telegram && isset($whatsappTags[$tag])) {
                preg_match('/^(\s*)(.*?)(\s*)$/us', $text, $parts);
                return $parts[1] . $whatsappTags[$tag] . $parts[2] . $whatsappTags[$tag] . $parts[3];
            }
        }
        if ($tag === 'li') {
            return '- ' . trim($text) . "\n";
        }
        if (in_array($tag, ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote'], true)) {
            return $text . "\n\n";
        }
        return $text;
    }
}
