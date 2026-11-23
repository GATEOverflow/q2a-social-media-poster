<?php

/**
 * Event handler for new jobs (qa_job_post).
 * Posts to enabled social media platforms.
 */
class SmpEventJob
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function process_event($event, $userid, $handle, $cookieid, $params)
    {
        if ($event !== 'qa_job_post') {
            return;
        }

        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $poster = new SmpPoster($this->directory);

        // Check if any accounts are configured for jobs
        $accounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_JOB);

        // Get user's personal accounts (if configured)
        $userAccounts = [];
        if ($userid) {
            $userAccounts = $poster->getUserAccountsForPosting((int)$userid);
        }

        if (empty($accounts) && empty($userAccounts)) {
            return;
        }

        $title = trim((string)($params['title'] ?? '')) ?: trim((string)($params['institute'] ?? ''));
        $postId = (int)($params['postid'] ?? $params['jobid'] ?? 0);
        $url = trim((string)($params['weblink'] ?? ''));

        if ($url === '' && $postId > 0 && function_exists('qa_job_request')) {
            $url = qa_path_absolute(qa_job_request($postId, $title));
        }
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if ($title === '' || !filter_var($url, FILTER_VALIDATE_URL) || !in_array($scheme, ['http', 'https'], true)) {
            $poster->reportFailure('Job post skipped', 'The job event is missing a title or a valid application URL.');
            return;
        }

        // Generate message using OpenAI
        $details = 'Job posting: ' . $title;
        if (!empty($params['program'])) {
            $details .= "\nProgram: " . $params['program'];
        }
        if (!empty($params['deadline'])) {
            $details .= "\nDeadline: " . $params['deadline'];
        }
        $composed = $poster->composePost(SmpConstants::CONTENT_JOB, $details, $url, 'Apply here');
        $message = $composed['default'];

        // Generate image if Instagram auto-image or YouTube auto-video is enabled
        $imageUrl = null;
        $allAccounts = $accounts + $userAccounts;
        $accountPlatforms = array_column($allAccounts, '_platform');
        $needsImage = in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateJobImage($title, $postId);
        }

        // Post to all enabled accounts
        $results = $poster->postToAll(SmpConstants::CONTENT_JOB, $message, $imageUrl, ['title' => $title, '_manual_accounts' => $userAccounts, 'platform_messages' => $composed['platforms']]);

        // Report failures
        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'Job post failed on ' . $platform . ' (' . $accountName . ')',
                    'Job ID: ' . $postId
                    . "\nTitle: " . $title
                    . "\nImage URL: " . $usedImageUrl
                    . "\nVideo URL: " . $videoUrl
                    . "\nError: " . ($result['error'] ?? 'Unknown')
                    . "\n\n--- Message ---\n" . $message
                );
            }
        }
    }
}
