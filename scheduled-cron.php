<?php

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}
$key = '';
$contentType = '';
foreach ($argv as $argument) {
    if (strpos($argument, '--key=') === 0) {
        $key = substr($argument, 6);
    } elseif (strpos($argument, '--type=') === 0) {
        $contentType = substr($argument, 7);
    }
}
require_once dirname(dirname(dirname(__FILE__))) . '/qa-include/qa-base.php';
$expected = (string)qa_opt('smp_cron_key');
if ($expected === '' || !hash_equals($expected, $key)) {
    fwrite(STDERR, "Invalid cron key.\n");
    exit(1);
}
require_once __DIR__ . '/SmpScheduled.php';
$scheduler = new SmpScheduled(__DIR__ . '/');
$result = $scheduler->postMotivation($contentType, date('Y-m-d'));
echo json_encode($result, JSON_UNESCAPED_UNICODE), "\n";