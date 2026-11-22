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

        if (qa_clicked('smp_manual_post')) {
            if (!qa_check_form_security_code('smp-manual-post', qa_post_text('code'))) {
                $message = 'The form expired. Reload the page and try again.';
            } else {
                $postText = trim(qa_post_text('smp_manual_message'));
                $postTitle = trim(qa_post_text('smp_manual_title'));
                $imageUrl = trim(qa_post_text('smp_manual_image_url'));
                $selected = $_POST['smp_manual_accounts'] ?? [];

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
                        $accountMessage = $platform === SmpConstants::PLATFORM_TELEGRAM
                            ? htmlspecialchars($postText, ENT_NOQUOTES, 'UTF-8')
                            : $postText;
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
                $checks .= '<label style="display:inline-flex;align-items:center;gap:7px;margin:5px 16px 5px 0"><input type="checkbox" name="smp_manual_accounts[]" value="' . $id . '"> ' . $label . '</label>';
            }
            $checks .= '</fieldset>';
        }

        $statusHtml = $message !== ''
            ? '<div style="padding:10px 14px;margin:12px 0;border-radius:5px;background:#f1f3f4">' . $message . '</div>'
            : '';
        $content['custom'] = $statusHtml
            . '<form method="post" action="' . qa_self_html() . '">'
            . '<input type="hidden" name="code" value="' . htmlspecialchars(qa_get_form_security_code('smp-manual-post'), ENT_QUOTES, 'UTF-8') . '">'
            . '<p><label for="smp-manual-title"><strong>Post title</strong></label><br><input id="smp-manual-title" name="smp_manual_title" type="text" maxlength="120" style="width:100%;max-width:760px"></p>'
            . '<p><label for="smp-manual-message"><strong>Message</strong></label><br><textarea id="smp-manual-message" name="smp_manual_message" required maxlength="5000" rows="9" style="width:100%;max-width:760px"></textarea></p>'
            . '<p><label for="smp-manual-image"><strong>Public image URL</strong> (optional)</label><br><input id="smp-manual-image" name="smp_manual_image_url" type="url" placeholder="https://..." style="width:100%;max-width:760px"></p>'
            . '<p><label><input id="smp-manual-select-all" type="checkbox"> Select all enabled accounts</label></p>'
            . $checks
            . '<button type="submit" name="smp_manual_post" value="1" style="padding:8px 16px;cursor:pointer">Post to selected accounts</button>'
            . '</form>'
            . '<script>document.getElementById("smp-manual-select-all")?.addEventListener("change",function(){document.querySelectorAll("input[name^=smp_manual_accounts]").forEach(function(c){c.checked=this.checked;},this)});</script>';
        return $content;
    }
}
