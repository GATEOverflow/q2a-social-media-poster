<?php

class SmpHistoryPage
{
    public function load_module($directory, $urltoroot)
    {
    }

    public function match_request($request)
    {
        return $request === 'smp-history';
    }

    public function process_request($request)
    {
        require_once __DIR__ . '/SmpConstants.php';
        $content = qa_content_prepare();
        $content['title'] = 'Daily History Calendar';
        $content['head_lines'][] = '<meta name="robots" content="noindex, nofollow">';
        if (qa_get_logged_in_level() < QA_USER_LEVEL_ADMIN) {
            header('HTTP/1.1 403 Forbidden');
            $content['error'] = 'Administrator access is required.';
            return $content;
        }

        $month = (int)(qa_get('month') ?: date('n'));
        $month = max(1, min(12, $month));
        $message = '';
        $unsaved = null;
        if (qa_clicked('smp_history_settings')) {
            if (!qa_check_form_security_code('smp-history', qa_post_text('code'))) {
                $message = 'The form expired. Reload the page and try again.';
            } else {
                qa_opt(SmpConstants::OPT_HISTORY_ENABLED, qa_post_text('history_enabled') ? '1' : '0');
                qa_redirect('smp-history', ['month' => $month, 'settings_saved' => 1]);
            }
        }
        if (qa_clicked('smp_history_save')) {
            $day = trim((string)qa_post_text('month_day'));
            $text = trim((string)qa_post_text('content'));
            $source = trim((string)qa_post_text('source_url'));
            $approved = (bool)qa_post_text('approved');
            $unsaved = ['month_day' => $day, 'content' => $text, 'source_url' => $source, 'approved' => $approved];
            if (!qa_check_form_security_code('smp-history', qa_post_text('code'))) {
                $message = 'The form expired. Reload the page and try again.';
            } elseif (!preg_match('/^(\d{2})-(\d{2})$/', $day, $parts) || !checkdate((int)$parts[1], (int)$parts[2], 2024)) {
                $message = 'Invalid calendar date.';
            } elseif (mb_strlen($text) > 4000 || ($approved && ($text === '' || $source === ''))) {
                $message = 'Approved entries require text and a source; text must not exceed 4,000 characters.';
            } elseif ($source !== '' && (!filter_var($source, FILTER_VALIDATE_URL) || strtolower((string)parse_url($source, PHP_URL_SCHEME)) !== 'https')) {
                $message = 'The source must be a valid HTTPS URL.';
            } else {
                qa_db_query_sub('INSERT INTO ^smp_history (month_day, content, source_url, approved) VALUES ($, $, $, #) ON DUPLICATE KEY UPDATE content=VALUES(content), source_url=VALUES(source_url), approved=VALUES(approved)', $day, $text, $source, (int)$approved);
                qa_redirect('smp-history', ['month' => (int)$parts[1], 'saved' => $day]);
            }
        }

        $rows = qa_db_read_all_assoc(qa_db_query_sub('SELECT month_day, content, source_url, approved FROM ^smp_history WHERE month_day LIKE $', sprintf('%02d-%%', $month)));
        $entries = [];
        foreach ($rows as $row) {
            $entries[$row['month_day']] = $row;
        }
        if ($unsaved && $message !== '') {
            $entries[$unsaved['month_day']] = $unsaved;
        }
        $deliveries = qa_db_read_all_assoc(qa_db_query_sub('SELECT post_date, account_id, status FROM ^smp_history_delivery WHERE YEAR(post_date)=# AND MONTH(post_date)=# ORDER BY post_date DESC, account_id', (int)date('Y'), $month));
        $statusByDay = [];
        foreach ($deliveries as $delivery) {
            $statusByDay[substr($delivery['post_date'], 5)][] = $delivery['account_id'] . ': ' . $delivery['status'];
        }
        $html = $message !== '' ? '<p class="qa-error">' . qa_html($message) . '</p>' : '';
        if (qa_get('saved')) {
            $html .= '<p>Saved ' . qa_html(qa_get('saved')) . '.</p>';
        }
        if (qa_get('settings_saved')) {
            $html .= '<p>History settings saved.</p>';
        }
        $html .= '<form method="post" action="' . qa_path_html('smp-history', ['month' => $month]) . '">'
            . '<input type="hidden" name="code" value="' . qa_html(qa_get_form_security_code('smp-history')) . '">'
            . '<label><input type="checkbox" name="history_enabled" value="1"' . (qa_opt(SmpConstants::OPT_HISTORY_ENABLED) ? ' checked' : '') . '> Enable daily history posting</label> '
            . '<button type="submit" name="smp_history_settings" value="1">Save settings</button>'
            . ' <a href="' . qa_path_html('admin/plugins') . '">Manage Daily History accounts</a></form>';
        $html .= '<nav aria-label="Month" style="display:flex;flex-wrap:wrap;gap:12px;margin:12px 0">';
        for ($number = 1; $number <= 12; $number++) {
            $label = date('M', mktime(12, 0, 0, $number, 1, 2024));
            $html .= '<a href="' . qa_path_html('smp-history', ['month' => $number]) . '"' . ($number === $month ? ' aria-current="page" style="font-weight:bold"' : '') . '>' . qa_html($label) . '</a>';
        }
        $html .= '</nav><h2>' . qa_html(date('F', mktime(12, 0, 0, $month, 1, 2024))) . '</h2>';
        $days = cal_days_in_month(CAL_GREGORIAN, $month, 2024);
        for ($dayNumber = 1; $dayNumber <= $days; $dayNumber++) {
            $key = sprintf('%02d-%02d', $month, $dayNumber);
            $entry = $entries[$key] ?? ['content' => '', 'source_url' => '', 'approved' => 0];
            $html .= '<form method="post" action="' . qa_path_html('smp-history', ['month' => $month]) . '" style="border-bottom:1px solid #ccc;padding:12px 0">'
                . '<input type="hidden" name="code" value="' . qa_html(qa_get_form_security_code('smp-history')) . '">'
                . '<input type="hidden" name="month_day" value="' . $key . '">'
                . '<label for="history-' . $key . '"><strong>' . qa_html(date('F j', mktime(12, 0, 0, $month, $dayNumber, 2024))) . '</strong></label>'
                . ' <span>' . (!empty($entry['approved']) ? 'Approved' : ($entry['content'] !== '' ? 'Draft' : 'Empty')) . '</span>'
                . (isset($statusByDay[$key]) ? ' <span>' . qa_html(implode(', ', $statusByDay[$key])) . '</span>' : '')
                . '<textarea id="history-' . $key . '" name="content" rows="3" maxlength="4000" style="display:block;width:100%;margin:6px 0">' . qa_html($entry['content']) . '</textarea>'
                . '<label>Source URL <input name="source_url" type="url" value="' . qa_html($entry['source_url']) . '" style="width:min(100%,600px)"></label> '
                . '<label><input type="checkbox" name="approved" value="1"' . (!empty($entry['approved']) ? ' checked' : '') . '> Approved</label> '
                . '<button type="submit" name="smp_history_save" value="1">Save</button></form>';
        }
        $content['custom'] = $html;
        return $content;
    }
}