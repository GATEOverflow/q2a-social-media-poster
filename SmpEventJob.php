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
        if (empty($accounts)) {
            return;
        }

        $title = $params['title'] ?? '';
        $postId = $params['postid'] ?? $params['jobid'] ?? 0;

        // Build URL using job path helper if available
        if (function_exists('qa_job_request')) {
            $url = qa_path_absolute(qa_job_request($postId, $title));
        } else {
            $url = qa_opt('site_url') . 'job/' . $postId . '/' . qa_slug($title);
        }

        // Generate message using OpenAI
        $generatedMessage = $poster->openaiGenerateMessage($title, 'job posting');
        $message = $generatedMessage . "\n\nApply here: " . $url;

        // Generate image if Instagram auto-image or YouTube auto-video is enabled
        $imageUrl = null;
        $accountPlatforms = array_column($accounts, '_platform');
        $needsImage = (in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_INSTAGRAM_AUTO_IMAGE))
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateFromText($title, 'New Job Opening', $postId);
        }

        // Post to all enabled accounts
        $results = $poster->postToAll(SmpConstants::CONTENT_JOB, $message, $imageUrl);

        // Log failures
        foreach ($results as $accountId => $result) {
            if (!$result['success']) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                error_log("SMP Job post to $platform ($accountName) failed: " . $result['error']);
            }
        }
    }
}
