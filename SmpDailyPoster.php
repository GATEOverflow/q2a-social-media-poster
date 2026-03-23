<?php

/**
 * Daily poster module for Question of the Day and Quote of the Day.
 * Can be triggered via page load (init_queries) or via cron.php.
 */
class SmpDailyPoster
{
    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function init_queries($tableslc)
    {
        require_once $this->directory . 'SmpConstants.php';
        $this->runDailyPosts();
        return [];
    }

    /**
     * Run daily posts if they are due.
     * Called from init_queries (page load) or from cron.php.
     */
    public function runDailyPosts(): void
    {
        require_once $this->directory . 'SmpConstants.php';

        // Use system timezone (PHP may default to UTC even if system is IST)
        $sysTz = @trim(shell_exec('cat /etc/timezone 2>/dev/null'))
            ?: @trim(shell_exec("timedatectl 2>/dev/null | grep 'Time zone' | awk '{print $3}'"));
        if ($sysTz && in_array($sysTz, timezone_identifiers_list())) {
            date_default_timezone_set($sysTz);
        }

        $currentHour = (int)date('G');
        $today = date('Y-m-d');

        // Question of the Day
        if (qa_opt(SmpConstants::OPT_QOTD_ENABLED)) {
            $qotdHour = (int)(qa_opt(SmpConstants::OPT_QOTD_HOUR) ?: 9);
            $qotdLastRun = qa_opt(SmpConstants::OPT_QOTD_LAST_RUN);
            $qotdLastDate = substr($qotdLastRun, 0, 10);

            if ($currentHour >= $qotdHour && $qotdLastDate !== $today) {
                qa_opt(SmpConstants::OPT_QOTD_LAST_RUN, $today); // lock immediately to prevent double-run
                $this->postQuestionOfTheDay();
                qa_opt(SmpConstants::OPT_QOTD_LAST_RUN, date('Y-m-d H:i:s'));
            }
        }

        // Quote of the Day
        if (qa_opt(SmpConstants::OPT_QUOTE_ENABLED)) {
            $quoteHour = (int)(qa_opt(SmpConstants::OPT_QUOTE_HOUR) ?: 8);
            $quoteLastRun = qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN);
            $quoteLastDate = substr($quoteLastRun, 0, 10);

            if ($currentHour >= $quoteHour && $quoteLastDate !== $today) {
                qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN, $today);
                $this->postQuoteOfTheDay();
                qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN, date('Y-m-d H:i:s'));
            }
        }
    }

    /**
     * Pick a random MCQ question with an answer and post it.
     */
    private function postQuestionOfTheDay(): void
    {
        require_once $this->directory . 'SmpPoster.php';
        require_once QA_INCLUDE_DIR . 'app/format.php';

        $poster = new SmpPoster($this->directory);

        // Check if any accounts are configured for QOTD
        $accounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_QOTD);
        if (empty($accounts)) {
            return;
        }

        // Fetch a random MCQ question (retry if image doesn't fit)
        $question = null;
        $imageUrl = null;
        $triedPostIds = [];
        require_once $this->directory . 'SmpImageGenerator.php';
        $imageGen = new SmpImageGenerator();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $question = $this->fetchRandomMcqQuestion($triedPostIds);
            if (!$question) {
                break;
            }

            $imageUrl = $imageGen->generateFromText(
                $question['content'],
                'Question of the Day: ' . $question['title'],
                (int)$question['postid']
            );

            if ($imageUrl !== null) {
                break; // Image fits
            }

            // Content too large for image — try another question
            $triedPostIds[] = (int)$question['postid'];
            $question = null;
        }

        if (!$question) {
            $poster->reportFailure('QOTD: No eligible MCQ question found (tried ' . count($triedPostIds) . ' questions)');
            return;
        }

        $postId = (int)$question['postid'];
        $title = $question['title'];
        $content = $question['content'];
        $tags = $question['tags'];
        $answerStr = $question['answer_str'];

        // Store the last posted question ID to avoid repeats
        qa_opt(SmpConstants::OPT_QOTD_LAST_POSTID, $postId);

        // Build URL
        $url = qa_q_path($postId, $title, true);

        // Strip HTML for text content
        $rawContent = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));

        // Build tag hashtags from question's tags
        $tagHashtags = $this->tagsToHashtags($tags);

        // Build the QOTD message (image has the full question, caption just has link)
        $messagePrefix = "📝 Question of the Day\n\n";
        $messagePrefix .= $title . "\n\n";
        $messagePrefix .= "🔗 Answer & Discussion: " . $url;
        $messagePrefix .= "\n\n#QuestionOfTheDay #QOTD" . (!empty($tagHashtags) ? ' ' . $tagHashtags : '');

        // Optionally enhance with OpenAI
        $message = $poster->openaiGenerateMessage(
            $messagePrefix,
            'Reformat this question-of-the-day social media post. Keep the question, options, and link. Make it engaging. Do not reveal the answer.'
        );

        $results = $poster->postToAll(SmpConstants::CONTENT_QOTD, $message, $imageUrl, ['title' => $title]);

        $poster->reportPostingSummary(
            'Question of the Day',
            $results,
            'Post ID: ' . $postId
            . "\nTitle: " . $title
            . "\nImage URL: " . ($imageUrl ?? 'none')
            . "\n\n--- Message ---\n" . mb_substr($message, 0, 500)
        );
    }

    /**
     * Generate and post a motivational quote of the day.
     */
    private function postQuoteOfTheDay(): void
    {
        require_once $this->directory . 'SmpPoster.php';

        $poster = new SmpPoster($this->directory);

        // Check if any accounts are configured for Quote
        $accounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_QUOTE);
        if (empty($accounts)) {
            return;
        }

        // Try quote bank first — use pre-generated quote for today
        $today = date('Y-m-d');
        $quote = $poster->getTodayQuote();

        if ($quote) {
            // Mark as posted in the bank
            $poster->markQuotePosted($today);
        } else {
            // Fallback: generate on the fly via OpenAI
            $customPrompt = qa_opt(SmpConstants::OPT_QUOTE_PROMPT);
            if (empty($customPrompt)) {
                $customPrompt = 'Generate a unique, inspiring motivational quote suitable for students preparing for competitive exams. '
                    . 'Include the quote and attribute it to a famous person or mark it as anonymous. '
                    . 'Format it nicely for social media with emojis. Add #QuoteOfTheDay #Motivation hashtags.';
            }

            $todayStr = date('l, F j, Y');
            $quote = $poster->openaiGenerateMessage(
                'Generate a motivational Quote of the Day for ' . $todayStr,
                $customPrompt
            );
        }

        if (empty($quote)) {
            $poster->reportFailure('Quote of the Day: Failed to get a quote (bank empty and OpenAI failed)');
            return;
        }

        // Generate image for Instagram/YouTube if needed
        $imageUrl = null;
        $accountPlatforms = array_column($accounts, '_platform');
        $needsImage = in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateQuoteImage($quote);
        }

        $results = $poster->postToAll(SmpConstants::CONTENT_QUOTE, $quote, $imageUrl, ['title' => 'Quote of the Day']);

        $poster->reportPostingSummary(
            'Quote of the Day',
            $results,
            'Image URL: ' . ($imageUrl ?? 'none')
            . "\n\n--- Quote ---\n" . mb_substr($quote, 0, 500)
        );
    }

    /**
     * Fetch a random MCQ question that:
     * - Has type='Q' in posts
     * - Does NOT have 'numerical-answers' or 'multiple-selects' tags
     * - Has an answer in ec_answers table
     * - Wasn't the last posted QOTD
     *
     * @return array|null Question row or null
     */
    private function fetchRandomMcqQuestion(array $excludePostIds = []): ?array
    {
        $lastPostId = (int)qa_opt(SmpConstants::OPT_QOTD_LAST_POSTID);
        $excludeTags = qa_opt(SmpConstants::OPT_QOTD_EXCLUDE_TAGS);
        $categories = qa_opt(SmpConstants::OPT_QOTD_CATEGORIES);

        // Use Q2A's basic selectspec so any plugin overrides/filtering get applied
        require_once QA_INCLUDE_DIR . 'db/selects.php';
        $selectspec = qa_db_posts_basic_selectspec(null, true, false);
        $baseSource = $selectspec['source'];

        // Build the exclusion tag condition
        // Always exclude numerical-answers and multiple-selects
        $tagExclusions = ['numerical-answers', 'multiple-selects', 'numerical answers'];
        if (!empty($excludeTags)) {
            $extra = array_map('trim', explode(',', $excludeTags));
            $tagExclusions = array_merge($tagExclusions, array_filter($extra));
        }

        // Build LIKE NOT conditions for tags
        $tagConditions = [];
        foreach ($tagExclusions as $tag) {
            $escapedTag = qa_db_escape_string($tag);
            $tagConditions[] = "^posts.tags NOT LIKE '%" . $escapedTag . "%'";
        }
        $tagWhere = implode(' AND ', $tagConditions);

        // Category filter
        $catWhere = '';
        if (!empty($categories)) {
            $catIds = array_map('intval', array_filter(explode(',', $categories)));
            if (!empty($catIds)) {
                $catWhere = ' AND ^posts.categoryid IN (' . implode(',', $catIds) . ')';
            }
        }

        // Exclude last posted question and any previously tried post IDs
        $excludeIds = $excludePostIds;
        if ($lastPostId > 0) {
            $excludeIds[] = $lastPostId;
        }
        $excludeWhere = '';
        if (!empty($excludeIds)) {
            $excludeWhere = ' AND ^posts.postid NOT IN (' . implode(',', array_map('intval', $excludeIds)) . ')';
        }

        // Limit content length to avoid questions that won't fit in the image
        $query = "SELECT ^posts.postid, ^posts.title, ^posts.content, ^posts.tags, a.answer_str "
            . "FROM " . $baseSource . " "
            . "JOIN ^ec_answers a ON ^posts.postid = a.postid "
            . "WHERE ^posts.type = 'Q' "
            . "AND ^posts.closedbyid IS NULL "
            . "AND a.answer_str != '' "
            . "AND LENGTH(^posts.content) < 1500 "
            . "AND " . $tagWhere
            . $catWhere
            . $excludeWhere
            . " ORDER BY RAND() LIMIT 1";

        $result = qa_db_query_sub($query);
        $row = qa_db_read_one_assoc($result, true);

        return $row ?: null;
    }

    /**
     * Convert Q2A comma-separated tags to hashtag string.
     * E.g. "gate-cse-2025,digital-logic" → "#GateCse2025 #DigitalLogic"
     */
    private function tagsToHashtags(string $tags): string
    {
        if (empty(trim($tags))) {
            return '';
        }

        $tagList = array_map('trim', explode(',', $tags));
        $hashtags = [];

        foreach ($tagList as $tag) {
            if (empty($tag)) {
                continue;
            }
            // Convert hyphenated-tag to CamelCase: "digital-logic" → "DigitalLogic"
            $camel = str_replace(' ', '', ucwords(str_replace('-', ' ', $tag)));
            // Remove any non-alphanumeric characters
            $camel = preg_replace('/[^a-zA-Z0-9]/', '', $camel);
            if (!empty($camel)) {
                $hashtags[] = '#' . $camel;
            }
        }

        return implode(' ', $hashtags);
    }

    /**
     * Try to extract answer options (A, B, C, D...) from question HTML content.
     */
    private function extractOptions(string $content): array
    {
        $options = [];

        // Try to extract from HTML <ol><li> structure first
        if (preg_match('/<ol\b[^>]*>(.*?)<\/ol>/is', $content, $olMatch)) {
            $olTag = $olMatch[0];

            // Determine list style
            $style = 'upper-alpha';
            if (preg_match('/list-style-type:\s*([a-z-]+)/i', $olTag, $sm)) {
                $style = strtolower(trim($sm[1], "; \t"));
            }

            preg_match_all('/<li\b[^>]*>(.*?)<\/li>/is', $olMatch[1], $liMatches);
            if (!empty($liMatches[1])) {
                foreach ($liMatches[1] as $i => $liContent) {
                    $label = $this->getOptionLabel($style, $i);
                    $optionText = trim(html_entity_decode(strip_tags($liContent), ENT_QUOTES, 'UTF-8'));
                    $options[] = $label . ') ' . mb_substr($optionText, 0, 100);
                }
                return $options;
            }
        }

        // Fallback: match plain text patterns like "A) ...", "(A) ..."
        $text = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));
        if (preg_match_all('/(?:^|\n)\s*(?:\(?([A-Da-d])\)\.?|([A-Da-d])[\)\.]\s*)(.+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $letter = strtoupper($match[1] ?: $match[2]);
                $optionText = trim($match[3]);
                $options[] = $letter . ') ' . mb_substr($optionText, 0, 100);
            }
        }

        return $options;
    }

    /**
     * Get option label based on list style type.
     */
    private function getOptionLabel(string $style, int $index): string
    {
        switch ($style) {
            case 'upper-alpha': case 'upper-latin':
                return chr(65 + $index);
            case 'lower-alpha': case 'lower-latin':
                return chr(97 + $index);
            case 'upper-roman':
                $r = ['I','II','III','IV','V','VI','VII','VIII','IX','X'];
                return $r[$index] ?? (string)($index + 1);
            case 'lower-roman':
                $r = ['i','ii','iii','iv','v','vi','vii','viii','ix','x'];
                return $r[$index] ?? (string)($index + 1);
            case 'decimal':
                return (string)($index + 1);
            default:
                return chr(65 + $index);
        }
    }
}
