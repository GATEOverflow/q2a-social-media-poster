<?php

/**
 * Core social media posting class. Handles posting to all supported platforms
 * with multi-account support.
 */
class SmpPoster
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * Get all accounts for a platform.
     */
    public function getAccounts(string $platform): array
    {
        $json = qa_opt(SmpConstants::accountsOptionKey($platform));
        if (empty($json)) {
            return [];
        }
        $accounts = json_decode($json, true);
        return is_array($accounts) ? $accounts : [];
    }

    /**
     * Get the default account for a platform (or first one).
     */
    public function getDefaultAccount(string $platform): ?array
    {
        $accounts = $this->getAccounts($platform);
        foreach ($accounts as $account) {
            if (!empty($account['is_default']) && !empty($account['enabled'])) {
                return $account;
            }
        }
        // If no default, return first enabled account
        foreach ($accounts as $account) {
            if (!empty($account['enabled'])) {
                return $account;
            }
        }
        return null;
    }

    /**
     * Get all enabled accounts for a platform.
     */
    public function getEnabledAccounts(string $platform): array
    {
        $accounts = $this->getAccounts($platform);
        return array_filter($accounts, function ($a) {
            return !empty($a['enabled']);
        });
    }

    /**
     * Get enabled platforms for a content type.
     * @deprecated Use getEnabledAccountIds for account-level routing
     */
    public function getEnabledPlatforms(string $contentType): array
    {
        $json = qa_opt(SmpConstants::contentPlatformsOptionKey($contentType));
        if (empty($json)) {
            return [];
        }
        $platforms = json_decode($json, true);
        return is_array($platforms) ? $platforms : [];
    }

    /**
     * Get all accounts across all platforms, keyed by account ID.
     * Ensures all accounts have an 'id' field.
     */
    public function getAllAccountsById(): array
    {
        $result = [];
        $platforms = SmpConstants::getPlatforms();
        
        foreach (array_keys($platforms) as $platform) {
            $accounts = $this->getAccounts($platform);
            $modified = false;
            
            foreach ($accounts as $idx => &$account) {
                // Generate ID if missing
                if (empty($account['id'])) {
                    $account['id'] = SmpConstants::generateAccountId($platform);
                    $modified = true;
                }
                $account['_platform'] = $platform;
                $result[$account['id']] = $account;
            }
            
            // Save back if we added IDs
            if ($modified) {
                $this->saveAccounts($platform, $accounts);
            }
        }
        
        return $result;
    }

    /**
     * Get enabled account IDs for a content type (new account-based routing).
     */
    public function getEnabledAccountIds(string $contentType): array
    {
        $json = qa_opt(SmpConstants::contentAccountsOptionKey($contentType));
        if (empty($json)) {
            // Fallback: migrate from old platform-based config
            return $this->migrateToAccountIds($contentType);
        }
        $ids = json_decode($json, true);
        return is_array($ids) ? $ids : [];
    }

    /**
     * Migrate old platform-based config to account IDs.
     */
    private function migrateToAccountIds(string $contentType): array
    {
        $platforms = $this->getEnabledPlatforms($contentType);
        if (empty($platforms)) {
            return [];
        }
        
        $accountIds = [];
        foreach ($platforms as $platform) {
            $account = $this->getDefaultAccount($platform);
            if ($account && !empty($account['id'])) {
                $accountIds[] = $account['id'];
            }
        }
        
        // Save migrated config
        if (!empty($accountIds)) {
            qa_opt(SmpConstants::contentAccountsOptionKey($contentType), json_encode($accountIds));
        }
        
        return $accountIds;
    }

    /**
     * Get category routing configuration.
     */
    public function getCategoryRouting(): array
    {
        $json = qa_opt(SmpConstants::OPT_CATEGORY_ROUTING);
        if (empty($json)) {
            return [];
        }
        $routing = json_decode($json, true);
        return is_array($routing) ? $routing : [];
    }

    /**
     * Get the final list of accounts to post to for a content type and category.
     * 
     * @param string $contentType Content type (question, blog, etc.)
     * @param int|null $categoryId Optional category ID for category-specific routing
     * @return array Array of account data with _platform key
     */
    public function getAccountsForPosting(string $contentType, ?int $categoryId = null): array
    {
        $allAccounts = $this->getAllAccountsById();
        $results = [];
        
        // Get content type default accounts
        $defaultAccountIds = $this->getEnabledAccountIds($contentType);
        
        // Check for category-specific routing
        $categoryAccountIds = [];
        $alsoDefault = true;
        
        if ($categoryId !== null) {
            $routing = $this->getCategoryRouting();
            $catKey = (string)$categoryId;
            
            if (isset($routing[$catKey])) {
                $catConfig = $routing[$catKey];
                $categoryAccountIds = $catConfig['accounts'] ?? [];
                $alsoDefault = $catConfig['also_default'] ?? true;
            }
        }
        
        // Build final account list
        $finalIds = [];
        
        if ($alsoDefault) {
            $finalIds = array_merge($finalIds, $defaultAccountIds);
        }
        
        $finalIds = array_merge($finalIds, $categoryAccountIds);
        $finalIds = array_unique($finalIds);
        
        // Resolve to actual account data
        foreach ($finalIds as $accountId) {
            if (isset($allAccounts[$accountId]) && !empty($allAccounts[$accountId]['enabled'])) {
                $results[$accountId] = $allAccounts[$accountId];
            }
        }
        
        return $results;
    }

    /**
     * Post message to all enabled accounts for a content type.
     * Supports account-level routing and category-specific overrides.
     *
     * @param string $contentType One of SmpConstants::CONTENT_*
     * @param string $message The text message to post
     * @param string|null $imageUrl Optional image URL for Instagram
     * @param array $extra Extra data (e.g., whatsapp template params, categoryid)
     * @return array Results per account ID
     */
    public function postToAll(string $contentType, string $message, ?string $imageUrl = null, array $extra = []): array
    {
        $categoryId = $extra['categoryid'] ?? null;
        $accounts = $this->getAccountsForPosting($contentType, $categoryId);
        $results = [];

        foreach ($accounts as $accountId => $account) {
            $platform = $account['_platform'] ?? '';
            if (empty($platform)) {
                $results[$accountId] = ['success' => false, 'error' => 'Unknown platform for account'];
                continue;
            }

            $result = $this->postToPlatform($platform, $account, $message, $imageUrl, $extra);
            $result['account_name'] = $account['name'] ?? $accountId;
            $result['platform'] = $platform;
            $results[$accountId] = $result;
        }

        return $results;
    }

    /**
     * Post to a specific platform using a specific account.
     */
    public function postToPlatform(string $platform, array $account, string $message, ?string $imageUrl = null, array $extra = []): array
    {
        $creds = $account['credentials'] ?? [];

        switch ($platform) {
            case SmpConstants::PLATFORM_TELEGRAM:
                return $this->postToTelegram($creds, $message);
            case SmpConstants::PLATFORM_FACEBOOK:
                return $this->postToFacebook($creds, $message);
            case SmpConstants::PLATFORM_X:
                return $this->postToX($creds, $message);
            case SmpConstants::PLATFORM_LINKEDIN:
                return $this->postToLinkedin($creds, $message);
            case SmpConstants::PLATFORM_WHATSAPP:
                if (!empty($extra['whatsapp_template'])) {
                    return $this->postToWhatsappTemplate($creds, $message, $extra);
                }
                return $this->postToWhatsapp($creds, $message);
            case SmpConstants::PLATFORM_INSTAGRAM:
                return $this->postToInstagram($creds, $message, $imageUrl);
            case SmpConstants::PLATFORM_YOUTUBE:
                return $this->postToYouTubeShorts($creds, $message, $imageUrl, $extra);
            default:
                return ['success' => false, 'error' => 'Unknown platform: ' . $platform];
        }
    }

    /**
     * Post to Telegram.
     */
    private function postToTelegram(array $creds, string $message): array
    {
        $botToken = $creds['bot_token'] ?? '';
        $chatId = $creds['chat_id'] ?? '';

        if (empty($botToken) || empty($chatId)) {
            return ['success' => false, 'error' => 'Missing Telegram credentials'];
        }

        $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";

        $postData = [
            'chat_id' => $chatId,
            'text' => $message,
            'parse_mode' => 'HTML',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (!empty($data['ok'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => $response];
    }

    /**
     * Post to Facebook Page.
     */
    private function postToFacebook(array $creds, string $message): array
    {
        $pageAccessToken = $creds['page_access_token'] ?? '';
        $pageId = $creds['page_id'] ?? '';

        if (empty($pageAccessToken) || empty($pageId)) {
            return ['success' => false, 'error' => 'Missing Facebook credentials'];
        }

        $url = "https://graph.facebook.com/v21.0/" . urlencode($pageId) . "/feed";

        $postData = [
            'message' => $message,
            'access_token' => $pageAccessToken,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (isset($data['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => $response];
    }

    /**
     * Post to X (Twitter).
     */
    private function postToX(array $creds, string $message): array
    {
        $apiKey = $creds['api_key'] ?? '';
        $apiSecret = $creds['api_secret'] ?? '';
        $accessToken = $creds['access_token'] ?? '';
        $accessTokenSecret = $creds['access_token_secret'] ?? '';

        if (empty($apiKey) || empty($apiSecret) || empty($accessToken) || empty($accessTokenSecret)) {
            return ['success' => false, 'error' => 'Missing X credentials'];
        }

        // Requires TwitterOAuth library
        if (!class_exists('Abraham\TwitterOAuth\TwitterOAuth')) {
            $autoloadPath = $this->directory . 'vendor/autoload.php';
            if (!file_exists($autoloadPath)) {
                // Try the publish-to-email vendor directory
                $altPath = dirname($this->directory) . '/publish-to-email/vendor/autoload.php';
                if (file_exists($altPath)) {
                    require_once $altPath;
                } else {
                    return ['success' => false, 'error' => 'TwitterOAuth library not found'];
                }
            } else {
                require_once $autoloadPath;
            }
        }

        $connection = new Abraham\TwitterOAuth\TwitterOAuth($apiKey, $apiSecret, $accessToken, $accessTokenSecret);
        $connection->setApiVersion('2');

        $result = $connection->post('tweets', ['text' => $message]);

        if ($result && !isset($result->errors)) {
            return ['success' => true, 'response' => (array)$result];
        }

        return ['success' => false, 'error' => json_encode($result)];
    }

    /**
     * Post to LinkedIn.
     */
    private function postToLinkedin(array $creds, string $message): array
    {
        $accessToken = $creds['access_token'] ?? '';
        $author = $creds['author_urn'] ?? '';

        if (empty($accessToken) || empty($author)) {
            return ['success' => false, 'error' => 'Missing LinkedIn credentials'];
        }

        $url = 'https://api.linkedin.com/v2/ugcPosts';

        $postData = [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => [
                        'text' => $message,
                    ],
                    'shareMediaCategory' => 'NONE',
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($postData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
            'x-li-format: json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (isset($data['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => $response];
    }

    /**
     * Post text message to WhatsApp.
     */
    private function postToWhatsapp(array $creds, string $message): array
    {
        $accessToken = $creds['access_token'] ?? '';
        $phoneNumberId = $creds['phone_number_id'] ?? '';
        $recipientPhone = $creds['recipient_phone'] ?? '';

        if (empty($accessToken) || empty($phoneNumberId) || empty($recipientPhone)) {
            return ['success' => false, 'error' => 'Missing WhatsApp credentials'];
        }

        $url = "https://graph.facebook.com/v21.0/" . urlencode($phoneNumberId) . "/messages";

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientPhone,
            'type' => 'text',
            'text' => ['body' => $message],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (isset($data['messages'][0]['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => $response];
    }

    /**
     * Post WhatsApp template message (e.g., for jobs).
     */
    private function postToWhatsappTemplate(array $creds, string $message, array $extra): array
    {
        $accessToken = $creds['access_token'] ?? '';
        $phoneNumberId = $creds['phone_number_id'] ?? '';
        $recipientPhone = $creds['recipient_phone'] ?? '';

        if (empty($accessToken) || empty($phoneNumberId) || empty($recipientPhone)) {
            return ['success' => false, 'error' => 'Missing WhatsApp credentials'];
        }

        $url = "https://graph.facebook.com/v21.0/" . urlencode($phoneNumberId) . "/messages";

        $templateName = $extra['template_name'] ?? 'job_notification';
        $templateLang = $extra['template_lang'] ?? 'en';
        $components = $extra['template_components'] ?? [];

        $data = [
            'messaging_product' => 'whatsapp',
            'to' => $recipientPhone,
            'type' => 'template',
            'template' => [
                'name' => $templateName,
                'language' => ['code' => $templateLang],
                'components' => $components,
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (isset($data['messages'][0]['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => $response];
    }

    /**
     * Post to Instagram (requires image).
     */
    private function postToInstagram(array $creds, string $caption, ?string $imageUrl = null): array
    {
        $accessToken = $creds['access_token'] ?? '';
        $accountId = $creds['account_id'] ?? '';

        if (empty($accessToken) || empty($accountId)) {
            return ['success' => false, 'error' => 'Missing Instagram credentials'];
        }

        if (empty($imageUrl)) {
            return ['success' => false, 'error' => 'Instagram requires an image URL'];
        }

        // Step 1: Create media container
        $createUrl = "https://graph.facebook.com/v20.0/" . urlencode($accountId) . "/media";
        $mediaData = [
            'image_url' => $imageUrl,
            'caption' => $caption,
            'access_token' => $accessToken,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $createUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($mediaData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error creating media: ' . $error];
        }

        $mediaObj = json_decode($response, true);
        if (!isset($mediaObj['id'])) {
            return ['success' => false, 'error' => 'Failed to create media container: ' . $response];
        }

        // Step 2: Publish
        $publishUrl = "https://graph.facebook.com/v20.0/" . urlencode($accountId) . "/media_publish";
        $publishData = [
            'creation_id' => $mediaObj['id'],
            'access_token' => $accessToken,
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $publishUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($publishData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error publishing: ' . $error];
        }

        $data = json_decode($response, true);
        if (isset($data['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => 'Failed to publish: ' . $response];
    }

    /**
     * Post a YouTube Short. Generates a short video from the image if no video_path provided.
     * Uses OAuth2 refresh_token to get a fresh access token.
     */
    private function postToYouTubeShorts(array $creds, string $message, ?string $imageUrl = null, array $extra = []): array
    {
        $clientId = $creds['client_id'] ?? '';
        $clientSecret = $creds['client_secret'] ?? '';
        $refreshToken = $creds['refresh_token'] ?? '';

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            return ['success' => false, 'error' => 'Missing YouTube credentials'];
        }

        // Step 1: Get fresh access token from refresh token
        $tokenResult = $this->refreshGoogleAccessToken($clientId, $clientSecret, $refreshToken);
        if (!$tokenResult['success']) {
            return $tokenResult;
        }
        $accessToken = $tokenResult['access_token'];

        // Step 2: Get or generate video file
        $videoPath = $extra['video_path'] ?? null;
        if (empty($videoPath)) {
            // Auto-generate a short video from text/image
            $videoPath = $this->generateShortsVideo($message, $imageUrl);
            if (empty($videoPath)) {
                return ['success' => false, 'error' => 'Failed to generate video for YouTube Shorts. Ensure ffmpeg is installed.'];
            }
        }

        if (!file_exists($videoPath)) {
            return ['success' => false, 'error' => 'Video file not found: ' . $videoPath];
        }

        // Step 3: Extract title (first line or first 100 chars) + #Shorts tag
        $lines = explode("\n", $message, 2);
        $title = mb_substr(trim($lines[0]), 0, 95) . ' #Shorts';
        $description = $message;

        // Step 4: Initialize resumable upload
        $metadata = [
            'snippet' => [
                'title' => $title,
                'description' => $description,
                'categoryId' => '27', // Education
            ],
            'status' => [
                'privacyStatus' => 'public',
                'selfDeclaredMadeForKids' => false,
            ],
        ];

        $initUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $initUrl);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($metadata));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json; charset=UTF-8',
            'X-Upload-Content-Length: ' . filesize($videoPath),
            'X-Upload-Content-Type: video/mp4',
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            return ['success' => false, 'error' => 'YouTube upload init failed (HTTP ' . $httpCode . '): ' . $response];
        }

        // Extract upload URL from Location header
        $uploadUrl = null;
        foreach (explode("\r\n", $response) as $header) {
            if (stripos($header, 'Location:') === 0) {
                $uploadUrl = trim(substr($header, 9));
                break;
            }
        }

        if (empty($uploadUrl)) {
            return ['success' => false, 'error' => 'No upload URL in YouTube response'];
        }

        // Step 5: Upload the video data
        $videoData = file_get_contents($videoPath);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $videoData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: video/mp4',
            'Content-Length: ' . strlen($videoData),
        ]);
        $uploadResponse = curl_exec($ch);
        $uploadCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $uploadError = curl_error($ch);
        curl_close($ch);

        // Clean up generated video
        if (empty($extra['video_path']) && file_exists($videoPath)) {
            unlink($videoPath);
        }

        if ($uploadError) {
            return ['success' => false, 'error' => 'cURL error uploading video: ' . $uploadError];
        }

        $data = json_decode($uploadResponse, true);
        if ($uploadCode === 200 && isset($data['id'])) {
            return ['success' => true, 'response' => $data];
        }

        return ['success' => false, 'error' => 'YouTube upload failed (HTTP ' . $uploadCode . '): ' . $uploadResponse];
    }

    /**
     * Refresh a Google OAuth2 access token using a refresh token.
     */
    private function refreshGoogleAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $url = 'https://oauth2.googleapis.com/token';

        $postData = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'Token refresh cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (!empty($data['access_token'])) {
            return ['success' => true, 'access_token' => $data['access_token'], 'expires_in' => $data['expires_in'] ?? 3600];
        }

        return ['success' => false, 'error' => 'Token refresh failed: ' . $response];
    }

    /**
     * Generate a short MP4 video from text/image for YouTube Shorts.
     * Uses ffmpeg to create a 9:16 vertical video with a static image and text overlay.
     *
     * @return string|null Path to generated video file, or null on failure
     */
    private function generateShortsVideo(string $text, ?string $imageUrl = null): ?string
    {
        // Check ffmpeg availability
        $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
        if (empty($ffmpegPath)) {
            return null;
        }

        $uploadDir = QA_BASE_DIR . 'qa-uploads/smp-videos/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $filename = 'smp_yt_' . uniqid() . '_' . time();
        $outputPath = $uploadDir . $filename . '.mp4';

        // If we have a generated image, use it as background
        if (!empty($imageUrl)) {
            // Download the image if it's a URL
            $imagePath = null;
            if (filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                $imagePath = $uploadDir . $filename . '_img.png';
                $imgData = file_get_contents($imageUrl);
                if ($imgData !== false) {
                    file_put_contents($imagePath, $imgData);
                }
            } elseif (file_exists($imageUrl)) {
                $imagePath = $imageUrl;
            }

            if ($imagePath && file_exists($imagePath)) {
                // Create 15-second vertical video (1080x1920) from image
                $cmd = escapeshellcmd($ffmpegPath)
                    . ' -y -loop 1 -i ' . escapeshellarg($imagePath)
                    . ' -c:v libx264 -t 15 -pix_fmt yuv420p'
                    . ' -vf ' . escapeshellarg('scale=1080:1920:force_original_aspect_ratio=decrease,pad=1080:1920:(ow-iw)/2:(oh-ih)/2:white')
                    . ' -r 30 ' . escapeshellarg($outputPath)
                    . ' 2>&1';

                exec($cmd, $output, $returnCode);

                // Clean up temp image
                if ($imagePath !== $imageUrl && file_exists($imagePath)) {
                    unlink($imagePath);
                }

                if ($returnCode === 0 && file_exists($outputPath)) {
                    return $outputPath;
                }
            }
        }

        // Fallback: generate a simple color background video with text via ffmpeg drawtext
        $escapedText = str_replace(["'", '"', '\\', ':', '%'], ["\'", '\"', '\\\\', '\\:', '%%'], mb_substr($text, 0, 200));

        // Find a font for drawtext
        $font = '';
        $fontPaths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ];
        foreach ($fontPaths as $fp) {
            if (file_exists($fp)) {
                $font = ':fontfile=' . escapeshellarg($fp);
                break;
            }
        }

        $drawtext = "drawtext=text='" . $escapedText . "'" . $font
            . ":fontsize=36:fontcolor=white:x=(w-text_w)/2:y=(h-text_h)/2:line_spacing=10";

        $cmd = escapeshellcmd($ffmpegPath)
            . ' -y -f lavfi -i ' . escapeshellarg('color=c=0x333333:s=1080x1920:d=15:r=30')
            . ' -vf ' . escapeshellarg($drawtext)
            . ' -c:v libx264 -pix_fmt yuv420p -t 15'
            . ' ' . escapeshellarg($outputPath)
            . ' 2>&1';

        exec($cmd, $output, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath)) {
            return $outputPath;
        }

        return null;
    }

    /**
     * Call OpenAI to generate a social media message.
     */
    public function openaiGenerateMessage(string $content, string $systemPrompt = ''): string
    {
        $apiKey = qa_opt(SmpConstants::OPT_OPENAI_KEY);
        if (empty($apiKey)) {
            return $content;
        }

        if (empty($systemPrompt)) {
            $systemPrompt = qa_opt(SmpConstants::OPT_OPENAI_CONFIG);
        }
        if (empty($systemPrompt)) {
            $systemPrompt = 'Create a short social media announcement for the following content. Keep it engaging and concise.';
        }

        $url = 'https://api.openai.com/v1/chat/completions';
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $content],
            ],
            'max_tokens' => 300,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $response === false) {
            return $content;
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? $content;
    }

    /**
     * Get the Q2A admin email address.
     */
    public static function getAdminEmail(): string
    {
        // Try admin_email first, then feedback_email, then from_email
        $email = qa_opt('admin_email');
        if (empty($email)) {
            $email = qa_opt('feedback_email');
        }
        if (empty($email)) {
            $email = qa_opt('from_email');
        }
        return $email ?: '';
    }

    /**
     * Report a posting failure via email to the Q2A admin.
     */
    public function reportFailure(string $subject, string $details = ''): void
    {
        if (!function_exists('qa_send_email')) {
            return;
        }

        $email = self::getAdminEmail();
        if (empty($email)) {
            return;
        }

        qa_send_email([
            'fromemail' => qa_opt('from_email'),
            'fromname' => qa_opt('site_name'),
            'replytoemail' => qa_opt('from_email'),
            'replytoname' => qa_opt('site_name') . ' (Do Not Reply)',
            'toemail' => $email,
            'toname' => 'Admin',
            'subject' => '[SMP] ' . $subject,
            'body' => 'Social Media Poster failure report' . "\n\n"
                . 'Platform/Subject: ' . $subject . "\n"
                . 'Time: ' . date('Y-m-d H:i:s') . "\n"
                . 'Site: ' . qa_opt('site_url') . "\n\n"
                . 'Details:' . "\n" . $details,
            'html' => false,
        ]);
    }

    /**
     * Send a token expiry warning email to the Q2A admin.
     */
    public function sendTokenExpiryWarning(string $platform, string $accountName, string $expiryDate, int $daysLeft): void
    {
        if (!function_exists('qa_send_email')) {
            return;
        }

        $email = self::getAdminEmail();
        if (empty($email)) {
            return;
        }

        $urgency = $daysLeft <= 2 ? 'URGENT: ' : '';

        qa_send_email([
            'fromemail' => qa_opt('from_email'),
            'fromname' => qa_opt('site_name'),
            'replytoemail' => qa_opt('from_email'),
            'replytoname' => qa_opt('site_name') . ' (Do Not Reply)',
            'toemail' => $email,
            'toname' => 'Admin',
            'subject' => '[SMP] ' . $urgency . 'Token expiry warning - ' . $platform . ' (' . $accountName . ')',
            'body' => 'Social Media Poster - Token Expiry Warning' . "\n\n"
                . 'Platform: ' . $platform . "\n"
                . 'Account: ' . $accountName . "\n"
                . 'Expiry Date: ' . $expiryDate . "\n"
                . 'Days Remaining: ' . $daysLeft . "\n\n"
                . ($daysLeft <= 2
                    ? 'ACTION REQUIRED: This token will expire very soon. Please renew it immediately to avoid service interruption.'
                    : 'Please plan to renew this token before it expires to avoid service interruption.'
                ) . "\n\n"
                . 'Manage tokens: ' . qa_opt('site_url') . 'admin/plugins',
            'html' => false,
        ]);
    }

    /**
     * Probe all accounts for token expiry dates via platform APIs,
     * store the results, then check for upcoming expiry and send warnings.
     */
    public function probeAndCheckTokenExpiry(): void
    {
        $platforms = SmpConstants::getPlatforms();

        // Phase 1: Probe each account to auto-detect expiry
        foreach ($platforms as $platformId => $platformInfo) {
            $accounts = $this->getAccounts($platformId);
            $accountsChanged = false;

            foreach ($accounts as $idx => &$account) {
                if (empty($account['enabled'])) {
                    continue;
                }

                $creds = $account['credentials'] ?? [];
                $detected = $this->probeTokenExpiry($platformId, $creds);

                if ($detected !== null) {
                    $account['token_expiry_date'] = $detected;
                    $account['token_expiry_source'] = 'auto';
                    $accountsChanged = true;
                } elseif (!isset($account['token_expiry_date'])) {
                    // No expiry detectable (e.g. Telegram bot tokens never expire)
                    $account['token_expiry_date'] = '';
                    $account['token_expiry_source'] = 'none';
                }
            }
            unset($account);

            if ($accountsChanged) {
                $this->saveAccounts($platformId, $accounts);
            }
        }

        // Phase 2: Check stored expiry dates and send warnings
        $this->checkTokenExpiry();
    }

    /**
     * Probe a single account's token expiry by calling the appropriate platform API.
     *
     * @return string|null YYYY-MM-DD expiry date, or null if not determinable
     */
    private function probeTokenExpiry(string $platform, array $creds): ?string
    {
        switch ($platform) {
            case SmpConstants::PLATFORM_FACEBOOK:
                return $this->probeMetaTokenExpiry($creds['page_access_token'] ?? '');

            case SmpConstants::PLATFORM_INSTAGRAM:
                return $this->probeMetaTokenExpiry($creds['access_token'] ?? '');

            case SmpConstants::PLATFORM_WHATSAPP:
                return $this->probeMetaTokenExpiry($creds['access_token'] ?? '');

            case SmpConstants::PLATFORM_LINKEDIN:
                return $this->probeLinkedInTokenExpiry($creds['access_token'] ?? '');

            case SmpConstants::PLATFORM_TELEGRAM:
                // Telegram bot tokens never expire
                return null;

            case SmpConstants::PLATFORM_X:
                // X/Twitter OAuth 1.0a tokens don't expire
                return null;

            case SmpConstants::PLATFORM_YOUTUBE:
                return $this->probeGoogleTokenExpiry($creds);

            default:
                return null;
        }
    }

    /**
     * Use Meta Graph API debug_token endpoint to get token expiry.
     * Works for Facebook, Instagram, and WhatsApp tokens.
     *
     * @return string|null YYYY-MM-DD or null
     */
    private function probeMetaTokenExpiry(string $accessToken): ?string
    {
        if (empty($accessToken)) {
            return null;
        }

        $url = 'https://graph.facebook.com/debug_token?'
            . http_build_query(['input_token' => $accessToken, 'access_token' => $accessToken]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $response === false) {
            return null;
        }

        $data = json_decode($response, true);
        if (!isset($data['data'])) {
            return null;
        }

        $tokenData = $data['data'];

        // data_access_expires_at is the stricter limit for data access
        // expires_at is when the token itself expires
        // Use the earlier of the two if both exist
        $timestamps = [];
        if (!empty($tokenData['data_access_expires_at'])) {
            $timestamps[] = (int)$tokenData['data_access_expires_at'];
        }
        if (!empty($tokenData['expires_at'])) {
            $timestamps[] = (int)$tokenData['expires_at'];
        }

        if (empty($timestamps)) {
            // Token never expires (e.g. page tokens with "never expires")
            return null;
        }

        $earliest = min($timestamps);
        if ($earliest <= 0) {
            // 0 means never expires
            return null;
        }

        return date('Y-m-d', $earliest);
    }

    /**
     * Probe LinkedIn token by calling the /v2/userinfo endpoint.
     * LinkedIn tokens typically expire in 60 days. The API doesn't expose
     * the exact expiry, so we test if the token is still valid.
     * Returns null (can't determine date) or empty string if token is dead.
     *
     * @return string|null null if valid/indeterminate, today's date if expired
     */
    private function probeLinkedInTokenExpiry(string $accessToken): ?string
    {
        if (empty($accessToken)) {
            return null;
        }

        $url = 'https://api.linkedin.com/v2/userinfo';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401) {
            // Token is already expired
            return date('Y-m-d');
        }

        // Token is valid but we can't determine exact expiry from LinkedIn API
        return null;
    }

    /**
     * Probe Google OAuth2 token expiry by attempting a token refresh
     * and checking the expires_in value from the response.
     */
    private function probeGoogleTokenExpiry(array $creds): ?string
    {
        $clientId = $creds['client_id'] ?? '';
        $clientSecret = $creds['client_secret'] ?? '';
        $refreshToken = $creds['refresh_token'] ?? '';

        if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
            return null;
        }

        $result = $this->refreshGoogleAccessToken($clientId, $clientSecret, $refreshToken);

        if (!$result['success']) {
            // Refresh failed — token is likely expired/revoked
            return date('Y-m-d');
        }

        // Google access tokens expire in ~1 hour, but the refresh token doesn't expire
        // unless revoked. If refresh works, the token is valid indefinitely.
        return null;
    }

    /**
     * Save accounts for a platform (public for use by token checker).
     */
    public function saveAccounts(string $platform, array $accounts): void
    {
        qa_opt(SmpConstants::accountsOptionKey($platform), json_encode(array_values($accounts)));
    }

    /**
     * Check all accounts for upcoming token expiry and send warning emails.
     * Sends at 7 days and 2 days before expiry.
     */
    public function checkTokenExpiry(): void
    {
        $platforms = SmpConstants::getPlatforms();
        $notifiedJson = qa_opt(SmpConstants::OPT_EXPIRY_NOTIFIED);
        $notified = !empty($notifiedJson) ? json_decode($notifiedJson, true) : [];
        if (!is_array($notified)) {
            $notified = [];
        }
        $changed = false;
        $today = new DateTime('today');

        foreach ($platforms as $platformId => $platformInfo) {
            $accounts = $this->getAccounts($platformId);
            foreach ($accounts as $idx => $account) {
                $expiryDate = $account['token_expiry_date'] ?? '';
                if (empty($expiryDate)) {
                    continue;
                }

                try {
                    $expiry = new DateTime($expiryDate);
                } catch (Exception $e) {
                    continue;
                }

                $diff = $today->diff($expiry);
                $daysLeft = $diff->invert ? -$diff->days : $diff->days;

                // Skip already expired tokens — only warn for future
                if ($daysLeft < 0) {
                    continue;
                }

                $accountName = $account['name'] ?? ('Account ' . ($idx + 1));
                $notifyKey = $platformId . '_' . $idx . '_' . $expiryDate;

                // Check 7-day warning
                if ($daysLeft <= 7 && $daysLeft > 2) {
                    if (empty($notified[$notifyKey . '_7'])) {
                        $this->sendTokenExpiryWarning(
                            $platformInfo['name'],
                            $accountName,
                            $expiryDate,
                            $daysLeft
                        );
                        $notified[$notifyKey . '_7'] = date('Y-m-d');
                        $changed = true;
                    }
                }

                // Check 2-day warning
                if ($daysLeft <= 2) {
                    if (empty($notified[$notifyKey . '_2'])) {
                        $this->sendTokenExpiryWarning(
                            $platformInfo['name'],
                            $accountName,
                            $expiryDate,
                            $daysLeft
                        );
                        $notified[$notifyKey . '_2'] = date('Y-m-d');
                        $changed = true;
                    }
                }
            }
        }

        if ($changed) {
            qa_opt(SmpConstants::OPT_EXPIRY_NOTIFIED, json_encode($notified));
        }
    }
}
