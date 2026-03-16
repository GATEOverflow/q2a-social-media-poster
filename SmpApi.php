<?php

/**
 * Public API for Social Media Poster plugin.
 * Other Q2A plugins can use these functions to post to social media.
 * 
 * Usage from another plugin:
 * 
 *   require_once QA_PLUGIN_DIR . 'social-media-poster/SmpApi.php';
 *   
 *   // Post to all accounts configured for a content type
 *   $results = smp_post('question', 'Check out this new question!', 'https://example.com/q/123');
 *   
 *   // Post to specific platforms only
 *   $results = smp_post_to_platforms(['telegram', 'facebook'], 'Hello world!');
 *   
 *   // Post to a specific account by ID
 *   $result = smp_post_to_account('telegram_abc12345', 'Direct message to this channel');
 */

if (!defined('QA_VERSION')) {
    header('Location: ../../');
    exit;
}

/**
 * Post a message to all accounts configured for a content type.
 * 
 * @param string $contentType One of: 'question', 'exam', 'blog', 'job', 'qotd', 'quote'
 * @param string $message The message to post
 * @param string|null $url Optional URL to append to message
 * @param string|null $imageUrl Optional image URL (for Instagram/YouTube)
 * @param int|null $categoryId Optional category ID for category-specific routing (questions only)
 * @return array Results keyed by account ID with 'success', 'error', 'platform', 'account_name'
 */
function smp_post(string $contentType, string $message, ?string $url = null, ?string $imageUrl = null, ?int $categoryId = null): array
{
    $poster = smp_get_poster();
    if (!$poster) {
        return ['error' => 'Social Media Poster not available'];
    }

    if ($url) {
        $message .= "\n\n" . $url;
    }

    $extra = [];
    if ($categoryId !== null) {
        $extra['categoryid'] = $categoryId;
    }

    return $poster->postToAll($contentType, $message, $imageUrl, $extra);
}

/**
 * Post to specific platforms only (uses default account for each platform).
 * 
 * @param array $platforms Array of platform IDs: 'telegram', 'facebook', 'x', 'linkedin', 'whatsapp', 'instagram', 'youtube'
 * @param string $message The message to post
 * @param string|null $imageUrl Optional image URL
 * @return array Results keyed by platform
 */
function smp_post_to_platforms(array $platforms, string $message, ?string $imageUrl = null): array
{
    $poster = smp_get_poster();
    if (!$poster) {
        return ['error' => 'Social Media Poster not available'];
    }

    $results = [];
    foreach ($platforms as $platform) {
        $account = $poster->getDefaultAccount($platform);
        if (!$account) {
            $results[$platform] = ['success' => false, 'error' => 'No enabled account found'];
            continue;
        }
        $results[$platform] = $poster->postToPlatform($platform, $account, $message, $imageUrl);
    }

    return $results;
}

/**
 * Post to a specific account by its ID.
 * 
 * @param string $accountId The unique account ID (e.g., 'telegram_abc12345')
 * @param string $message The message to post
 * @param string|null $imageUrl Optional image URL
 * @return array Result with 'success', 'error' keys
 */
function smp_post_to_account(string $accountId, string $message, ?string $imageUrl = null): array
{
    $poster = smp_get_poster();
    if (!$poster) {
        return ['success' => false, 'error' => 'Social Media Poster not available'];
    }

    $allAccounts = $poster->getAllAccountsById();
    if (!isset($allAccounts[$accountId])) {
        return ['success' => false, 'error' => 'Account not found: ' . $accountId];
    }

    $account = $allAccounts[$accountId];
    $platform = $account['_platform'] ?? '';
    
    if (empty($platform)) {
        return ['success' => false, 'error' => 'Unknown platform for account'];
    }

    return $poster->postToPlatform($platform, $account, $message, $imageUrl);
}

/**
 * Generate an image from text (for Instagram/YouTube posts).
 * 
 * @param string $text Main text content
 * @param string $title Optional title/header
 * @param int|null $postId Optional post ID for unique filename
 * @return string|null URL to generated image, or null on failure
 */
function smp_generate_image(string $text, string $title = '', ?int $postId = null): ?string
{
    $dir = dirname(__FILE__) . '/';
    require_once $dir . 'SmpImageGenerator.php';
    
    $generator = new SmpImageGenerator();
    return $generator->generateFromText($text, $title, $postId ?? time());
}

/**
 * Generate a social media message using OpenAI.
 * 
 * @param string $content The content to summarize/transform
 * @param string|null $customPrompt Optional custom prompt (uses default if null)
 * @return string Generated message, or original content if OpenAI fails
 */
function smp_generate_message(string $content, ?string $customPrompt = null): string
{
    $poster = smp_get_poster();
    if (!$poster) {
        return $content;
    }

    return $poster->openaiGenerateMessage($content, $customPrompt);
}

/**
 * Get all configured accounts.
 * 
 * @return array Accounts keyed by ID with platform info
 */
function smp_get_accounts(): array
{
    $poster = smp_get_poster();
    if (!$poster) {
        return [];
    }

    return $poster->getAllAccountsById();
}

/**
 * Get accounts enabled for a specific content type.
 * 
 * @param string $contentType Content type identifier
 * @param int|null $categoryId Optional category for routing
 * @return array Accounts that would receive posts for this content type
 */
function smp_get_accounts_for_content(string $contentType, ?int $categoryId = null): array
{
    $poster = smp_get_poster();
    if (!$poster) {
        return [];
    }

    return $poster->getAccountsForPosting($contentType, $categoryId);
}

/**
 * Check if the Social Media Poster plugin is properly configured.
 * 
 * @return bool True if at least one account is enabled
 */
function smp_is_configured(): bool
{
    $accounts = smp_get_accounts();
    foreach ($accounts as $account) {
        if (!empty($account['enabled'])) {
            return true;
        }
    }
    return false;
}

/**
 * Get the SmpPoster instance. Internal helper.
 * 
 * @return SmpPoster|null
 */
function smp_get_poster(): ?SmpPoster
{
    static $poster = null;
    
    if ($poster === null) {
        $dir = dirname(__FILE__) . '/';
        
        if (!file_exists($dir . 'SmpConstants.php') || !file_exists($dir . 'SmpPoster.php')) {
            return null;
        }
        
        require_once $dir . 'SmpConstants.php';
        require_once $dir . 'SmpPoster.php';
        
        $poster = new SmpPoster($dir);
    }
    
    return $poster;
}

/**
 * Platform constants for convenience.
 */
define('SMP_PLATFORM_TELEGRAM', 'telegram');
define('SMP_PLATFORM_FACEBOOK', 'facebook');
define('SMP_PLATFORM_X', 'x');
define('SMP_PLATFORM_LINKEDIN', 'linkedin');
define('SMP_PLATFORM_WHATSAPP', 'whatsapp');
define('SMP_PLATFORM_INSTAGRAM', 'instagram');
define('SMP_PLATFORM_YOUTUBE', 'youtube');

/**
 * Content type constants for convenience.
 */
define('SMP_CONTENT_QUESTION', 'question');
define('SMP_CONTENT_EXAM', 'exam');
define('SMP_CONTENT_BLOG', 'blog');
define('SMP_CONTENT_JOB', 'job');
define('SMP_CONTENT_QOTD', 'qotd');
define('SMP_CONTENT_QUOTE', 'quote');
