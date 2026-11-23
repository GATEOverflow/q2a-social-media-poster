<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$key = '';
$siteBase = dirname(__DIR__, 2);
foreach ($argv as $argument) {
    if (strpos($argument, '--key=') === 0) {
        $key = substr($argument, 6);
    } elseif (strpos($argument, '--site-base=') === 0) {
        $siteBase = substr($argument, 12);
    }
}
$siteBase = realpath($siteBase);
if ($siteBase === false || !is_file($siteBase . '/qa-config.php') || !is_file($siteBase . '/qa-include/qa-base.php')) {
    fwrite(STDERR, "Invalid Q2A site base.\n");
    exit(1);
}
if (!defined('QA_BASE_DIR')) {
    define('QA_BASE_DIR', $siteBase . '/');
}
require_once QA_BASE_DIR . 'qa-include/qa-base.php';
$expected = (string)qa_opt('smp_cron_key');
if ($expected === '' || !hash_equals($expected, $key)) {
    fwrite(STDERR, "Invalid cron key.\n");
    exit(1);
}
require_once __DIR__ . '/SmpHistory.php';
$history = new SmpHistory(__DIR__ . '/');
$result = $history->postApproved(date('Y-m-d'));
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";