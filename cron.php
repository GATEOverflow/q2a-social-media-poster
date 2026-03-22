<?php
/**
 * Cron endpoint for Social Media Poster daily posts.
 *
 * Usage (crontab):
 *   For exact-hour posting, run every hour:
 *     0 * * * * curl -s "https://yoursite.com/qa-plugin/social-media-poster/cron.php?key=YOUR_SECRET_KEY"
 *   Or via CLI:
 *     0 * * * * php /path/to/qa-plugin/social-media-poster/cron.php --key=YOUR_SECRET_KEY
 *
 * The script checks the configured posting hour internally, so running hourly
 * ensures posts happen at exactly the right time without extra cron entries.
 */

// Use system timezone (PHP may default to UTC even if system is IST)
$sysTz = @trim(shell_exec('cat /etc/timezone 2>/dev/null'))
    ?: @trim(shell_exec("timedatectl 2>/dev/null | grep 'Time zone' | awk '{print $3}'"));
if ($sysTz && in_array($sysTz, timezone_identifiers_list())) {
    date_default_timezone_set($sysTz);
}

// Determine secret key from CLI argument or HTTP query string
$cronKey = null;

if (php_sapi_name() === 'cli') {
    // CLI mode: parse --key=VALUE from arguments
    foreach ($argv as $arg) {
        if (strpos($arg, '--key=') === 0) {
            $cronKey = substr($arg, 6);
            break;
        }
    }
} else {
    // HTTP mode
    $cronKey = $_GET['key'] ?? null;
}

// Bootstrap Q2A
define('QA_BASE_DIR', dirname(dirname(dirname(__FILE__))) . '/');

if (!file_exists(QA_BASE_DIR . 'qa-config.php')) {
    http_response_code(500);
    die('Q2A not found');
}

require_once QA_BASE_DIR . 'qa-include/qa-base.php';

// Validate cron key
$expectedKey = qa_opt('smp_cron_key');

if (empty($expectedKey)) {
    http_response_code(403);
    die('Cron key not configured');
}

if (!hash_equals($expectedKey, (string)$cronKey)) {
    http_response_code(403);
    die('Invalid cron key');
}

// Run daily posts
require_once __DIR__ . '/SmpConstants.php';
require_once __DIR__ . '/SmpDailyPoster.php';

$poster = new SmpDailyPoster();
$poster->load_module(__DIR__ . '/', '');
$poster->runDailyPosts();

$output = 'SMP cron executed at ' . date('Y-m-d H:i:s');

if (php_sapi_name() === 'cli') {
    echo $output . "\n";
} else {
    header('Content-Type: text/plain');
    echo $output;
}
