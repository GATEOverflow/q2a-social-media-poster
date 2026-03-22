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
     * Get manually selected share accounts from POST data.
     * Validates user level and returns only valid, enabled accounts
     * that are NOT already in the auto-posting set for this content type.
     *
     * @param string $contentType Content type
     * @param int|null $categoryId Optional category for routing
     * @return array Account data keyed by account ID (with _platform)
     */
    public function getManualShareAccounts(string $contentType, ?int $categoryId = null): array
    {
        $selectedIds = $_POST['smp_share'] ?? [];
        if (empty($selectedIds) || !is_array($selectedIds)) {
            return [];
        }

        // Check user level
        $minLevel = (int)qa_opt(SmpConstants::OPT_MANUAL_SHARE_LEVEL);
        if ($minLevel === 0) {
            $minLevel = QA_USER_LEVEL_EDITOR;
        }
        if (qa_get_logged_in_level() < $minLevel) {
            return [];
        }

        // Get accounts that are already auto-posting
        $autoAccounts = $this->getAccountsForPosting($contentType, $categoryId);
        $allAccounts = $this->getAllAccountsById();
        $result = [];

        foreach ($selectedIds as $accountId) {
            // Skip if already auto-posting, not a real account, or not enabled
            if (isset($autoAccounts[$accountId])) {
                continue;
            }
            if (!isset($allAccounts[$accountId]) || empty($allAccounts[$accountId]['enabled'])) {
                continue;
            }
            $result[$accountId] = $allAccounts[$accountId];
        }

        return $result;
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

        // Merge manually selected accounts if present
        $manualAccounts = $extra['_manual_accounts'] ?? [];
        if (!empty($manualAccounts)) {
            $accounts = $accounts + $manualAccounts;
        }

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
                return $this->postToTelegram($creds, $message, $imageUrl);
            case SmpConstants::PLATFORM_FACEBOOK:
                return $this->postToFacebook($creds, $message, $imageUrl);
            case SmpConstants::PLATFORM_X:
                return $this->postToX($creds, $message, $imageUrl);
            case SmpConstants::PLATFORM_LINKEDIN:
                return $this->postToLinkedin($creds, $message, $imageUrl);
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
    private function postToTelegram(array $creds, string $message, ?string $imageUrl = null): array
    {
        $botToken = $creds['bot_token'] ?? '';
        $chatId = $creds['chat_id'] ?? '';
        $messageThreadId = $creds['message_thread_id'] ?? '';

        if (empty($botToken) || empty($chatId)) {
            return ['success' => false, 'error' => 'Missing Telegram credentials'];
        }

        if (!empty($imageUrl)) {
            // Send photo with caption
            $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendPhoto";
            $postData = [
                'chat_id' => $chatId,
                'photo' => $imageUrl,
                'caption' => $message,
                'parse_mode' => 'HTML',
            ];
        } else {
            $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";
            $postData = [
                'chat_id' => $chatId,
                'text' => $message,
                'parse_mode' => 'HTML',
            ];
        }

        // Support Telegram group topics (forum mode)
        if (!empty($messageThreadId)) {
            $postData['message_thread_id'] = (int)$messageThreadId;
        }

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
    private function postToFacebook(array $creds, string $message, ?string $imageUrl = null): array
    {
        $pageAccessToken = $creds['page_access_token'] ?? '';
        $pageId = $creds['page_id'] ?? '';

        if (empty($pageAccessToken) || empty($pageId)) {
            return ['success' => false, 'error' => 'Missing Facebook credentials'];
        }

        if (!empty($imageUrl)) {
            $url = "https://graph.facebook.com/v21.0/" . urlencode($pageId) . "/photos";
            $postData = [
                'url' => $imageUrl,
                'message' => $message,
                'access_token' => $pageAccessToken,
            ];
        } else {
            $url = "https://graph.facebook.com/v21.0/" . urlencode($pageId) . "/feed";
            $postData = [
                'message' => $message,
                'access_token' => $pageAccessToken,
            ];
        }

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
    private function postToX(array $creds, string $message, ?string $imageUrl = null): array
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
    private function postToLinkedin(array $creds, string $message, ?string $imageUrl = null): array
    {
        $accessToken = $creds['access_token'] ?? '';
        $author = $creds['author_urn'] ?? '';
        $clientId = $creds['client_id'] ?? '';
        $clientSecret = $creds['client_secret'] ?? '';
        $refreshToken = $creds['refresh_token'] ?? '';

        // If we have full OAuth credentials with refresh_token, get a fresh access token
        if (!empty($clientId) && !empty($clientSecret) && !empty($refreshToken)) {
            $refreshResult = $this->refreshLinkedInAccessToken($clientId, $clientSecret, $refreshToken);
            if ($refreshResult['success']) {
                $accessToken = $refreshResult['access_token'];
            }
            // If refresh fails, fall through to use existing access_token
        }

        if (empty($accessToken) || empty($author)) {
            return ['success' => false, 'error' => 'Missing LinkedIn credentials (access_token or author_urn)'];
        }

        // If image is available, upload it and post as IMAGE share
        if (!empty($imageUrl)) {
            return $this->postToLinkedinWithImage($accessToken, $author, $message, $imageUrl);
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
     * Post to LinkedIn with an image.
     * Uses the 3-step process: register upload, upload binary, create post.
     */
    private function postToLinkedinWithImage(string $accessToken, string $author, string $message, string $imageUrl): array
    {
        // Step 1: Register upload
        $registerData = [
            'registerUploadRequest' => [
                'recipes' => ['urn:li:digitalmediaRecipe:feedshare-image'],
                'owner' => $author,
                'serviceRelationships' => [
                    ['relationshipType' => 'OWNER', 'identifier' => 'urn:li:userGeneratedContent'],
                ],
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.linkedin.com/v2/assets?action=registerUpload');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($registerData));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        $regResponse = json_decode(curl_exec($ch), true);
        curl_close($ch);

        $uploadUrl = $regResponse['value']['uploadMechanism']['com.linkedin.digitalmedia.uploading.MediaUploadHttpRequest']['uploadUrl'] ?? '';
        $asset = $regResponse['value']['asset'] ?? '';

        if (empty($uploadUrl) || empty($asset)) {
            // Fallback to text-only post
            return ['success' => false, 'error' => 'LinkedIn image register failed: ' . json_encode($regResponse)];
        }

        // Step 2: Download image and upload binary to LinkedIn
        $imageData = @file_get_contents($imageUrl);
        if (empty($imageData)) {
            return ['success' => false, 'error' => 'Could not download image from ' . $imageUrl];
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $uploadUrl);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $imageData);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: image/png',
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            return ['success' => false, 'error' => 'LinkedIn image upload failed with HTTP ' . $httpCode];
        }

        // Step 3: Create UGC post with image
        $postData = [
            'author' => $author,
            'lifecycleState' => 'PUBLISHED',
            'specificContent' => [
                'com.linkedin.ugc.ShareContent' => [
                    'shareCommentary' => ['text' => $message],
                    'shareMediaCategory' => 'IMAGE',
                    'media' => [
                        [
                            'status' => 'READY',
                            'media' => $asset,
                        ],
                    ],
                ],
            ],
            'visibility' => [
                'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
            ],
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, 'https://api.linkedin.com/v2/ugcPosts');
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
            return ['success' => false, 'error' => 'Missing Instagram credentials', 'image_url' => $imageUrl];
        }

        if (empty($imageUrl)) {
            // Auto-generate image from caption text
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateFromText($caption, '');
        }

        if (empty($imageUrl)) {
            return ['success' => false, 'error' => 'Instagram requires an image URL and auto-generation failed (check GD extension, font availability, and qa-uploads directory permissions)', 'image_url' => null];
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
            return ['success' => false, 'error' => 'cURL error creating media: ' . $error, 'image_url' => $imageUrl];
        }

        $mediaObj = json_decode($response, true);
        if (!isset($mediaObj['id'])) {
            return ['success' => false, 'error' => 'Failed to create media container: ' . $response, 'image_url' => $imageUrl];
        }

        // Step 2: Wait for container to finish processing
        $containerId = $mediaObj['id'];
        $statusUrl = "https://graph.facebook.com/v20.0/" . urlencode($containerId)
            . "?" . http_build_query(['fields' => 'status_code', 'access_token' => $accessToken]);
        $maxAttempts = 10;
        $pollInterval = 3; // seconds
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            sleep($pollInterval);
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $statusUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            $statusResponse = curl_exec($ch);
            curl_close($ch);

            $statusData = json_decode($statusResponse, true);
            $statusCode = $statusData['status_code'] ?? '';

            if ($statusCode === 'FINISHED') {
                break;
            }
            if ($statusCode === 'ERROR') {
                return ['success' => false, 'error' => 'Instagram media processing failed: ' . $statusResponse, 'image_url' => $imageUrl];
            }
            // IN_PROGRESS — keep polling
        }

        // Step 3: Publish
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
            return ['success' => false, 'error' => 'cURL error publishing: ' . $error, 'image_url' => $imageUrl];
        }

        $data = json_decode($response, true);
        if (isset($data['id'])) {
            return ['success' => true, 'response' => $data, 'image_url' => $imageUrl];
        }

        return ['success' => false, 'error' => 'Failed to publish: ' . $response, 'image_url' => $imageUrl];
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

        // Step 3: Use original post title if available, otherwise extract from message
        $title = $extra['title'] ?? '';
        if (empty($title)) {
            foreach (explode("\n", $message) as $line) {
                $line = trim(strip_tags($line));
                if ($line !== '' && !preg_match('#^https?://#i', $line)) {
                    $title = $line;
                    break;
                }
            }
        }
        if (empty($title)) {
            $title = mb_substr(trim(strip_tags($message)), 0, 95);
        }
        if (empty($title)) {
            $title = 'New Video';
        }
        $title = str_replace(['<', '>'], '', $title);
        $title = mb_substr(trim($title), 0, 95) . ' #Shorts';
        $description = strip_tags($message);

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
            return ['success' => false, 'error' => 'YouTube upload init failed (HTTP ' . $httpCode . '). Title used: [' . $title . ']. Response: ' . $response];
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
            return [
                'success' => true,
                'response' => $data,
                'video_url' => 'https://youtube.com/shorts/' . $data['id'],
            ];
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
     * Refresh a LinkedIn OAuth2 access token using a refresh token.
     */
    private function refreshLinkedInAccessToken(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $url = 'https://www.linkedin.com/oauth/v2/accessToken';

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
            return ['success' => false, 'error' => 'LinkedIn token refresh cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (!empty($data['access_token'])) {
            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000,
                'refresh_token' => $data['refresh_token'] ?? $refreshToken,
                'refresh_token_expires_in' => $data['refresh_token_expires_in'] ?? null,
            ];
        }

        return ['success' => false, 'error' => 'LinkedIn token refresh failed: ' . $response];
    }

    /**
     * Generate a short MP4 video from text/image for YouTube Shorts.
     * Uses ffmpeg to create a 9:16 vertical video with a static image and text overlay.
     *
     * @return string|null Path to generated video file, or null on failure
     */
    private function generateShortsVideo(string $text, ?string $imageUrl = null): ?string
    {
        // Check ffmpeg availability - try known paths first since Apache PATH may be limited
        $ffmpegPath = '';
        foreach (['/usr/bin/ffmpeg', '/usr/local/bin/ffmpeg', '/snap/bin/ffmpeg'] as $path) {
            if (is_file($path) && is_executable($path)) {
                $ffmpegPath = $path;
                break;
            }
        }
        if (empty($ffmpegPath)) {
            $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?? '');
        }
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

    // ==================== Quote Bank Methods ====================

    /**
     * Generate a bank of quotes using OpenAI, scheduled starting from a given date.
     * Makes 10 API calls of 10 quotes each to get 100 total.
     *
     * @param string $startDate YYYY-MM-DD
     * @param int $count Number of quotes to generate
     * @param string $customPrompt Optional custom prompt
     * @return array Generated quote bank [{date, quote, status}, ...]
     */
    public function generateQuoteBank(string $startDate, int $count = 100, string $customPrompt = ''): array
    {
        $apiKey = qa_opt(SmpConstants::OPT_OPENAI_KEY);
        if (empty($apiKey)) {
            return [];
        }

        if (empty($customPrompt)) {
            $customPrompt = qa_opt(SmpConstants::OPT_QUOTE_PROMPT);
        }
        if (empty($customPrompt)) {
            $customPrompt = 'Generate motivational quotes suitable for students preparing for competitive exams like GATE CSE. '
                . 'Include the quote and attribute it to a famous person or mark it as anonymous.';
        }

        $bank = [];
        $batchSize = 10;
        $batches = (int)ceil($count / $batchSize);
        $date = new DateTime($startDate);

        for ($b = 0; $b < $batches && count($bank) < $count; $b++) {
            $remaining = min($batchSize, $count - count($bank));
            $batchNum = $b + 1;

            $systemPrompt = $customPrompt . "\n\n"
                . "IMPORTANT: Return EXACTLY {$remaining} quotes, one per line, numbered 1 through {$remaining}. "
                . "Each quote should be complete with attribution. Format nicely for social media. Do NOT use any emoji characters. "
                . "Add relevant hashtags like #QuoteOfTheDay #Motivation. "
                . "Do NOT include any extra text, headers, or explanations — just the numbered quotes.";

            $userMsg = "Generate batch {$batchNum} of {$batches}: {$remaining} unique motivational quotes. "
                . "Make each one different in theme and source. Batch {$batchNum} should be distinct from previous batches.";

            $response = $this->callOpenAI($systemPrompt, $userMsg, 2000);
            if (empty($response)) {
                continue;
            }

            // Parse numbered lines
            $lines = preg_split('/\n(?=\d+[\.\)]\s)/', trim($response));
            foreach ($lines as $line) {
                if (count($bank) >= $count) break;
                $quote = preg_replace('/^\d+[\.\)]\s*/', '', trim($line));
                if (mb_strlen($quote) < 10) continue;

                $bank[] = [
                    'date' => $date->format('Y-m-d'),
                    'quote' => $quote,
                    'status' => 'pending',
                ];
                $date->modify('+1 day');
            }
        }

        return $bank;
    }

    /**
     * Generate a single replacement quote via OpenAI.
     */
    public function generateSingleQuote(string $customPrompt = ''): ?string
    {
        if (empty($customPrompt)) {
            $customPrompt = qa_opt(SmpConstants::OPT_QUOTE_PROMPT);
        }
        if (empty($customPrompt)) {
            $customPrompt = 'Generate motivational quotes suitable for students preparing for competitive exams like GATE CSE.';
        }

        $systemPrompt = $customPrompt . "\n\n"
            . "Return EXACTLY one motivational quote with attribution. Format nicely for social media. Do NOT use any emoji characters. "
            . "Add relevant hashtags like #QuoteOfTheDay #Motivation. "
            . "Do NOT include any numbering, headers, or explanations — just the single quote.";

        $response = $this->callOpenAI($systemPrompt, 'Generate one unique motivational quote.', 400);
        if (empty($response)) {
            return null;
        }

        $quote = preg_replace('/^\d+[\.\)]\s*/', '', trim($response));
        return mb_strlen($quote) >= 10 ? $quote : null;
    }

    /**
     * Low-level OpenAI chat completion call.
     */
    private function callOpenAI(string $systemPrompt, string $userMessage, int $maxTokens = 300): ?string
    {
        $apiKey = qa_opt(SmpConstants::OPT_OPENAI_KEY);
        if (empty($apiKey)) {
            return null;
        }

        $url = 'https://api.openai.com/v1/chat/completions';
        $data = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'max_tokens' => $maxTokens,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $response === false) {
            return null;
        }

        $result = json_decode($response, true);
        return $result['choices'][0]['message']['content'] ?? null;
    }

    /**
     * Get the saved quote bank from the smp_quotes table.
     */
    public function getQuoteBank(): array
    {
        $result = qa_db_query_sub(
            'SELECT `id`, `quote_date` AS `date`, `quote_text` AS `quote`, `status` FROM ^smp_quotes ORDER BY `quote_date` ASC'
        );
        $bank = [];
        while ($row = qa_db_read_one_assoc($result, true)) {
            $bank[] = $row;
        }
        return $bank;
    }

    /**
     * Save an entire quote bank to the smp_quotes table (replaces all existing).
     */
    public function saveQuoteBank(array $bank): void
    {
        qa_db_query_sub('DELETE FROM ^smp_quotes');
        foreach ($bank as $entry) {
            qa_db_query_sub(
                'INSERT INTO ^smp_quotes (`quote_date`, `quote_text`, `status`) VALUES ($, $, $)',
                $entry['date'],
                $entry['quote'],
                $entry['status'] ?? 'pending'
            );
        }
    }

    /**
     * Replace a single quote by its DB id.
     */
    public function replaceQuoteById(int $id, string $newQuote): void
    {
        qa_db_query_sub(
            'UPDATE ^smp_quotes SET `quote_text` = $, `status` = \'pending\' WHERE `id` = #',
            $newQuote,
            $id
        );
    }

    /**
     * Edit a single quote's text by its DB id.
     */
    public function editQuoteById(int $id, string $newText): void
    {
        qa_db_query_sub(
            'UPDATE ^smp_quotes SET `quote_text` = $ WHERE `id` = #',
            $newText,
            $id
        );
    }

    /**
     * Get today's quote from the bank. Returns null if not found.
     */
    public function getTodayQuote(): ?string
    {
        $today = date('Y-m-d');
        $result = qa_db_query_sub(
            'SELECT `quote_text` FROM ^smp_quotes WHERE `quote_date` = $ AND `status` = \'pending\' LIMIT 1',
            $today
        );
        $row = qa_db_read_one_assoc($result, true);
        return $row ? $row['quote_text'] : null;
    }

    /**
     * Mark today's quote as posted.
     */
    public function markQuotePosted(string $date): void
    {
        qa_db_query_sub(
            'UPDATE ^smp_quotes SET `status` = \'posted\' WHERE `quote_date` = $',
            $date
        );
    }

    /**
     * Call OpenAI to generate a social media message.
     */
    public function openaiGenerateMessage(string $content, string $systemPrompt = ''): string
    {
        if (empty($systemPrompt)) {
            $systemPrompt = qa_opt(SmpConstants::OPT_OPENAI_CONFIG);
        }
        if (empty($systemPrompt)) {
            $systemPrompt = 'Create a short social media announcement for the following content. Keep it engaging and concise.';
        }

        $result = $this->callOpenAI($systemPrompt, $content, 300);
        return $result ?? $content;
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
     * Send a posting summary email (success + failures) to the Q2A admin.
     */
    public function reportPostingSummary(string $contentLabel, array $results, string $extraInfo = ''): void
    {
        if (!function_exists('qa_send_email')) {
            return;
        }

        $email = self::getAdminEmail();
        if (empty($email)) {
            return;
        }

        $successList = [];
        $failList = [];
        foreach ($results as $accountId => $result) {
            $accountName = $result['account_name'] ?? $accountId;
            $platform = $result['platform'] ?? 'unknown';
            $label = $platform . ' (' . $accountName . ')';
            if (!empty($result['success'])) {
                $successList[] = $label;
            } else {
                $failList[] = $label . ' — ' . ($result['error'] ?? 'Unknown error');
            }
        }

        $totalSuccess = count($successList);
        $totalFail = count($failList);
        $total = $totalSuccess + $totalFail;

        if ($total === 0) {
            return;
        }

        $allOk = ($totalFail === 0);
        $statusTag = $allOk ? 'SUCCESS' : ($totalSuccess > 0 ? 'PARTIAL' : 'FAILED');
        $subject = '[SMP] ' . $statusTag . ': ' . $contentLabel;

        $body = 'Social Media Poster — ' . $contentLabel . "\n"
            . str_repeat('=', 50) . "\n\n"
            . 'Time: ' . date('Y-m-d H:i:s') . "\n"
            . 'Site: ' . qa_opt('site_url') . "\n\n";

        if (!empty($extraInfo)) {
            $body .= $extraInfo . "\n\n";
        }

        $body .= 'Results: ' . $totalSuccess . '/' . $total . ' succeeded' . "\n\n";

        if (!empty($successList)) {
            $body .= 'Successful posts:' . "\n";
            foreach ($successList as $s) {
                $body .= '  [OK] ' . $s . "\n";
            }
            $body .= "\n";
        }

        if (!empty($failList)) {
            $body .= 'Failed posts:' . "\n";
            foreach ($failList as $f) {
                $body .= '  [FAIL] ' . $f . "\n";
            }
            $body .= "\n";
        }

        qa_send_email([
            'fromemail' => qa_opt('from_email'),
            'fromname' => qa_opt('site_name'),
            'replytoemail' => qa_opt('from_email'),
            'replytoname' => qa_opt('site_name') . ' (Do Not Reply)',
            'toemail' => $email,
            'toname' => 'Admin',
            'subject' => $subject,
            'body' => $body,
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
                    if ($detected === 'invalid') {
                        $account['token_expiry_date'] = '';
                        $account['token_expiry_source'] = 'invalid';
                    } else {
                        $account['token_expiry_date'] = $detected;
                        $account['token_expiry_source'] = 'auto';
                    }
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
                // If OAuth credentials are available, try a refresh to validate
                if (!empty($creds['client_id']) && !empty($creds['client_secret']) && !empty($creds['refresh_token'])) {
                    $result = $this->refreshLinkedInAccessToken($creds['client_id'], $creds['client_secret'], $creds['refresh_token']);
                    if ($result['success']) {
                        $expiresIn = $result['expires_in'] ?? 5184000;
                        return date('Y-m-d', time() + (int)$expiresIn);
                    }
                    return 'invalid';
                }
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
            return 'invalid';
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

    /**
     * Refresh a Meta (Facebook/Instagram/WhatsApp) short-lived token
     * to a long-lived token via the Graph API.
     *
     * @param string $accessToken Current access token
     * @param string $appId Facebook App ID (optional, extracted from token debug if empty)
     * @param string $appSecret Facebook App Secret
     * @return array ['success' => bool, 'access_token' => string, 'expires_in' => int, 'error' => string]
     */
    public function refreshMetaToken(string $accessToken, string $appId, string $appSecret): array
    {
        if (empty($accessToken) || empty($appSecret)) {
            return ['success' => false, 'error' => 'Missing access token or app secret'];
        }

        $url = 'https://graph.facebook.com/v21.0/oauth/access_token?' . http_build_query([
            'grant_type' => 'fb_exchange_token',
            'client_id' => $appId,
            'client_secret' => $appSecret,
            'fb_exchange_token' => $accessToken,
        ]);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => 'cURL error: ' . $error];
        }

        $data = json_decode($response, true);
        if (!empty($data['access_token'])) {
            return [
                'success' => true,
                'access_token' => $data['access_token'],
                'expires_in' => $data['expires_in'] ?? 5184000, // ~60 days
                'token_type' => $data['token_type'] ?? 'bearer',
            ];
        }

        $errMsg = $data['error']['message'] ?? $response;
        return ['success' => false, 'error' => 'Token exchange failed: ' . $errMsg];
    }

    /**
     * Auto-refresh tokens for all enabled accounts.
     * Called by SmpTokenChecker. Delegates to platform-specific methods.
     *
     * @return array Summary of refresh results per account
     */
    public function autoRefreshTokens(): array
    {
        $results = array_merge(
            $this->autoRefreshMetaTokens(),
            $this->autoRefreshGoogleTokens(),
            $this->autoRefreshLinkedInTokens()
        );

        qa_opt(SmpConstants::OPT_LAST_TOKEN_REFRESH, date('Y-m-d H:i:s'));

        return $results;
    }

    /**
     * Auto-refresh Meta tokens (Facebook, Instagram, WhatsApp) when they
     * are within 7 days of expiry.
     *
     * @return array Summary of refresh results per account
     */
    public function autoRefreshMetaTokens(): array
    {
        $appId = qa_opt('smp_meta_app_id');
        $appSecret = qa_opt('smp_meta_app_secret');
        $results = [];
        $hasMetaCreds = !empty($appId) && !empty($appSecret);

        if ($hasMetaCreds) {
            $metaPlatforms = [
                SmpConstants::PLATFORM_FACEBOOK => 'page_access_token',
                SmpConstants::PLATFORM_INSTAGRAM => 'access_token',
                SmpConstants::PLATFORM_WHATSAPP => 'access_token',
            ];

            foreach ($metaPlatforms as $platformId => $tokenField) {
                $accounts = $this->getAccounts($platformId);
                $accountsChanged = false;

                foreach ($accounts as $idx => &$account) {
                    if (empty($account['enabled'])) {
                        continue;
                    }

                    $currentToken = $account['credentials'][$tokenField] ?? '';
                    if (empty($currentToken)) {
                        continue;
                    }

                    $accountName = $account['name'] ?? ('Account ' . ($idx + 1));
                    $expiryDate = $account['token_expiry_date'] ?? '';

                    // Only refresh if expiry is within 7 days or unknown
                    $shouldRefresh = empty($expiryDate);
                    if (!empty($expiryDate)) {
                        try {
                            $expiry = new DateTime($expiryDate);
                            $today = new DateTime('today');
                            $diff = $today->diff($expiry);
                            $daysLeft = $diff->invert ? -$diff->days : $diff->days;
                            $shouldRefresh = ($daysLeft <= 7);
                        } catch (Exception $e) {
                            $shouldRefresh = true;
                        }
                    }

                    if (!$shouldRefresh) {
                        $results[$platformId . '_' . $idx] = [
                            'platform' => $platformId,
                            'account' => $accountName,
                            'status' => 'skipped',
                            'reason' => 'Token not near expiry (expires: ' . $expiryDate . ')',
                        ];
                        continue;
                    }

                    $refreshResult = $this->refreshMetaToken($currentToken, $appId, $appSecret);

                    if ($refreshResult['success']) {
                        $account['credentials'][$tokenField] = $refreshResult['access_token'];
                        $expiresIn = $refreshResult['expires_in'] ?? 5184000;
                        $account['token_expiry_date'] = date('Y-m-d', time() + $expiresIn);
                        $account['token_expiry_source'] = 'auto-refresh';
                        $account['token_last_refreshed'] = date('Y-m-d H:i:s');
                        $accountsChanged = true;

                        $results[$platformId . '_' . $idx] = [
                            'platform' => $platformId,
                            'account' => $accountName,
                            'status' => 'refreshed',
                            'new_expiry' => $account['token_expiry_date'],
                        ];
                    } else {
                        $results[$platformId . '_' . $idx] = [
                            'platform' => $platformId,
                            'account' => $accountName,
                            'status' => 'failed',
                            'error' => $refreshResult['error'],
                        ];
                    }
                }
                unset($account);

                if ($accountsChanged) {
                    $this->saveAccounts($platformId, $accounts);
                }
            }
        } else {
            // Check if any Meta accounts exist that would need credentials
            foreach ([SmpConstants::PLATFORM_FACEBOOK, SmpConstants::PLATFORM_INSTAGRAM, SmpConstants::PLATFORM_WHATSAPP] as $mp) {
                if (!empty($this->getEnabledAccounts($mp))) {
                    $results['_meta_warning'] = 'Meta App ID/Secret not configured — cannot auto-refresh Facebook/Instagram/WhatsApp tokens';
                    break;
                }
            }
        }

        return $results;
    }

    /**
     * Validate Google/YouTube refresh tokens by attempting an access token refresh.
     * Marks tokens as 'invalid' if the refresh token is revoked or broken.
     *
     * @return array Summary of refresh results per account
     */
    public function autoRefreshGoogleTokens(): array
    {
        $results = [];
        $ytAccounts = $this->getAccounts(SmpConstants::PLATFORM_YOUTUBE);
        $ytChanged = false;

        foreach ($ytAccounts as $idx => &$ytAccount) {
            if (empty($ytAccount['enabled'])) {
                continue;
            }

            $creds = $ytAccount['credentials'] ?? [];
            $clientId = $creds['client_id'] ?? '';
            $clientSecret = $creds['client_secret'] ?? '';
            $refreshToken = $creds['refresh_token'] ?? '';
            $accountName = $ytAccount['name'] ?? ('YouTube Account ' . ($idx + 1));

            if (empty($clientId) || empty($clientSecret) || empty($refreshToken)) {
                $results['youtube_' . $idx] = [
                    'platform' => 'youtube',
                    'account' => $accountName,
                    'status' => 'skipped',
                    'reason' => 'Missing credentials',
                ];
                continue;
            }

            $refreshResult = $this->refreshGoogleAccessToken($clientId, $clientSecret, $refreshToken);

            if ($refreshResult['success']) {
                $ytAccount['token_expiry_date'] = null; // Refresh token doesn't expire
                $ytAccount['token_expiry_source'] = 'none';
                $ytAccount['token_last_refreshed'] = date('Y-m-d H:i:s');
                $ytChanged = true;

                $results['youtube_' . $idx] = [
                    'platform' => 'youtube',
                    'account' => $accountName,
                    'status' => 'valid',
                    'reason' => 'Refresh token is working (access token obtained)',
                ];
            } else {
                // Refresh token is revoked/broken — mark as invalid
                $ytAccount['token_expiry_source'] = 'invalid';
                $ytAccount['token_last_refreshed'] = date('Y-m-d H:i:s');
                $ytChanged = true;

                $results['youtube_' . $idx] = [
                    'platform' => 'youtube',
                    'account' => $accountName,
                    'status' => 'failed',
                    'error' => $refreshResult['error'],
                ];
            }
        }
        unset($ytAccount);

        if ($ytChanged) {
            $this->saveAccounts(SmpConstants::PLATFORM_YOUTUBE, $ytAccounts);
        }

        return $results;
    }

    /**
     * Auto-refresh LinkedIn tokens when they are within 7 days of expiry.
     *
     * @return array Summary of refresh results per account
     */
    public function autoRefreshLinkedInTokens(): array
    {
        $results = [];
        $liAccounts = $this->getAccounts(SmpConstants::PLATFORM_LINKEDIN);
        $liChanged = false;

        foreach ($liAccounts as $idx => &$liAccount) {
            if (empty($liAccount['enabled'])) {
                continue;
            }

            $creds = $liAccount['credentials'] ?? [];
            $clientId = $creds['client_id'] ?? '';
            $clientSecret = $creds['client_secret'] ?? '';
            $refreshToken = $creds['refresh_token'] ?? '';
            $accessToken = $creds['access_token'] ?? '';
            $accountName = $liAccount['name'] ?? ('LinkedIn Account ' . ($idx + 1));

            // No refresh_token: validate the access_token by probing
            if (empty($refreshToken)) {
                if (empty($accessToken)) {
                    $results['linkedin_' . $idx] = [
                        'platform' => 'linkedin',
                        'account' => $accountName,
                        'status' => 'skipped',
                        'reason' => 'No access token or refresh token available',
                    ];
                    continue;
                }
                $probeResult = $this->probeLinkedInTokenExpiry($accessToken);
                $liAccount['token_last_refreshed'] = date('Y-m-d H:i:s');
                $liChanged = true;
                if ($probeResult === null) {
                    // Token is valid, estimate expiry from stored date or set unknown
                    $liAccount['token_expiry_source'] = 'probe';
                    $results['linkedin_' . $idx] = [
                        'platform' => 'linkedin',
                        'account' => $accountName,
                        'status' => 'valid',
                        'reason' => 'Access token is valid (no refresh token available)',
                    ];
                } else {
                    $liAccount['token_expiry_source'] = 'invalid';
                    $liAccount['token_expiry_date'] = $probeResult;
                    $results['linkedin_' . $idx] = [
                        'platform' => 'linkedin',
                        'account' => $accountName,
                        'status' => 'failed',
                        'error' => 'Access token expired or invalid — re-authenticate via OAuth',
                    ];
                }
                continue;
            }

            if (empty($clientId) || empty($clientSecret)) {
                $results['linkedin_' . $idx] = [
                    'platform' => 'linkedin',
                    'account' => $accountName,
                    'status' => 'skipped',
                    'reason' => 'Missing client_id or client_secret for refresh',
                ];
                continue;
            }

            $expiryDate = $liAccount['token_expiry_date'] ?? '';

            // Only refresh if expiry is within 7 days or unknown
            $shouldRefresh = empty($expiryDate);
            if (!empty($expiryDate)) {
                try {
                    $expiry = new DateTime($expiryDate);
                    $today = new DateTime('today');
                    $diff = $today->diff($expiry);
                    $daysLeft = $diff->invert ? -$diff->days : $diff->days;
                    $shouldRefresh = ($daysLeft <= 7);
                } catch (Exception $e) {
                    $shouldRefresh = true;
                }
            }

            if (!$shouldRefresh) {
                $results['linkedin_' . $idx] = [
                    'platform' => 'linkedin',
                    'account' => $accountName,
                    'status' => 'skipped',
                    'reason' => 'Token not near expiry (expires: ' . $expiryDate . ')',
                ];
                continue;
            }

            $refreshResult = $this->refreshLinkedInAccessToken($clientId, $clientSecret, $refreshToken);

            if ($refreshResult['success']) {
                $liAccount['credentials']['access_token'] = $refreshResult['access_token'];
                // Update refresh_token if LinkedIn issued a new one
                if (!empty($refreshResult['refresh_token'])) {
                    $liAccount['credentials']['refresh_token'] = $refreshResult['refresh_token'];
                }
                $expiresIn = $refreshResult['expires_in'] ?? 5184000;
                $liAccount['token_expiry_date'] = date('Y-m-d', time() + (int)$expiresIn);
                $liAccount['token_expiry_source'] = 'auto-refresh';
                $liAccount['token_last_refreshed'] = date('Y-m-d H:i:s');
                $liChanged = true;

                $results['linkedin_' . $idx] = [
                    'platform' => 'linkedin',
                    'account' => $accountName,
                    'status' => 'refreshed',
                    'new_expiry' => $liAccount['token_expiry_date'],
                ];
            } else {
                $liAccount['token_expiry_source'] = 'invalid';
                $liAccount['token_last_refreshed'] = date('Y-m-d H:i:s');
                $liChanged = true;

                $results['linkedin_' . $idx] = [
                    'platform' => 'linkedin',
                    'account' => $accountName,
                    'status' => 'failed',
                    'error' => $refreshResult['error'],
                ];
            }
        }
        unset($liAccount);

        if ($liChanged) {
            $this->saveAccounts(SmpConstants::PLATFORM_LINKEDIN, $liAccounts);
        }

        return $results;
    }

    // ==================== Per-User Account Methods ====================

    private const USER_META_ACCOUNTS_PREFIX = 'smp_accounts_';
    private const USER_META_SHARING_ENABLED = 'smp_sharing_enabled';

    /**
     * Get a user's social media accounts for a specific platform.
     */
    public function getUserAccounts(int $userId, string $platform): array
    {
        $metaKey = self::USER_META_ACCOUNTS_PREFIX . $platform;
        $json = qa_db_read_one_value(qa_db_query_sub(
            "SELECT content FROM ^usermetas WHERE userid = # AND title = $",
            $userId, $metaKey
        ), true);

        if (empty($json)) {
            return [];
        }
        $accounts = json_decode($json, true);
        return is_array($accounts) ? $accounts : [];
    }

    /**
     * Save a user's social media accounts for a specific platform.
     */
    public function saveUserAccounts(int $userId, string $platform, array $accounts): void
    {
        $metaKey = self::USER_META_ACCOUNTS_PREFIX . $platform;
        $json = json_encode(array_values($accounts));

        qa_db_query_sub(
            "INSERT INTO ^usermetas (userid, title, content) VALUES (#, $, $) ON DUPLICATE KEY UPDATE content = $",
            $userId, $metaKey, $json, $json
        );
    }

    /**
     * Check if a user has social sharing enabled.
     */
    public function getUserSharingEnabled(int $userId): bool
    {
        $val = qa_db_read_one_value(qa_db_query_sub(
            "SELECT content FROM ^usermetas WHERE userid = # AND title = $",
            $userId, self::USER_META_SHARING_ENABLED
        ), true);

        return $val === '1';
    }

    /**
     * Set user's sharing enabled/disabled.
     */
    public function setUserSharingEnabled(int $userId, bool $enabled): void
    {
        $val = $enabled ? '1' : '0';
        qa_db_query_sub(
            "INSERT INTO ^usermetas (userid, title, content) VALUES (#, $, $) ON DUPLICATE KEY UPDATE content = $",
            $userId, self::USER_META_SHARING_ENABLED, $val, $val
        );
    }

    /**
     * Get ALL enabled accounts for a user across all platforms.
     * Returns array keyed by account ID with _platform set.
     */
    public function getUserAccountsForPosting(int $userId): array
    {
        if (!$this->getUserSharingEnabled($userId)) {
            return [];
        }

        $result = [];
        $platforms = SmpConstants::getPlatforms();

        foreach (array_keys($platforms) as $platform) {
            $accounts = $this->getUserAccounts($userId, $platform);
            foreach ($accounts as $account) {
                if (!empty($account['enabled']) && !empty($account['id'])) {
                    $account['_platform'] = $platform;
                    $result[$account['id']] = $account;
                }
            }
        }

        return $result;
    }
}
