<?php

/**
 * Daily poster module for Question of the Day and Quote of the Day.
 * Runs on each page load, throttled to once per day per feature.
 * Posts to platforms configured under the QOTD / Quote content types.
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

        $currentHour = (int)date('G');
        $today = date('Y-m-d');

        // Question of the Day
        if (qa_opt(SmpConstants::OPT_QOTD_ENABLED)) {
            $qotdHour = (int)(qa_opt(SmpConstants::OPT_QOTD_HOUR) ?: 9);
            $qotdLastRun = qa_opt(SmpConstants::OPT_QOTD_LAST_RUN);

            if ($currentHour >= $qotdHour && $qotdLastRun !== $today) {
                qa_opt(SmpConstants::OPT_QOTD_LAST_RUN, $today);
                $this->postQuestionOfTheDay();
            }
        }

        // Quote of the Day
        if (qa_opt(SmpConstants::OPT_QUOTE_ENABLED)) {
            $quoteHour = (int)(qa_opt(SmpConstants::OPT_QUOTE_HOUR) ?: 8);
            $quoteLastRun = qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN);

            if ($currentHour >= $quoteHour && $quoteLastRun !== $today) {
                qa_opt(SmpConstants::OPT_QUOTE_LAST_RUN, $today);
                $this->postQuoteOfTheDay();
            }
        }

        return [];
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

        // Fetch a random MCQ question
        $question = $this->fetchRandomMcqQuestion();
        if (!$question) {
            $poster->reportFailure('QOTD: No eligible MCQ question found');
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

        // Build the QOTD message
        $messagePrefix = "📝 Question of the Day\n\n";
        $messagePrefix .= "Q: " . $title . "\n\n";

        // Truncate body for social media
        $bodySnippet = mb_substr($rawContent, 0, 400);
        if (mb_strlen($rawContent) > 400) {
            $bodySnippet .= '...';
        }

        // Add answer options hint
        $optionLabels = $this->extractOptions($content);
        if (!empty($optionLabels)) {
            $messagePrefix .= $bodySnippet . "\n\n";
            foreach ($optionLabels as $label) {
                $messagePrefix .= $label . "\n";
            }
        } else {
            $messagePrefix .= $bodySnippet . "\n";
        }

        $messagePrefix .= "\n🔗 Answer & Discussion: " . $url;
        $messagePrefix .= "\n\n#QuestionOfTheDay #QOTD";

        // Optionally enhance with OpenAI
        $message = $poster->openaiGenerateMessage(
            $messagePrefix,
            'Reformat this question-of-the-day social media post. Keep the question, options, and link. Make it engaging. Do not reveal the answer.'
        );

        // Generate image for Instagram/YouTube if needed
        $imageUrl = null;
        $accountPlatforms = array_column($accounts, '_platform');
        $needsImage = in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateFromText($content, 'Question of the Day: ' . $title, $postId);
        }

        $results = $poster->postToAll(SmpConstants::CONTENT_QOTD, $message, $imageUrl, ['title' => $title]);

        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'QOTD post failed on ' . $platform . ' (' . $accountName . ')',
                    'Post ID: ' . $postId
                    . "\nTitle: " . $title
                    . "\nImage URL: " . $usedImageUrl
                    . "\nVideo URL: " . $videoUrl
                    . "\nError: " . ($result['error'] ?? 'Unknown')
                    . "\n\n--- Message ---\n" . $message
                    . "\n\n--- Content ---\n" . ($content ?? '')
                );
            }
        }
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
            $imageUrl = $imageGen->generateFromText($quote, 'Quote of the Day');
        }

        $results = $poster->postToAll(SmpConstants::CONTENT_QUOTE, $quote, $imageUrl, ['title' => 'Quote of the Day']);

        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $accountName = $result['account_name'] ?? $accountId;
                $platform = $result['platform'] ?? 'unknown';
                $usedImageUrl = $result['image_url'] ?? $imageUrl ?? 'none';
                $videoUrl = $result['video_url'] ?? 'none';
                $poster->reportFailure(
                    'Quote of the Day post failed on ' . $platform . ' (' . $accountName . ')',
                    'Image URL: ' . $usedImageUrl
                    . "\nVideo URL: " . $videoUrl
                    . "\nError: " . ($result['error'] ?? 'Unknown')
                    . "\n\n--- Message ---\n" . $quote
                );
            }
        }
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
    private function fetchRandomMcqQuestion(): ?array
    {
        $lastPostId = (int)qa_opt(SmpConstants::OPT_QOTD_LAST_POSTID);
        $excludeTags = qa_opt(SmpConstants::OPT_QOTD_EXCLUDE_TAGS);
        $categories = qa_opt(SmpConstants::OPT_QOTD_CATEGORIES);

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
            $tagConditions[] = "p.tags NOT LIKE '%" . $escapedTag . "%'";
        }
        $tagWhere = implode(' AND ', $tagConditions);

        // Category filter
        $catWhere = '';
        if (!empty($categories)) {
            $catIds = array_map('intval', array_filter(explode(',', $categories)));
            if (!empty($catIds)) {
                $catWhere = ' AND p.categoryid IN (' . implode(',', $catIds) . ')';
            }
        }

        // Exclude last posted question
        $excludeWhere = '';
        if ($lastPostId > 0) {
            $excludeWhere = ' AND p.postid != ' . $lastPostId;
        }

        $query = "SELECT p.postid, p.title, p.content, p.tags, a.answer_str "
            . "FROM ^posts p "
            . "JOIN ^ec_answers a ON p.postid = a.postid "
            . "WHERE p.type = 'Q' "
            . "AND a.answer_str != '' "
            . "AND " . $tagWhere
            . $catWhere
            . $excludeWhere
            . " ORDER BY RAND() LIMIT 1";

        $result = qa_db_query_sub($query);
        $row = qa_db_read_one_assoc($result, true);

        return $row ?: null;
    }

    /**
     * Try to extract answer options (A, B, C, D...) from question HTML content.
     */
    private function extractOptions(string $content): array
    {
        $options = [];
        $text = strip_tags(html_entity_decode($content, ENT_QUOTES, 'UTF-8'));

        // Match patterns like "A) ...", "A. ...", "(A) ...", "a) ..."
        if (preg_match_all('/(?:^|\n)\s*(?:\(?([A-Da-d])\)\.?|([A-Da-d])[\)\.]\s*)(.+)/m', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $letter = strtoupper($match[1] ?: $match[2]);
                $optionText = trim($match[3]);
                $options[] = $letter . ') ' . mb_substr($optionText, 0, 100);
            }
        }

        return $options;
    }
}
