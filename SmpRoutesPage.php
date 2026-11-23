<?php

class SmpRoutesPage
{
    private string $directory;

    public function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    public function match_request($request)
    {
        return $request === 'smp-routes';
    }

    public function process_request($request)
    {
        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $content = qa_content_prepare();
        $content['title'] = 'Social Media Posting Destinations';
        $content['head_lines'][] = '<meta name="robots" content="noindex, nofollow">';
        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            header('HTTP/1.1 403 Forbidden');
            $content['error'] = 'Administrator access is required.';
            return $content;
        }

        $poster = new SmpPoster($this->directory);
        $platforms = SmpConstants::getPlatforms();
        $triggers = [
            SmpConstants::CONTENT_QUESTION => 'When a question is posted',
            SmpConstants::CONTENT_EXAM => 'When an exam is published (batched)',
            SmpConstants::CONTENT_BLOG => 'When a blog is published',
            SmpConstants::CONTENT_JOB => 'When the jobs import adds a job',
            SmpConstants::CONTENT_QOTD => qa_opt(SmpConstants::OPT_QOTD_ENABLED)
                ? 'Daily from ' . (int)(qa_opt(SmpConstants::OPT_QOTD_HOUR) ?: 9) . ':00' : 'Disabled',
            SmpConstants::CONTENT_QUOTE => qa_opt(SmpConstants::OPT_QUOTE_ENABLED)
                ? 'Daily from ' . (int)(qa_opt(SmpConstants::OPT_QUOTE_HOUR) ?: 8) . ':00' : 'Disabled',
            SmpConstants::CONTENT_HISTORY => qa_opt(SmpConstants::OPT_HISTORY_ENABLED)
                ? 'Daily via history cron (approved entries only)' : 'Disabled',
            SmpConstants::CONTENT_GO_MOTIVATION => 'Via scheduled cron',
        ];

        $chatNames = [];
        $rows = '';
        foreach (SmpConstants::getContentTypes() as $type => $label) {
            $accounts = $poster->getAccountsForPosting($type);
            $items = [];
            foreach ($accounts as $account) {
                $platform = $account['_platform'] ?? '';
                $text = qa_html(($platforms[$platform]['name'] ?? $platform) . ': ' . ($account['name'] ?? ''));
                if ($platform === SmpConstants::PLATFORM_TELEGRAM) {
                    $link = $this->telegramLink($account['credentials'] ?? [], $chatNames);
                    if ($link !== '') {
                        $text .= ' (<a href="' . qa_html($link) . '" target="_blank" rel="noopener">' . qa_html(preg_replace('~^https://~', '', $link)) . '</a>)';
                    }
                }
                if (($account['token_expiry_source'] ?? '') === 'invalid') {
                    $text .= ' <strong style="color:#b3261e">token invalid</strong>';
                }
                $items[] = '<li>' . $text . '</li>';
            }
            $rows .= '<tr><td><strong>' . qa_html($label) . '</strong></td>'
                . '<td>' . qa_html($triggers[$type] ?? '') . '</td>'
                . '<td>' . ($items ? '<ul style="margin:0;padding-left:18px">' . implode('', $items) . '</ul>' : '<em>None</em>') . '</td></tr>';
        }

        $routing = $poster->getCategoryRouting();
        $disabled = [];
        foreach (array_keys($platforms) as $platform) {
            foreach ($poster->getAccounts($platform) as $account) {
                if (empty($account['enabled'])) {
                    $disabled[] = qa_html(($platforms[$platform]['name'] ?? $platform) . ': ' . ($account['name'] ?? ''));
                }
            }
        }

        $content['custom'] = '<p>Live routing for <strong>' . qa_html(qa_opt('site_title')) . '</strong>. Accounts are managed in '
            . '<a href="' . qa_path_html('admin/plugins') . '">plugin settings</a>.</p>'
            . '<table class="qa-form-tall-table" style="width:100%;border-collapse:collapse">'
            . '<tr><th style="text-align:left">Content</th><th style="text-align:left">Trigger</th><th style="text-align:left">Destinations</th></tr>'
            . $rows . '</table>'
            . '<p>Category overrides: ' . count($routing) . '</p>'
            . '<p>Disabled accounts: ' . ($disabled ? implode(', ', $disabled) : 'none') . '</p>';
        return $content;
    }

    private function telegramLink(array $creds, array &$chatNames): string
    {
        $chatId = (string)($creds['chat_id'] ?? '');
        if ($chatId === '' || empty($creds['bot_token'])) {
            return '';
        }
        if (!array_key_exists($chatId, $chatNames)) {
            $chatNames[$chatId] = '';
            $ch = curl_init('https://api.telegram.org/bot' . urlencode($creds['bot_token']) . '/getChat');
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chatId]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5,
            ]);
            $data = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            $chatNames[$chatId] = (string)($data['result']['username'] ?? '');
        }
        if ($chatNames[$chatId] === '') {
            return '';
        }
        $thread = (string)($creds['message_thread_id'] ?? '');
        return 'https://t.me/' . $chatNames[$chatId] . ($thread !== '' ? '/' . rawurlencode($thread) : '');
    }
}
