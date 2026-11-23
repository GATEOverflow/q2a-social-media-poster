<?php

class SmpScheduled
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    public function postMotivation(string $contentType, string $date): array
    {
        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';
        if ($contentType !== SmpConstants::CONTENT_GO_MOTIVATION) {
            throw new InvalidArgumentException('Invalid scheduled content type.');
        }
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed || $parsed->format('Y-m-d') !== $date) {
            throw new InvalidArgumentException('Invalid scheduled date.');
        }

        $poster = new SmpPoster($this->directory);
        $accounts = $poster->getAccountsForPosting($contentType);
        if (!$accounts) {
            return ['status' => 'no_accounts'];
        }
        qa_db_query_sub('INSERT IGNORE INTO ^smp_scheduled_posts (content_type, post_date, content, status) VALUES ($, $, $, $)', $contentType, $date, '', 'generating');
        if (qa_db_affected_rows() !== 1) {
            return ['status' => 'already_attempted'];
        }

        try {
            $text = function_exists('openai_call') ? (string)openai_call($date, 6) : '';
        } catch (Throwable $exception) {
            $text = '';
        }
        if (preg_match('/<<<<(.*?)>>>>/s', $text, $match)) {
            $text = $match[1];
        }
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 5000 || preg_match('/^(Error:|OpenAI API error:|OpenAI:|cURL error:)/i', $text)) {
            qa_db_query_sub('UPDATE ^smp_scheduled_posts SET status=$ WHERE content_type=$ AND post_date=$', 'failed', $contentType, $date);
            $poster->reportFailure('Scheduled motivation generation failed', 'Content type: ' . $contentType . "\nDate: " . $date);
            return ['status' => 'generation_failed'];
        }
        qa_db_query_sub('UPDATE ^smp_scheduled_posts SET content=$, status=$ WHERE content_type=$ AND post_date=$', $text, 'sending', $contentType, $date);

        $results = [];
        foreach ($accounts as $accountId => $account) {
            qa_db_query_sub('INSERT IGNORE INTO ^smp_scheduled_delivery (content_type, post_date, account_id, status) VALUES ($, $, $, $)', $contentType, $date, (string)$accountId, 'sending');
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
            qa_db_query_sub('UPDATE ^smp_scheduled_delivery SET status=$ WHERE content_type=$ AND post_date=$ AND account_id=$', $status, $contentType, $date, (string)$accountId);
            $results[$accountId] = $status;
            if ($status === 'failed') {
                $poster->reportFailure('Scheduled motivation post failed', 'Content type: ' . $contentType . "\nDate: " . $date . "\nAccount: " . ($account['name'] ?? $accountId));
            }
        }
        $finalStatus = in_array('failed', $results, true)
            ? (in_array('sent', $results, true) ? 'partial' : 'failed')
            : 'sent';
        qa_db_query_sub('UPDATE ^smp_scheduled_posts SET status=$ WHERE content_type=$ AND post_date=$', $finalStatus, $contentType, $date);
        return ['status' => $finalStatus, 'results' => $results];
    }
}