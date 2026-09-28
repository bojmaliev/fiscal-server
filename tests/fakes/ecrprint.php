#!/usr/bin/env php
<?php

/**
 * Test stand-in for bin/accent/ecrprint.exe.
 *
 * tests/run.php copies this over the real exe in a scratch copy of bin/ and
 * marks it executable. It leaves behind what the real exe leaves behind, with
 * the printer's answers taken from fake.json beside it instead of a COM port:
 *
 *  - reads the file named by <defaultInputFileName> in ecrprint.xml, both
 *    resolved against the working directory, as the real exe does;
 *  - writes one entry per input line to ecrprint.rs (link outcome),
 *    ecrprint.err (six status bytes) and ecrprint.out (the printer's reply),
 *    and a blank entry in all three for a line too short to send;
 *  - prints its two version banners, then anything under "console".
 *
 * fake.json keys, all optional:
 *   replies   command character => reply text (UTF-8, written as
 *             Windows-1251). Default "P".
 *   outcomes  command character => ecrprint.rs entry. Default "1, OK".
 *   console   extra console output, which the real exe prints for every
 *             exception it swallows.
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
$cwd      = getcwd();
$scenario = json_decode((string) @file_get_contents($here . '/fake.json'), true) ?: [];

$setup     = @simplexml_load_file($cwd . '/ecrprint.xml');
$inputName = $setup ? (string) $setup->defaultInputFileName : '';
$input     = (string) @file_get_contents($cwd . '/' . $inputName);

file_put_contents($here . '/calls.jsonl', json_encode([
    'cwd'   => $cwd,
    'argv'  => array_slice($argv, 1),
    'input' => base64_encode($input),
]) . "\n", FILE_APPEND);

echo "Ecr DLL Version: 1.0.0.0\r\nEcr EXE Version: 1.0.0.0\r\n";
echo $scenario['console'] ?? '';

// Split the way File.ReadAllLines does: the final terminator ends the last
// line rather than starting another one.
$lines = preg_split("/\r\n|\r|\n/", $input);

if (end($lines) === '') {
    array_pop($lines);
}

$outcomes = $statuses = $replies = [];

foreach ($lines as $line) {
    if (strlen($line) < 2) {
        $outcomes[] = $statuses[] = $replies[] = '';
        continue;
    }

    $command    = $line[1];
    $outcomes[] = $scenario['outcomes'][$command] ?? '1, OK';
    $statuses[] = '128, 128, 128, 128, 134, 154';
    $replies[]  = $scenario['replies'][$command] ?? 'P';
}

$write = static function (string $name, array $entries) use ($cwd): void {
    $text = $entries === [] ? '' : implode("\r\n", $entries) . "\r\n";
    file_put_contents($cwd . '/' . $name, mb_convert_encoding($text, 'Windows-1251', 'UTF-8'));
};

$write('ecrprint.rs', $outcomes);
$write('ecrprint.err', $statuses);
$write('ecrprint.out', $replies);

exit(0);
