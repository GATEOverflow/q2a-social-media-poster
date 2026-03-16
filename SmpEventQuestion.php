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
        
        // Check if any accounts are configured for this content type (with category routing)
        $accounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_QUESTION, $categoryId);
        if (empty($accounts)) {
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
        $accountPlatforms = array_column($accounts, '_platform');
        $needsImage = (in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_INSTAGRAM_AUTO_IMAGE))
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateFromText($content, $title, $postId);
        }

        // Post to all enabled accounts (with category routing)
        $extra = ['categoryid' => $categoryId];
        $results = $poster->postToAll(SmpConstants::CONTENT_QUESTION, $message, $imageUrl, $extra);

        // Report any failures
        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $poster->reportFailure(
                    'Question post failed on ' . $platform . ' (' . $accountName . ')',
                    'Post ID: ' . $postId . "\nTitle: " . $title . "\nError: " . ($result['error'] ?? 'Unknown')
                );
            }
        }
    }
}
