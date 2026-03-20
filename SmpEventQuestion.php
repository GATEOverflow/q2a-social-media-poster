<?php

/**
 * Event handler for new questions (q_post).
 * Posts to enabled social media platforms.
 */
class SmpEventQuestion
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function process_event($event, $userid, $handle, $cookieid, $params)
    {
        if ($event !== 'q_post') {
            return;
        }

        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $poster = new SmpPoster($this->directory);

        // Get category for routing
        $categoryId = $params['categoryid'] ?? null;
        
        // Get auto-posting accounts + manually selected accounts
        $autoAccounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_QUESTION, $categoryId);
        $manualAccounts = $poster->getManualShareAccounts(SmpConstants::CONTENT_QUESTION, $categoryId);

        // Get user's personal accounts (if configured)
        $userAccounts = [];
        if ($userid) {
            $userAccounts = $poster->getUserAccountsForPosting((int)$userid);
        }

        if (empty($autoAccounts) && empty($manualAccounts) && empty($userAccounts)) {
            return;
        }

        require_once QA_INCLUDE_DIR . 'app/format.php';

        $title = $params['title'] ?? '';
        $content = $params['content'] ?? '';
        $postId = $params['postid'] ?? 0;
        $url = qa_q_path($postId, $title, true);

        // Generate message using OpenAI if available
        $rawContent = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        $messageInput = $title . "\n" . mb_substr($rawContent, 0, 500);
        $generatedMessage = $poster->openaiGenerateMessage($messageInput);

        // Build final message with URL
        $message = $generatedMessage . "\n\n" . $url;

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
            $imageUrl = $imageGen->generateFromText($content, $title, $postId);
        }

        // Post to all enabled accounts (with category routing + user accounts)
        $extra = ['categoryid' => $categoryId, 'title' => $title, '_manual_accounts' => $manualAccounts + $userAccounts];
        $results = $poster->postToAll(SmpConstants::CONTENT_QUESTION, $message, $imageUrl, $extra);

        // Report any failures
        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'Question post failed on ' . $platform . ' (' . $accountName . ')',
                    'Post ID: ' . $postId
                    . "\nTitle: " . $title
                    . "\nImage URL: " . $usedImageUrl
                    . "\nVideo URL: " . $videoUrl
                    . "\nError: " . ($result['error'] ?? 'Unknown')
                    . "\n\n--- Message ---\n" . $message
                    . "\n\n--- Content ---\n" . $content
                );
            }
        }
    }
}
