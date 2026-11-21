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
    const OPT_IMAGE_STYLE = 'smp_image_style';
    const OPT_IMAGE_SITE_NAME = 'smp_image_site_name';
    const OPT_IMAGE_TEMPLATE = 'smp_image_template';
    const OPT_IMAGE_CSS_LIGHT = 'smp_image_css_light';
    const OPT_IMAGE_CSS_DARK = 'smp_image_css_dark';

    // Quote template
    const OPT_QUOTE_TEMPLATE = 'smp_quote_template';
    const OPT_QUOTE_CSS = 'smp_quote_css';
    const OPT_QUOTE_CSS_LIGHT = 'smp_quote_css_light';
    const OPT_QUOTE_CSS_DARK = 'smp_quote_css_dark';

    // Exam template
    const OPT_EXAM_TEMPLATE = 'smp_exam_template';
    const OPT_EXAM_CSS = 'smp_exam_css';
    const OPT_EXAM_CSS_LIGHT = 'smp_exam_css_light';
    const OPT_EXAM_CSS_DARK = 'smp_exam_css_dark';

    // Job template
    const OPT_JOB_TEMPLATE = 'smp_job_template';
    const OPT_JOB_CSS = 'smp_job_css';
    const OPT_JOB_CSS_LIGHT = 'smp_job_css_light';
    const OPT_JOB_CSS_DARK = 'smp_job_css_dark';
    const OPT_YOUTUBE_AUTO_VIDEO = 'smp_youtube_auto_video';
    const OPT_LAST_EXPIRY_CHECK = 'smp_last_expiry_check';
    const OPT_EXPIRY_NOTIFIED = 'smp_expiry_notified';
    const OPT_LAST_TOKEN_REFRESH = 'smp_last_token_refresh';
    const OPT_RECENT_POSTS = 'smp_recent_posts';
    const OPT_EXAM_QUEUE = 'smp_exam_queue';
    const OPT_EXAM_PALETTE_LAST = 'smp_exam_palette_last';

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
    const OPT_CRON_KEY = 'smp_cron_key';

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
                'fields' => ['bot_token', 'chat_id', 'message_thread_id'],
                'labels' => ['Bot Token', 'Chat ID', 'Topic Thread ID (optional)'],
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
                'fields' => ['client_id', 'client_secret', 'access_token', 'refresh_token', 'author_urn'],
                'labels' => ['Client ID', 'Client Secret', 'Access Token', 'Refresh Token', 'Author URN'],
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

    // Appended to every AI system prompt so generated content reads like a human mentor wrote it
    const AI_VOICE_GUIDELINES = 'Voice: write as an experienced Indian GATE CSE mentor in the style of Arjun Suresh of GATE Overflow: '
        . 'direct, practical, honest and encouraging, in simple Indian English, like a senior guiding juniors. '
        . 'Avoid hype and marketing buzzwords (unlock, elevate, dive into, embark, journey, game-changer, supercharge). '
        . 'Do not use emojis, emoticons or decorative symbols/icons (sparkles, rockets, arrows, check marks, stars, fire, bullets). '
        . 'Do not use em dashes or markdown formatting. Never mention being an AI and do not sign off with any name. '
        . 'Never use stock phrases such as: you\'ve got this, attention aspirants, don\'t miss out, make sure to check it out, '
        . 'keep pushing, stay tuned, gear up, level up, exciting news, we are thrilled, are you ready.';

    // Checked in code as well, since the model does not always follow the instruction above
    const BANNED_PHRASES = [
        'attention gate', 'attention aspirants', 'attention students', 'you\'ve got this', 'you’ve got this',
        'don\'t miss', 'don’t miss', 'make sure to check', 'keep pushing', 'stay tuned', 'gear up',
        'level up', 'exciting news', 'thrilled', 'are you ready',
    ];

    /**
     * Writing angles rotated per content type so consecutive posts don't read alike.
     */
    public static function getPostAngles(string $contentType): array
    {
        $angles = [
            self::CONTENT_EXAM => [
                'a study tip tied to the subjects covered in the test',
                'a warning about where students typically lose marks in such tests',
                'a time-management nudge for attempting a timed test',
                'a direct challenge with a realistic target score',
                'bust a common myth about mock tests',
                'stress analysing mistakes after the test rather than only the score',
            ],
            self::CONTENT_QUESTION => [
                'why this question is worth trying',
                'the core concept this question tests',
                'a common trap students fall into on such questions',
                'challenge readers to solve it within a few minutes',
                'how GATE tends to ask this topic',
            ],
            self::CONTENT_BLOG => [
                'the single most useful takeaway',
                'who should read this and why',
                'a practical tip from the post',
                'the question this post answers',
            ],
            self::CONTENT_JOB => [
                'who should apply',
                'the skills this role needs',
                'why this is a good opportunity for GATE aspirants or freshers',
                'a plain reminder to apply before the deadline',
            ],
        ];
        return $angles[$contentType] ?? ['a plain, informative announcement', 'one practical tip', 'a question to the reader'];
    }

    /**
     * Remove emojis and decorative pictographs that make text look AI-generated.
     */
    public static function cleanAiText(string $text): string
    {
        $text = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2190}-\x{21FF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}]/u', '', $text) ?? $text;
        $text = str_replace("\u{2014}", ' - ', $text);
        $text = preg_replace('/[ \t]{2,}/', ' ', $text) ?? $text;
        return trim(preg_replace('/^[ \t]+|[ \t]+$/m', '', $text) ?? $text);
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

/**
 * Large SMP settings (templates, CSS, state blobs) live in ^smp_settings so they are not
 * loaded with qa_options on every page; other keys pass through to qa_opt().
 */
class SmpSettings
{
    private static $cache = null;

    public static function isStored(string $name): bool
    {
        return (bool)preg_match('/^smp_((image|quote|job)_(template|css_light|css_dark)|expiry_notified|recent_posts_.+)$/', $name);
    }

    /** Same contract as qa_opt(): read with one argument, write with two. */
    public static function opt(string $name, $value = null)
    {
        if (!self::isStored($name)) {
            return func_num_args() > 1 ? qa_opt($name, $value) : qa_opt($name);
        }
        if (self::$cache === null) {
            self::$cache = qa_db_read_all_assoc(qa_db_query_sub('SELECT title, content FROM ^smp_settings'), 'title', 'content');
        }
        if (func_num_args() > 1) {
            qa_db_query_sub('INSERT INTO ^smp_settings (title, content) VALUES ($, $) ON DUPLICATE KEY UPDATE content = VALUES(content)', $name, (string)$value);
            self::$cache[$name] = (string)$value;
            return $value;
        }
        return self::$cache[$name] ?? '';
    }
}
