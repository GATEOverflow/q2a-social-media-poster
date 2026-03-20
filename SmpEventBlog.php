<?php

/**
 * Event handler for new blog posts (qas_blog_b_post).
 * Posts to enabled social media platforms.
 */
class SmpEventBlog
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function process_event($event, $userid, $handle, $cookieid, $params)
    {
        if ($event !== 'qas_blog_b_post') {
            return;
        }

        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $poster = new SmpPoster($this->directory);

        // Get auto-posting accounts + manually selected accounts
        $autoAccounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_BLOG);
        $manualAccounts = $poster->getManualShareAccounts(SmpConstants::CONTENT_BLOG);

        // Get user's personal accounts (if configured)
        $userAccounts = [];
        if ($userid) {
            $userAccounts = $poster->getUserAccountsForPosting((int)$userid);
        }

        if (empty($autoAccounts) && empty($manualAccounts) && empty($userAccounts)) {
            return;
        }

        $title = $params['title'] ?? '';
        $content = $params['content'] ?? '';
        $postId = $params['postid'] ?? 0;

        // Build URL using blog path helper if available
        if (function_exists('qas_blog_request')) {
            $url = qa_path_absolute(qas_blog_request($postId, $title));
        } else {
            $url = qa_opt('site_url') . 'blog/' . $postId . '/' . urlencode($title);
        }

        // Generate message using OpenAI
        $rawContent = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        $messageInput = $title . "\n" . mb_substr($rawContent, 0, 500);
        $generatedMessage = $poster->openaiGenerateMessage($messageInput);

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

        // Post to all enabled accounts
        $results = $poster->postToAll(SmpConstants::CONTENT_BLOG, $message, $imageUrl, ['title' => $title, '_manual_accounts' => $manualAccounts + $userAccounts]);

        // Report failures
        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'Blog post failed on ' . $platform . ' (' . $accountName . ')',
                    'Blog ID: ' . $postId
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
