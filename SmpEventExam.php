<?php

/**
 * Event handler for new exams (qa_exam_post_).
 * Posts to enabled social media platforms.
 */
class SmpEventExam
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function process_event($event, $userid, $handle, $cookieid, $params)
    {
        if ($event !== 'qa_exam_post_') {
            return;
        }

        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $poster = new SmpPoster($this->directory);

        // Get auto-posting accounts + manually selected accounts
        $autoAccounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_EXAM);
        $manualAccounts = $poster->getManualShareAccounts(SmpConstants::CONTENT_EXAM);

        // Get user's personal accounts (if configured)
        $userAccounts = [];
        if ($userid) {
            $userAccounts = $poster->getUserAccountsForPosting((int)$userid);
        }

        if (empty($autoAccounts) && empty($manualAccounts) && empty($userAccounts)) {
            return;
        }

        $title = $params['title'] ?? '';
        $postId = $params['postid'] ?? 0;

        // Build URL using exam path helper if available
        if (function_exists('qa_exam_request')) {
            $url = qa_path_absolute(qa_exam_request($postId, $title));
        } else {
            $url = qa_opt('site_url') . 'exam/' . $postId . '/' . urlencode($title);
        }

        // Generate message using OpenAI
        $generatedMessage = $poster->openaiGenerateMessage($title);
        $message = $generatedMessage . "\n\nLink: " . $url;

        // Generate image if Instagram auto-image or YouTube auto-video is enabled
        $imageUrl = null;
        $allAccounts = $autoAccounts + $manualAccounts + $userAccounts;
        $accountPlatforms = array_column($allAccounts, '_platform');
        $needsImage = in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateExamImage($title, $postId);
        }

        // Post to all enabled accounts
        $results = $poster->postToAll(SmpConstants::CONTENT_EXAM, $message, $imageUrl, ['title' => $title, '_manual_accounts' => $manualAccounts + $userAccounts]);

        // Report failures
        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'Exam post failed on ' . $platform . ' (' . $accountName . ')',
                    'Exam ID: ' . $postId
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
