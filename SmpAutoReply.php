<?php

/**
 * Auto-reply bot for social media platforms.
 * Checks Telegram and Facebook for new replies/comments, uses Gemini API to:
 *   1. Detect and remove spam
 *   2. Reply if confident of the answer
 *   3. Email admin for replies needing human attention
 *
 * Runs on a configurable interval (default 12 hours) via cron or page load.
 */

require_once dirname(__FILE__) . '/SmpConstants.php';

class SmpAutoReply
{
    // Option keys
    const OPT_ENABLED = 'smp_autoreply_enabled';
    const OPT_INTERVAL = 'smp_autoreply_interval'; // hours
    const OPT_LAST_RUN = 'smp_autoreply_last_run';
    const OPT_GEMINI_MODEL = 'smp_autoreply_gemini_model';
    const OPT_SYSTEM_PROMPT = 'smp_autoreply_system_prompt';
    const OPT_TG_LAST_UPDATE_ID = 'smp_autoreply_tg_last_update';
    const OPT_FB_LAST_CHECK = 'smp_autoreply_fb_last_check';
    const OPT_SPAM_ACTION = 'smp_autoreply_spam_action'; // delete or hide
    const OPT_LOG_ENABLED = 'smp_autoreply_log';

    private string $directory;
    private array $log = [];

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function init_queries($tableslc)
    {
        $this->runIfDue();
        return [];
    }

    /**
     * Check if it's time to run and execute if so.
     */
    public function runIfDue(): void
    {
        if (!qa_opt(self::OPT_ENABLED)) return;

        $interval = max(1, (int)(qa_opt(self::OPT_INTERVAL) ?: 12));
        $lastRun = qa_opt(self::OPT_LAST_RUN);
        $now = time();

        if ($lastRun && ($now - strtotime($lastRun)) < $interval * 3600) {
            return; // Not due yet
        }

        // Lock immediately to prevent double-run
        qa_opt(self::OPT_LAST_RUN, date('Y-m-d H:i:s', $now));

        $this->processAllPlatforms();
    }

    /**
     * Force run (called from cron).
     */
    public function runNow(): void
    {
        if (!qa_opt(self::OPT_ENABLED)) return;
        qa_opt(self::OPT_LAST_RUN, date('Y-m-d H:i:s'));
        $this->processAllPlatforms();
    }

    /**
     * Process replies/comments on all configured platforms.
     */
    private function processAllPlatforms(): void
    {
        $this->log = [];

        // Process Telegram accounts
        $tgAccounts = $this->getAccountsByPlatform('telegram');
        foreach ($tgAccounts as $account) {
            $this->processTelegram($account);
        }

        // Process Facebook accounts
        $fbAccounts = $this->getAccountsByPlatform('facebook');
        foreach ($fbAccounts as $account) {
            $this->processFacebook($account);
        }

        // Send summary to admin if there were actions
        if (!empty($this->log) && qa_opt(self::OPT_LOG_ENABLED)) {
            $this->sendLogEmail();
        }
    }

    // ==================== TELEGRAM ====================

    private function processTelegram(array $account): void
    {
        $botToken = $account['credentials']['bot_token'] ?? '';
        $chatId = $account['credentials']['chat_id'] ?? '';
        if (empty($botToken) || empty($chatId)) return;

        $lastUpdateId = (int)(qa_opt(self::OPT_TG_LAST_UPDATE_ID . '_' . $account['id']) ?: 0);

        // Fetch new updates
        $updates = $this->telegramGetUpdates($botToken, $lastUpdateId + 1);
        if (empty($updates)) return;

        $maxUpdateId = $lastUpdateId;

        foreach ($updates as $update) {
            $updateId = $update['update_id'];
            if ($updateId > $maxUpdateId) $maxUpdateId = $updateId;

            $message = $update['message'] ?? $update['channel_post'] ?? null;
            if (!$message) continue;

            // Only process messages in our configured chat
            $msgChatId = (string)($message['chat']['id'] ?? '');
            if ($msgChatId !== (string)$chatId) continue;

            // Skip our own bot messages
            if (isset($message['from']['is_bot']) && $message['from']['is_bot']) continue;

            $text = $message['text'] ?? $message['caption'] ?? '';
            if (empty(trim($text))) continue;

            $msgId = $message['message_id'];
            $from = $message['from']['first_name'] ?? 'Unknown';
            $replyTo = $message['reply_to_message'] ?? null;

            // Only process replies to our posts, or new messages in groups
            $isReply = ($replyTo !== null);

            $this->log("TG [{$account['name']}]: Message from $from: " . mb_substr($text, 0, 100));

            // Analyze with Gemini
            $analysis = $this->analyzeMessage($text, 'telegram', $from, $isReply ? ($replyTo['text'] ?? '') : '');

            if ($analysis['spam']) {
                // Delete spam
                $this->telegramDeleteMessage($botToken, $chatId, $msgId);
                $this->log("TG [{$account['name']}]: SPAM deleted from $from: " . mb_substr($text, 0, 60));
            } elseif ($analysis['can_reply'] && !empty($analysis['reply'])) {
                // Send reply
                $this->telegramSendReply($botToken, $chatId, $msgId, $analysis['reply']);
                $this->log("TG [{$account['name']}]: Replied to $from");
            } elseif ($analysis['needs_human']) {
                // Email admin
                $this->emailAdminForReply(
                    'Telegram',
                    $account['name'],
                    $from,
                    $text,
                    $analysis['reason'] ?? 'Needs human review'
                );
                $this->log("TG [{$account['name']}]: Emailed admin about message from $from");
            }
        }

        qa_opt(self::OPT_TG_LAST_UPDATE_ID . '_' . $account['id'], $maxUpdateId);
    }

    private function telegramGetUpdates(string $botToken, int $offset): array
    {
        $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/getUpdates";
        $params = [
            'offset' => $offset,
            'limit' => 100,
            'timeout' => 10,
        ];

        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) return [];

        $data = json_decode($response, true);
        return ($data['ok'] ?? false) ? ($data['result'] ?? []) : [];
    }

    private function telegramDeleteMessage(string $botToken, string $chatId, int $messageId): bool
    {
        $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/deleteMessage";
        $response = $this->httpPost($url, [
            'chat_id' => $chatId,
            'message_id' => $messageId,
        ]);
        $data = json_decode($response, true);
        return $data['ok'] ?? false;
    }

    private function telegramSendReply(string $botToken, string $chatId, int $replyToMsgId, string $text): bool
    {
        $url = "https://api.telegram.org/bot" . urlencode($botToken) . "/sendMessage";
        $response = $this->httpPost($url, [
            'chat_id' => $chatId,
            'text' => $text,
            'reply_to_message_id' => $replyToMsgId,
            'parse_mode' => 'HTML',
        ]);
        $data = json_decode($response, true);
        return $data['ok'] ?? false;
    }

    // ==================== FACEBOOK ====================

    private function processFacebook(array $account): void
    {
        $pageToken = $account['credentials']['page_access_token'] ?? '';
        $pageId = $account['credentials']['page_id'] ?? '';
        if (empty($pageToken) || empty($pageId)) return;

        $lastCheck = qa_opt(self::OPT_FB_LAST_CHECK . '_' . $account['id']) ?: date('Y-m-d\TH:i:sO', strtotime('-1 day'));

        // Fetch recent page posts (last 7 days)
        $posts = $this->facebookGetRecentPosts($pageToken, $pageId);
        if (empty($posts)) return;

        $processedCount = 0;

        foreach ($posts as $post) {
            $postId = $post['id'] ?? '';
            if (empty($postId)) continue;

            // Fetch comments on this post
            $comments = $this->facebookGetComments($pageToken, $postId, $lastCheck);

            foreach ($comments as $comment) {
                $commentId = $comment['id'] ?? '';
                $text = $comment['message'] ?? '';
                $from = $comment['from']['name'] ?? 'Unknown';
                $fromId = $comment['from']['id'] ?? '';

                // Skip our own page comments
                if ($fromId === $pageId) continue;
                if (empty(trim($text))) continue;

                $this->log("FB [{$account['name']}]: Comment from $from: " . mb_substr($text, 0, 100));

                $postText = $post['message'] ?? '';
                $analysis = $this->analyzeMessage($text, 'facebook', $from, $postText);

                if ($analysis['spam']) {
                    $this->facebookHideComment($pageToken, $commentId);
                    $this->log("FB [{$account['name']}]: SPAM hidden from $from");
                } elseif ($analysis['can_reply'] && !empty($analysis['reply'])) {
                    $this->facebookReplyToComment($pageToken, $commentId, $analysis['reply']);
                    $this->log("FB [{$account['name']}]: Replied to $from");
                    $processedCount++;
                } elseif ($analysis['needs_human']) {
                    $this->emailAdminForReply(
                        'Facebook',
                        $account['name'],
                        $from,
                        $text,
                        $analysis['reason'] ?? 'Needs human review'
                    );
                    $this->log("FB [{$account['name']}]: Emailed admin about comment from $from");
                }
            }
        }

        qa_opt(self::OPT_FB_LAST_CHECK . '_' . $account['id'], date('Y-m-d\TH:i:sO'));
    }

    private function facebookGetRecentPosts(string $pageToken, string $pageId): array
    {
        $url = "https://graph.facebook.com/v21.0/" . urlencode($pageId) . "/posts";
        $params = [
            'access_token' => $pageToken,
            'fields' => 'id,message,created_time',
            'since' => strtotime('-7 days'),
            'limit' => 50,
        ];
        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) return [];
        $data = json_decode($response, true);
        return $data['data'] ?? [];
    }

    private function facebookGetComments(string $pageToken, string $postId, string $since): array
    {
        $url = "https://graph.facebook.com/v21.0/" . urlencode($postId) . "/comments";
        $params = [
            'access_token' => $pageToken,
            'fields' => 'id,message,from,created_time',
            'since' => strtotime($since),
            'limit' => 100,
            'order' => 'chronological',
        ];
        $response = $this->httpGet($url . '?' . http_build_query($params));
        if (!$response) return [];
        $data = json_decode($response, true);
        return $data['data'] ?? [];
    }

    private function facebookHideComment(string $pageToken, string $commentId): bool
    {
        $url = "https://graph.facebook.com/v21.0/" . urlencode($commentId);
        $response = $this->httpPost($url, [
            'access_token' => $pageToken,
            'is_hidden' => 'true',
        ]);
        $data = json_decode($response, true);
        return $data['success'] ?? false;
    }

    private function facebookReplyToComment(string $pageToken, string $commentId, string $reply): bool
    {
        $url = "https://graph.facebook.com/v21.0/" . urlencode($commentId) . "/comments";
        $response = $this->httpPost($url, [
            'access_token' => $pageToken,
            'message' => $reply,
        ]);
        $data = json_decode($response, true);
        return isset($data['id']);
    }

    // ==================== GEMINI ANALYSIS ====================

    /**
     * Analyze a message using Gemini API.
     * Returns: ['spam' => bool, 'can_reply' => bool, 'reply' => string, 'needs_human' => bool, 'reason' => string]
     */
    private function analyzeMessage(string $text, string $platform, string $authorName, string $contextText = ''): array
    {
        $default = ['spam' => false, 'can_reply' => false, 'reply' => '', 'needs_human' => false, 'reason' => ''];

        $apiKey = qa_opt('openai_gemini_api_key');
        if (empty($apiKey)) return $default;

        $model = qa_opt(self::OPT_GEMINI_MODEL) ?: 'gemini-2.5-flash';
        $siteName = qa_opt('site_name') ?: 'our site';
        $siteUrl = qa_opt('site_url') ?: '';

        $customPrompt = qa_opt(self::OPT_SYSTEM_PROMPT) ?: '';

        $systemContext = "You are a moderator and assistant for \"$siteName\" ($siteUrl), "
            . "a Q&A community for GATE and other competitive exams in Computer Science and Engineering.\n\n"
            . "You are reviewing a message posted on the {$platform} social media account.\n\n"
            . "Analyze the message and respond with a JSON object:\n"
            . "{\n"
            . "  \"spam\": true/false,    // Is this spam, advertising, scam, or abuse?\n"
            . "  \"can_reply\": true/false, // Can you confidently provide a helpful reply?\n"
            . "  \"reply\": \"...\",       // Your reply text (only if can_reply is true). Keep it concise, helpful, and friendly.\n"
            . "  \"needs_human\": true/false, // Does this need a human moderator's attention?\n"
            . "  \"reason\": \"...\"        // Brief reason for your classification\n"
            . "}\n\n"
            . "Rules:\n"
            . "- Mark as spam: promotional links, scams, irrelevant ads, abusive content, crypto/forex spam\n"
            . "- Reply confidently only for: questions about the site, exam preparation queries you're certain about, "
            . "greetings, thank-you messages, simple factual CS/Engineering questions\n"
            . "- Mark needs_human for: complaints, complex questions you're unsure about, feedback, "
            . "questions about specific exam results, technical issues\n"
            . "- If the message is just a reaction (emoji, 'nice', 'thanks', etc.), set can_reply=false and needs_human=false\n"
            . "- Replies should be in the same language as the message\n"
            . "- Do NOT hallucinate answers. If unsure, set needs_human=true\n";

        if ($customPrompt) {
            $systemContext .= "\nAdditional instructions:\n" . $customPrompt . "\n";
        }

        $userMessage = "Platform: $platform\n"
            . "Author: $authorName\n";
        if ($contextText) {
            $userMessage .= "Original post/context: " . mb_substr($contextText, 0, 500) . "\n";
        }
        $userMessage .= "Message to analyze: $text\n\n"
            . "Respond with only the JSON object.";

        $result = $this->callGemini($apiKey, $model, $systemContext, $userMessage);
        if (!$result) return $default;

        return [
            'spam' => !empty($result['spam']),
            'can_reply' => !empty($result['can_reply']),
            'reply' => $result['reply'] ?? '',
            'needs_human' => !empty($result['needs_human']),
            'reason' => $result['reason'] ?? '',
        ];
    }

    private function callGemini(string $apiKey, string $model, string $systemPrompt, string $userMessage): ?array
    {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $data = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userMessage]]]],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 2000,
            ],
        ];

        $response = $this->httpPost($url, $data, true);
        if (!$response) return null;

        $decoded = json_decode($response, true);
        $text = $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';

        // Strip markdown wrapper
        $text = preg_replace('/^```json\s*/', '', trim($text));
        $text = preg_replace('/\s*```$/', '', $text);

        return json_decode($text, true);
    }

    // ==================== EMAIL NOTIFICATION ====================

    private function emailAdminForReply(string $platform, string $accountName, string $authorName, string $messageText, string $reason): void
    {
        if (!function_exists('qa_send_email')) return;

        $email = SmpPoster::getAdminEmail();
        if (empty($email)) return;

        $siteName = qa_opt('site_name') ?: 'Q2A Site';

        qa_send_email([
            'fromemail' => qa_opt('from_email'),
            'fromname' => $siteName,
            'replytoemail' => qa_opt('from_email'),
            'replytoname' => $siteName . ' (Do Not Reply)',
            'toemail' => $email,
            'toname' => 'Admin',
            'subject' => "[SMP Bot] Reply needed on $platform ($accountName)",
            'body' => "A message on $platform needs your attention.\n\n"
                . "Account: $accountName\n"
                . "Platform: $platform\n"
                . "Author: $authorName\n"
                . "Time: " . date('Y-m-d H:i:s') . "\n\n"
                . "Message:\n" . $messageText . "\n\n"
                . "Reason: $reason\n\n"
                . "Please check your $platform account and reply manually.",
            'html' => false,
        ]);
    }

    private function sendLogEmail(): void
    {
        if (!function_exists('qa_send_email')) return;

        $email = SmpPoster::getAdminEmail();
        if (empty($email)) return;

        $siteName = qa_opt('site_name') ?: 'Q2A Site';
        $logText = implode("\n", $this->log);

        qa_send_email([
            'fromemail' => qa_opt('from_email'),
            'fromname' => $siteName,
            'replytoemail' => qa_opt('from_email'),
            'replytoname' => $siteName . ' (Do Not Reply)',
            'toemail' => $email,
            'toname' => 'Admin',
            'subject' => '[SMP Bot] Auto-reply run summary',
            'body' => "Auto-reply bot run at " . date('Y-m-d H:i:s') . "\n\n"
                . "Actions taken:\n" . $logText . "\n\n"
                . "Total actions: " . count($this->log),
            'html' => false,
        ]);
    }

    // ==================== HELPERS ====================

    private function getAccountsByPlatform(string $platform): array
    {
        require_once $this->directory . 'SmpPoster.php';
        $poster = new SmpPoster($this->directory);

        $allAccounts = $poster->getAllAccountsById();
        $result = [];
        foreach ($allAccounts as $id => $account) {
            if (($account['_platform'] ?? '') === $platform && !empty($account['enabled'])) {
                $result[] = array_merge($account, ['id' => $id]);
            }
        }
        return $result;
    }

    private function httpGet(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return $error ? null : $response;
    }

    private function httpPost(string $url, array $data, bool $json = false): ?string
    {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
        ];

        if ($json) {
            $opts[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
            $opts[CURLOPT_POSTFIELDS] = json_encode($data);
        } else {
            $opts[CURLOPT_POSTFIELDS] = http_build_query($data);
        }

        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);
        return $error ? null : $response;
    }

    private function log(string $message): void
    {
        $this->log[] = '[' . date('H:i:s') . '] ' . $message;
    }
}
