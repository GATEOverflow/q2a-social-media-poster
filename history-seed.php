<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
require_once dirname(dirname(dirname(__FILE__))) . '/qa-include/qa-base.php';
$key = (string)getenv('SMP_CRON_KEY');
$expected = (string)qa_opt('smp_cron_key');
if ($expected === '' || !hash_equals($expected, $key)) {
    fwrite(STDERR, "Invalid cron key.\n");
    exit(1);
}
$options = getopt('', ['month:', 'day:', 'apply', 'replace-generated', 'fill-empty']);
$month = (int)($options['month'] ?? 0);
$onlyDay = (int)($options['day'] ?? 0);
if ($month < 1 || $month > 12 || ($onlyDay && !checkdate($month, $onlyDay, 2024))) {
    fwrite(STDERR, "Specify a valid --month and optional --day.\n");
    exit(1);
}
$applied = isset($options['apply']);
$saved = 0;
$skipped = 0;
$preserved = 0;
$empty = [];
$failed = [];
$replaceGenerated = isset($options['replace-generated']);
$technicalScore = static function (string $text): int {
    if (preg_match('/\b(kill(?:ed|ing|s)?|murder(?:ed|s)?|massacre|shooting|shot|war|battle|attack|bomb(?:ing|ed)?|terror|rape|execut(?:ed|ion)|disaster|crash|fatal\w*|deaths?|dead|injur\w*|derail\w*|rear-ends|suicide|assassin|genocide|weapon|firearm|rifle|kidnapp\w*|behead\w*|explosion|explodes|disintegrat\w*|collid\w*|collaps\w*|grief|champagne|concert|entertainer|rapper|internet personality|internet celebrity|politic\w*|diplomat|mountaineer|cricketer|race car driver|estate manager|natural satellite|meteors|frisbee|flying disc|antitrust|merger|telegraph agency|telephone operator|dvd rental|television show|digital library|extrasolar planet|additional moons|solar storm|chimpanzee|rhesus|monkey|flammable|destroys|brewer|gatorade|ends operation|internet movie database|announces discovery of)\b/iu', $text)) {
        return 0;
    }
    if (preg_match('/\b(computer|computing|software|internet|algorithm|programming|digital|microprocessor|semiconductor|world wide web|artificial intelligence|machine learning|robotics|cryptograph\w*)\b/iu', $text)) {
        return 100;
    }
    if (preg_match('/\b(engineer\w*|engineering|inventor|invention|invented|satellites?|spacecraft|space shuttle|rocket|railway|railroad|bridge|electrical|electric power transmission|electronics|aerospace|telecommunication\w*|telephone|telegraph|telescope|nuclear reactor|power station)\b/iu', $text)) {
        return 20;
    }
    return 0;
};
$fetchFeed = static function (string $kind, int $month, int $day): ?array {
    $url = sprintf('https://en.wikipedia.org/api/rest_v1/feed/onthisday/%s/%02d/%02d', $kind, $month, $day);
    $curl = curl_init($url);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERAGENT => 'GateOverflowHistory/1.0 (admin@gateoverflow.in)',
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
    ]);
    $response = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    $data = $status === 200 ? json_decode((string)$response, true) : null;
    return is_array($data) && isset($data[$kind]) && is_array($data[$kind]) ? $data[$kind] : null;
};
$firstDay = $onlyDay ?: 1;
$lastDay = $onlyDay ?: cal_days_in_month(CAL_GREGORIAN, $month, 2024);
for ($day = $firstDay; $day <= $lastDay; $day++) {
    $key = sprintf('%02d-%02d', $month, $day);
    $existing = qa_db_read_one_assoc(qa_db_query_sub('SELECT content, source_url, approved FROM ^smp_history WHERE month_day=$', $key), true);
    $fillEmpty = $existing && isset($options['fill-empty']) && $existing['content'] === '' && $existing['source_url'] === '' && empty($existing['approved']);
    if ($existing && !$fillEmpty && (!$replaceGenerated || !empty($existing['approved']) || $technicalScore($existing['content']) > 0)) {
        $skipped++;
        continue;
    }
    $calendarSource = 'https://en.wikipedia.org/wiki/' . date('F_j', mktime(12, 0, 0, $month, $day, 2024));
    if ($existing && !$fillEmpty && !str_starts_with($existing['source_url'], 'https://en.wikipedia.org/wiki/')) {
        $preserved++;
        continue;
    }
    $events = $fetchFeed('events', $month, $day);
    if ($events === null) {
        $failed[] = $key;
        continue;
    }
    $best = null;
    $source = $calendarSource;
    $bestScore = 0;
    $originalFound = !$existing || $fillEmpty;
    foreach ($events as $event) {
        $text = trim((string)($event['text'] ?? ''));
        $year = (int)($event['year'] ?? 0);
        if ($existing && $existing['source_url'] === $calendarSource && $existing['content'] === $year . ': ' . $text) {
            $originalFound = true;
        }
        if ($text === '' || $year < 1 || mb_strlen($text) > 500) {
            continue;
        }
        $score = $technicalScore($text);
        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $year . ': ' . $text;
        }
    }
    if ($bestScore < 100 || !$originalFound) {
        $births = $fetchFeed('births', $month, $day);
        if ($births === null) {
            $failed[] = $key;
            continue;
        }
        foreach ($births as $birth) {
            $text = trim((string)($birth['text'] ?? ''));
            $year = (int)($birth['year'] ?? 0);
            $article = (string)($birth['pages'][0]['content_urls']['desktop']['page'] ?? '');
            if ($text === '' || $year < 1 || mb_strlen($text) > 400 || !str_starts_with($article, 'https://en.wikipedia.org/wiki/')) {
                continue;
            }
            if ($existing && $existing['source_url'] === $article && $existing['content'] === $year . ': Born ' . rtrim($text, '. ') . '.') {
                $originalFound = true;
            }
            $score = $technicalScore($text);
            if (preg_match('/\b(politician|prime minister|president|military|soldier|athlete|singer)\b/iu', $text)) {
                $score = 0;
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $year . ': Born ' . rtrim($text, '. ') . '.';
                $source = $article;
            }
        }
    }
    if (!$originalFound) {
        $preserved++;
        continue;
    }
    if ($best === null) {
        $empty[] = $key;
    }
    if ($applied) {
        if ($existing) {
            qa_db_query_sub('UPDATE ^smp_history SET content=$, source_url=$ WHERE month_day=$ AND approved=0 AND BINARY content=BINARY $ AND BINARY source_url=BINARY $', $best ?? '', $best === null ? '' : $source, $key, $existing['content'], $existing['source_url']);
        } elseif ($best !== null) {
            qa_db_query_sub('INSERT IGNORE INTO ^smp_history (month_day, content, source_url, approved) VALUES ($, $, $, 0)', $key, $best, $source);
        }
        $saved += qa_db_affected_rows();
    } else {
        echo json_encode(['date' => $key, 'content' => $best, 'source' => $best === null ? '' : $source], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
    }
}
echo json_encode(['month' => $month, 'saved' => $saved, 'skipped_existing' => $skipped, 'preserved_manual' => $preserved, 'empty' => $empty, 'failed' => $failed, 'apply' => $applied]), "\n";
if ($failed) {
    exit(2);
}