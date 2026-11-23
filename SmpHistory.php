<?php

class SmpHistory
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function postApproved(string $date): array
    {
        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';
        if (!qa_opt(SmpConstants::OPT_HISTORY_ENABLED)) {
            return ['status' => 'disabled'];
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid history date.');
        }
        $entry = qa_db_read_one_assoc(qa_db_query_sub('SELECT content, source_url FROM ^smp_history WHERE month_day=$ AND approved=1', $parsed->format('m-d')), true);
        if (!$entry || trim($entry['content']) === '' || trim($entry['source_url']) === '') {
            return ['status' => 'not_approved'];
        }

        $poster = new SmpPoster($this->directory);
        $accounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_HISTORY);
        if (!$accounts) {
            return ['status' => 'no_accounts'];
        }
        $text = trim($entry['content']) . "\n\nSource: " . trim($entry['source_url']);
        if (strtolower((string)parse_url($entry['source_url'], PHP_URL_HOST)) === 'en.wikipedia.org') {
            $text .= "\nWikipedia contributors; CC BY-SA 4.0: https://creativecommons.org/licenses/by-sa/4.0/";
        }
        $results = [];
        foreach ($accounts as $accountId => $account) {
            qa_db_query_sub('INSERT IGNORE INTO ^smp_history_delivery (post_date, account_id, status) VALUES ($, $, $)', $date, (string)$accountId, 'sending');
            if (qa_db_affected_rows() !== 1) {
                $results[$accountId] = 'already_attempted';
                continue;
            }
            $platform = $account['_platform'] ?? '';
            $message = $platform === SmpConstants::PLATFORM_TELEGRAM ? htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
            try {
                $result = $poster->postToPlatform($platform, $account, $message);
                $status = !empty($result['success']) ? 'sent' : 'failed';
            } catch (Throwable $exception) {
                $status = 'failed';
            }
            qa_db_query_sub('UPDATE ^smp_history_delivery SET status=$ WHERE post_date=$ AND account_id=$', $status, $date, (string)$accountId);
            $results[$accountId] = $status;
            if ($status === 'failed') {
                $poster->reportFailure('Daily history post failed', 'Date: ' . $date . "\nAccount: " . ($account['name'] ?? $accountId) . "\nPlatform: " . $platform);
            }
        }
        return ['status' => 'attempted', 'results' => $results];
    }
}