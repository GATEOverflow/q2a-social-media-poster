<?php

/**
 * Social posting for new exams (qa_exam_post).
 * Exams are queued and posted once no new exam has arrived for a while, so a series
 * uploaded together becomes a single combined post. Also registered as a process
 * module: page loads (and cron.php) flush the queue when it is due.
 */
class SmpEventExam
{
    private const QUIET_SECONDS = 1200;
    private const MAX_WAIT_SECONDS = 7200;

    private string $directory;

    function load_module($directory, $urltoroot)
    {
        $this->directory = $directory;
    }

    function process_event($event, $userid, $handle, $cookieid, $params)
    {
        // exam-creator fires 'qa_exam_post'; 'qa_exam_post_' kept for backward compatibility
        if ($event !== 'qa_exam_post' && $event !== 'qa_exam_post_') {
            return;
        }
        $postId = (int)($params['postid'] ?? 0);
        if (!$postId) {
            return;
        }

        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        // Manual share selections only exist in this request's POST data, so capture them now
        $poster = new SmpPoster($this->directory);
        $manualIds = array_keys($poster->getManualShareAccounts(SmpConstants::CONTENT_EXAM));

        $this->withQueueLock(function () use ($postId, $userid, $manualIds) {
            $queue = $this->readQueue();
            $queue[] = ['postid' => $postId, 'userid' => (int)$userid, 'manual' => $manualIds, 'at' => time()];
            qa_opt(SmpConstants::OPT_EXAM_QUEUE, json_encode($queue));
        });
    }

    function init_queries($tableslc)
    {
        require_once $this->directory . 'SmpConstants.php';

        $queue = json_decode(qa_opt(SmpConstants::OPT_EXAM_QUEUE) ?: '[]', true);
        if (!empty($queue) && is_array($queue) && $this->isDue($queue)) {
            register_shutdown_function(function () {
                // Send the page to the visitor first; posting can take several seconds
                if (function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                }
                $this->flushQueue();
            });
        }

        return [];
    }

    /**
     * Post queued exams if the batch window has passed (or immediately when $force is true).
     */
    public function flushQueue(bool $force = false): void
    {
        require_once $this->directory . 'SmpConstants.php';
        require_once $this->directory . 'SmpPoster.php';

        $batch = [];
        $this->withQueueLock(function () use (&$batch, $force) {
            $queue = $this->readQueue();
            if ($queue && ($force || $this->isDue($queue))) {
                $batch = $queue;
                qa_opt(SmpConstants::OPT_EXAM_QUEUE, '');
            }
        });

        $groups = [];
        foreach ($batch as $item) {
            $groups[($item['userid'] ?? 0) . '|' . implode(',', $item['manual'] ?? [])][] = $item;
        }
        foreach ($groups as $items) {
            $this->postGroup($items);
        }
    }

    private function isDue(array $queue): bool
    {
        $times = array_column($queue, 'at');
        return time() - max($times) >= self::QUIET_SECONDS || time() - min($times) >= self::MAX_WAIT_SECONDS;
    }

    private function readQueue(): array
    {
        // Read from the DB, not the per-request options cache, so concurrent requests see each other's writes
        $json = qa_db_read_one_value(qa_db_query_sub(
            'SELECT content FROM ^options WHERE title = $',
            SmpConstants::OPT_EXAM_QUEUE
        ), true);
        $queue = json_decode($json ?: '[]', true);
        return is_array($queue) ? $queue : [];
    }

    private function withQueueLock(callable $fn): void
    {
        $locked = (int)qa_db_read_one_value(qa_db_query_sub("SELECT GET_LOCK('smp_exam_queue', 10)"), true);
        try {
            $fn();
        } finally {
            if ($locked) {
                qa_db_query_sub("SELECT RELEASE_LOCK('smp_exam_queue')");
            }
        }
    }

    private function postGroup(array $items): void
    {
        $poster = new SmpPoster($this->directory);
        $userid = (int)($items[0]['userid'] ?? 0);

        $allAccounts = $poster->getAllAccountsById();
        $manualAccounts = [];
        foreach ($items[0]['manual'] ?? [] as $id) {
            if (!empty($allAccounts[$id]['enabled'])) {
                $manualAccounts[$id] = $allAccounts[$id];
            }
        }
        $userAccounts = $userid ? $poster->getUserAccountsForPosting($userid) : [];
        $autoAccounts = $poster->getAccountsForPosting(SmpConstants::CONTENT_EXAM);
        if (empty($autoAccounts) && empty($manualAccounts) && empty($userAccounts)) {
            return;
        }

        $exams = array_values(array_filter(array_map(fn($i) => $this->loadExam((int)$i['postid']), $items)));
        if (!$exams) {
            return;
        }

        $single = count($exams) === 1;
        if ($single) {
            $title = $exams[0]['title'];
            $link = $exams[0]['url'];
            $context = "A new GATE CSE test is live.\n" . $this->describeExam($exams[0]);
        } else {
            $title = $this->commonTitle(array_column($exams, 'title'));
            $link = qa_path_absolute('exams');
            $context = count($exams) . " new GATE CSE tests are live together. Write one post covering all of them.\n\n"
                . implode("\n\n", array_map([$this, 'describeExam'], $exams));
        }

        $composed = $poster->composePost(SmpConstants::CONTENT_EXAM, $context, $link, $single ? 'Attempt here' : 'All tests');
        $message = $composed['default'];
        $platformMessages = $composed['platforms'];
        if (!$single) {
            $list = "\n\nTests in this set:\n" . implode("\n", array_map(fn($e) => '- ' . $e['title'] . ': ' . $e['url'], $exams));
            $message .= $list;
            $platformMessages[SmpConstants::PLATFORM_FACEBOOK] .= $list;
            $platformMessages[SmpConstants::PLATFORM_TELEGRAM] .= htmlspecialchars($list, ENT_NOQUOTES, 'UTF-8');
        }

        $imageUrl = null;
        $accountPlatforms = array_column($autoAccounts + $manualAccounts + $userAccounts, '_platform');
        $needsImage = in_array(SmpConstants::PLATFORM_INSTAGRAM, $accountPlatforms)
            || (in_array(SmpConstants::PLATFORM_YOUTUBE, $accountPlatforms)
                && qa_opt(SmpConstants::OPT_YOUTUBE_AUTO_VIDEO));
        if ($needsImage) {
            $sameValue = fn(string $key) => count(array_unique(array_column($exams, $key))) === 1 ? (int)$exams[0][$key] : 0;
            require_once $this->directory . 'SmpImageGenerator.php';
            $imageGen = new SmpImageGenerator();
            $imageUrl = $imageGen->generateExamImage($title, $single ? (int)$exams[0]['postid'] : null, [
                'hook' => $composed['headline'] ?: $poster->generateExamHook($title),
                'kicker' => $single ? null : count($exams) . ' NEW TESTS ARE LIVE',
                'tests' => $single ? 0 : count($exams),
                'questions' => $sameValue('total_qs'),
                'minutes' => $sameValue('time_alotted'),
                'marks' => $sameValue('total_marks'),
            ]);
        }

        $results = $poster->postToAll(SmpConstants::CONTENT_EXAM, $message, $imageUrl, [
            'title' => $title,
            '_manual_accounts' => $manualAccounts + $userAccounts,
            'platform_messages' => $platformMessages,
        ]);

        foreach ($results as $accountId => $result) {
            if (empty($result['success'])) {
                $platform = $result['platform'] ?? 'unknown';
                $poster->reportFailure(
                    'Exam post failed on ' . $platform . ' (' . ($result['account_name'] ?? $accountId) . ')',
                    'Exam IDs: ' . implode(', ', array_column($exams, 'postid'))
                    . "\nTitle: " . $title
                    . "\nImage URL: " . ($result['image_url'] ?? $imageUrl ?? 'none')
                    . "\nVideo URL: " . ($result['video_url'] ?? 'none')
                    . "\nError: " . ($result['error'] ?? 'Unknown')
                    . "\n\n--- Message ---\n" . ($platformMessages[$platform] ?? $message)
                );
            }
        }
    }

    private function loadExam(int $postId): ?array
    {
        $exam = qa_db_read_one_assoc(qa_db_query_sub(
            'SELECT postid, title, tag, subjects, total_qs, time_alotted, total_marks, created FROM ^exams WHERE postid = #',
            $postId
        ), true);
        if (!$exam) {
            return null;
        }

        $exam['url'] = function_exists('qa_exam_request')
            ? qa_path_absolute(qa_exam_request($postId, $exam['title']))
            : qa_opt('site_url') . 'exam/' . $postId;

        $subjectIds = array_values(array_filter(array_map('intval', explode(',', (string)$exam['subjects']))));
        $exam['subject_names'] = $subjectIds
            ? qa_db_read_all_values(qa_db_query_sub('SELECT title FROM ^categories WHERE categoryid IN (#)', $subjectIds))
            : [];

        // Same tag is reused across yearly editions, which gives real attempt stats to mention
        $exam['previous'] = (string)$exam['tag'] !== ''
            ? qa_db_read_one_assoc(qa_db_query_sub(
                'SELECT taken, avg_mark, topper_mark FROM ^exams WHERE tag = $ AND postid <> # AND taken > 0 AND created < $ ORDER BY created DESC LIMIT 1',
                $exam['tag'], $postId, $exam['created']
            ), true)
            : null;

        return $exam;
    }

    private function describeExam(array $exam): string
    {
        $lines = ['Title: ' . $exam['title']];
        if (!empty($exam['subject_names'])) {
            $lines[] = 'Subjects: ' . implode(', ', $exam['subject_names']);
        }
        $lines[] = sprintf('%d questions, %d minutes, %d marks', $exam['total_qs'], $exam['time_alotted'], $exam['total_marks']);
        if (!empty($exam['previous'])) {
            $p = $exam['previous'];
            $lines[] = sprintf(
                "Last year's edition of this test: %d students attempted, average %.1f, topper %.1f",
                $p['taken'], $p['avg_mark'], $p['topper_mark']
            );
        }
        return implode("\n", $lines);
    }

    /**
     * Shared series name for a batch, e.g. "GATE Overflow CS Test Series 2027 | Mix Subjects".
     */
    private function commonTitle(array $titles): string
    {
        $prefix = array_shift($titles);
        foreach ($titles as $t) {
            while ($prefix !== '' && mb_strpos($t, $prefix) !== 0) {
                $prefix = mb_substr($prefix, 0, -1);
            }
        }
        if (($pos = mb_strrpos($prefix, '|')) !== false) {
            $prefix = mb_substr($prefix, 0, $pos);
        }
        $prefix = trim($prefix, " |-:");
        return mb_strlen($prefix) >= 10 ? $prefix : 'New GATE CSE tests';
    }
}
