<?php

/**
 * Constants for the Social Media Poster plugin.
 */
class SmpConstants
{
    // Platform identifiers
    const PLATFORM_TELEGRAM = 'telegram';
    const PLATFORM_FACEBOOK = 'facebook';
    const PLATFORM_X = 'x';
    const PLATFORM_LINKEDIN = 'linkedin';
    const PLATFORM_WHATSAPP = 'whatsapp';
    const PLATFORM_INSTAGRAM = 'instagram';
    const PLATFORM_YOUTUBE = 'youtube';

    // Content types
    const CONTENT_QUESTION = 'question';
    const CONTENT_EXAM = 'exam';
    const CONTENT_BLOG = 'blog';
    const CONTENT_JOB = 'job';
    const CONTENT_QOTD = 'qotd';
    const CONTENT_QUOTE = 'quote';

    // Option keys
    const OPT_PREFIX = 'smp_';
    const OPT_OPENAI_KEY = 'openai_api_key';
    const OPT_OPENAI_CONFIG = 'smp_openai_config';
    const OPT_INSTAGRAM_AUTO_IMAGE = 'smp_instagram_auto_image';
    const OPT_IMAGE_WIDTH = 'smp_image_width';
    const OPT_IMAGE_HEIGHT = 'smp_image_height';
    const OPT_IMAGE_BG_COLOR = 'smp_image_bg_color';
    const OPT_IMAGE_TEXT_COLOR = 'smp_image_text_color';
    const OPT_IMAGE_FONT_SIZE = 'smp_image_font_size';
    const OPT_IMAGE_LOGO_URL = 'smp_image_logo_url';
    const OPT_YOUTUBE_AUTO_VIDEO = 'smp_youtube_auto_video';
    const OPT_LAST_EXPIRY_CHECK = 'smp_last_expiry_check';
    const OPT_EXPIRY_NOTIFIED = 'smp_expiry_notified';
    const OPT_LAST_TOKEN_REFRESH = 'smp_last_token_refresh';

    // Daily poster option keys
    const OPT_QOTD_ENABLED = 'smp_qotd_enabled';
    const OPT_QOTD_HOUR = 'smp_qotd_hour';
    const OPT_QOTD_LAST_RUN = 'smp_qotd_last_run';
    const OPT_QOTD_LAST_POSTID = 'smp_qotd_last_postid';
    const OPT_QOTD_EXCLUDE_TAGS = 'smp_qotd_exclude_tags';
    const OPT_QOTD_CATEGORIES = 'smp_qotd_categories';
    const OPT_QUOTE_ENABLED = 'smp_quote_enabled';
    const OPT_QUOTE_HOUR = 'smp_quote_hour';
    const OPT_QUOTE_LAST_RUN = 'smp_quote_last_run';
    const OPT_QUOTE_PROMPT = 'smp_quote_prompt';

    // Manual share settings
    const OPT_MANUAL_SHARE_LEVEL = 'smp_manual_share_level';

    // Category routing
    const OPT_CATEGORY_ROUTING = 'smp_category_routing';

    /**
     * Get all supported platforms with display info.
     */
    public static function getPlatforms(): array
    {
        return [
            self::PLATFORM_TELEGRAM => [
                'name' => 'Telegram',
                'fields' => ['bot_token', 'chat_id'],
                'labels' => ['Bot Token', 'Chat ID'],
            ],
            self::PLATFORM_FACEBOOK => [
                'name' => 'Facebook',
                'fields' => ['page_access_token', 'page_id'],
                'labels' => ['Page Access Token', 'Page ID'],
            ],
            self::PLATFORM_X => [
                'name' => 'X (Twitter)',
                'fields' => ['api_key', 'api_secret', 'access_token', 'access_token_secret'],
                'labels' => ['API Key', 'API Secret', 'Access Token', 'Access Token Secret'],
            ],
            self::PLATFORM_LINKEDIN => [
                'name' => 'LinkedIn',
                'fields' => ['access_token', 'author_urn'],
                'labels' => ['Access Token', 'Author URN'],
            ],
            self::PLATFORM_WHATSAPP => [
                'name' => 'WhatsApp',
                'fields' => ['access_token', 'phone_number_id', 'recipient_phone'],
                'labels' => ['Access Token', 'Phone Number ID', 'Recipient Phone'],
            ],
            self::PLATFORM_INSTAGRAM => [
                'name' => 'Instagram',
                'fields' => ['access_token', 'account_id'],
                'labels' => ['Access Token', 'Account ID'],
            ],
            self::PLATFORM_YOUTUBE => [
                'name' => 'YouTube Shorts',
                'fields' => ['client_id', 'client_secret', 'refresh_token'],
                'labels' => ['Client ID', 'Client Secret', 'Refresh Token'],
            ],
        ];
    }

    /**
     * Get all content types with display info.
     */
    public static function getContentTypes(): array
    {
        return [
            self::CONTENT_QUESTION => 'Question',
            self::CONTENT_EXAM => 'Exam',
            self::CONTENT_BLOG => 'Blog',
            self::CONTENT_JOB => 'Job',
            self::CONTENT_QOTD => 'Question of the Day',
            self::CONTENT_QUOTE => 'Quote of the Day',
        ];
    }

    /**
     * Get available content types based on installed tables.
     * Filters out exam/blog/job if their tables don't exist.
     */
    public static function getAvailableContentTypes(): array
    {
        $all = self::getContentTypes();
        
        // These require specific tables
        $tableMap = [
            self::CONTENT_EXAM => 'exams',
            self::CONTENT_BLOG => 'blogs',
            self::CONTENT_JOB => 'jobs',
        ];

        foreach ($tableMap as $contentType => $tableName) {
            if (!self::tableExists($tableName)) {
                unset($all[$contentType]);
            }
        }

        return $all;
    }

    /**
     * Check if a Q2A table exists.
     */
    private static function tableExists(string $tableName): bool
    {
        static $cache = [];
        
        if (isset($cache[$tableName])) {
            return $cache[$tableName];
        }

        $fullTable = qa_db_add_table_prefix($tableName);
        $result = qa_db_read_one_value(
            qa_db_query_raw("SHOW TABLES LIKE '" . qa_db_escape_string($fullTable) . "'"),
            true
        );
        
        $cache[$tableName] = ($result !== null);
        return $cache[$tableName];
    }

    /**
     * Get option key for platform accounts.
     */
    public static function accountsOptionKey(string $platform): string
    {
        return self::OPT_PREFIX . $platform . '_accounts';
    }

    /**
     * Get option key for content-type platform mapping.
     * @deprecated Use contentAccountsOptionKey for account-level routing
     */
    public static function contentPlatformsOptionKey(string $contentType): string
    {
        return self::OPT_PREFIX . $contentType . '_platforms';
    }

    /**
     * Get option key for content-type account mapping.
     */
    public static function contentAccountsOptionKey(string $contentType): string
    {
        return self::OPT_PREFIX . $contentType . '_accounts';
    }

    /**
     * Generate a unique account ID.
     */
    public static function generateAccountId(string $platform): string
    {
        return $platform . '_' . substr(md5(uniqid('', true)), 0, 8);
    }
}
