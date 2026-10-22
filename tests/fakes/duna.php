#!/usr/bin/env php
<?php

/**
 * Test stand-in for the two Duna executables, bin/severec/Severec.exe and
 * bin/razvigorec/Razvigorec.exe.
 *
 * tests/run.php copies this over both in a scratch copy of bin/ and marks it
 * executable. Like the real ones it takes the command file as its only
 * argument and reports next to itself rather than in the working directory:
 * Result.out (Windows-1251) and, on failure, a file under Log\Error\.
 *
 * fake.json keys, all optional:
 *   result     text for Result.out (UTF-8). Default: the success report
 *              the vendor samples show (docs/severec/Result.out and
 *              docs/razvigorec/Result.out), for receipt 42. null writes no
 *              Result.out at all.
 *   error_log  text for a new file in Log/Error/.
 *
 * Every run is appended to calls.jsonl beside this file, so a test can check
 * what the exe was given and where it was started.
 */

// serve.bat serves the whole checkout, this directory included.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

$here     = __DIR__;
$scenario = json_decode((string) @file_get_contents($here . '/fake.json'), true) ?: [];
$argument = $argv[1] ?? '';
$input    = (string) @file_get_contents($argument);

file_put_contents($here . '/calls.jsonl', json_encode([
    'cwd'   => getcwd(),
    'argv'  => array_slice($argv, 1),
    'input' => base64_encode($input),
]) . "\n", FILE_APPEND);

$result = array_key_exists('result', $scenario)
    ? $scenario['result']
    : "Команда: " . basename($argument) . "\r\n"
      . "Сериски број на фискална меморија: DU330999004\r\n"
      . "Број на фискална сметка: 42\r\n";

if ($result !== null) {
    file_put_contents($here . '/Result.out', mb_convert_encoding($result, 'Windows-1251', 'UTF-8'));
}

if (isset($scenario['error_log'])) {
    @mkdir($here . '/Log/Error', 0777, true);
    file_put_contents($here . '/Log/Error/' . date('Ymd') . '.txt', $scenario['error_log']);
}

exit(0);
