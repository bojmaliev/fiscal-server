<?php

/**
 * Test runner — nothing to install beyond PHP itself:
 *
 *     php tests/run.php [name filter]
 *
 * Exits non-zero when any test fails.
 *
 * The drivers run for real, file writes and process launches included, each
 * test against a scratch install of its own: the three vendor config files
 * copied from bin/, and each vendor executable replaced by a fake from
 * tests/fakes — a PHP script with a "#!/usr/bin/env php" line, marked
 * executable, that leaves behind the files the real exe leaves behind
 * (ecrprint.rs/.out/.err, Result.out) and records what it was given. That
 * needs a system that honours shebang lines, i.e. macOS or Linux rather than
 * the Windows tills the server itself runs on.
 */

// serve.bat serves the whole checkout, this directory included.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit();
}

const ROOT = __DIR__ . DIRECTORY_SEPARATOR . '..';

foreach ([
    'PrinterDriver',
    'ProcessRunner',
    'Accent/EcrPrintDriver',
    'Accent/FP700Driver',
    'Accent/SY250Driver',
    'Duna/DunaResult',
    'Duna/SeverecDriver',
    'Duna/RazvigorecDriver',
] as $file) {
    require_once ROOT . "/drivers/{$file}.php";
}

// ---------------------------------------------------------------------------
// Harness
// ---------------------------------------------------------------------------

final class Failure extends \Exception
{
}

/** @var array<string, callable> */
$tests = [];

/** @var string[] Scratch directories, removed when the run ends. */
$scratch = [];

function test(string $name, callable $body): void
{
    $GLOBALS['tests'][$name] = $body;
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new Failure($message);
    }
}

function same(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new Failure(
            ($what === '' ? '' : $what . "\n")
            . '  expected: ' . show($expected) . "\n"
            . '  actual:   ' . show($actual)
        );
    }
}

function throwsLike(string $class, string $fragment, callable $body): void
{
    try {
        $body();
    } catch (\Throwable $e) {
        check($e instanceof $class, "expected {$class}, got " . get_class($e) . ': ' . $e->getMessage());
        check(
            str_contains($e->getMessage(), $fragment),
            'expected a message containing ' . show($fragment) . ', got ' . show($e->getMessage())
        );

        return;
    }

    throw new Failure("expected {$class}, nothing was thrown");
}

/**
 * Renders a value for a failure message. Command bytes are escaped the way
 * ecrprint.log escapes them, so a difference in a separator or a
 * Windows-1251 byte is visible.
 */
function show(mixed $value): string
{
    if (is_string($value)) {
        return '"' . preg_replace_callback(
            '/[^\x20-\x7e]|["\\\\]/',
            fn(array $m) => match ($m[0]) {
                "\t"    => '\t',
                "\r"    => '\r',
                "\n"    => '\n',
                '"'     => '\"',
                '\\'    => '\\\\',
                default => sprintf('\x%02X', ord($m[0])),
            },
            $value
        ) . '"';
    }

    if (is_array($value)) {
        $list  = $value === [] || array_keys($value) === range(0, count($value) - 1);
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = ($list ? '' : show($key) . ' => ') . show($item);
        }

        return '[' . implode(', ', $parts) . ']';
    }

    return var_export($value, true);
}

function scratchDir(string $label): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'fiscal-server-' . $label . '-' . bin2hex(random_bytes(4));

    mkdir($dir, 0777, true);
    $GLOBALS['scratch'][] = $dir;

    return $dir;
}

/**
 * Deletes a scratch directory. A link is unlinked and never followed, and
 * nothing is chmod-ed on the way out: both would reach whatever a link
 * points at, outside the scratch directory.
 */
function removeTree(string $path): void
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);

        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
        removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }

    @rmdir($path);
}

// ---------------------------------------------------------------------------
// Scratch tills
// ---------------------------------------------------------------------------

/** Where each driver's executable, config and output files live under bin/. */
const EXE_DIRS = [
    'fp700'      => 'accent',
    'sy250'      => 'accent',
    'severec'    => 'severec',
    'razvigorec' => 'razvigorec',
];

/**
 * A scratch install: the three vendor config files copied from bin/, the
 * fakes standing in for the three executables, and both sequence counters
 * at 40, so the first command of a batch goes out with sequence byte 41 (")").
 */
function till(): string
{
    $base = scratchDir('till');

    foreach (['accent/ecrprint.xml', 'severec/FISKAL.INI', 'razvigorec/Razvigorec.ini'] as $file) {
        @mkdir(dirname("{$base}/bin/{$file}"), 0777, true);
        copy(ROOT . "/bin/{$file}", "{$base}/bin/{$file}");
    }

    foreach ([
        'accent/ecrprint.exe'       => 'ecrprint.php',
        'severec/Severec.exe'       => 'duna.php',
        'razvigorec/Razvigorec.exe' => 'duna.php',
    ] as $exe => $fake) {
        copy(ROOT . "/tests/fakes/{$fake}", "{$base}/bin/{$exe}");
        chmod("{$base}/bin/{$exe}", 0755);
    }

    file_put_contents("{$base}/bin/accent/seq.txt", '40');
    file_put_contents("{$base}/bin/severec/severec_seq.txt", '40');

    return $base;
}

function driver(string $name, string $base): PrinterDriver
{
    return match ($name) {
        'fp700'      => new FP700Driver($base, 'COM3'),
        'sy250'      => new SY250Driver($base, 'COM3'),
        'severec'    => new SeverecDriver($base, 'COM4'),
        'razvigorec' => new RazvigorecDriver($base, 'COM5'),
    };
}

/** Sets what the fake executable behind $driver answers — see tests/fakes. */
function scenario(string $base, string $driver, array $scenario): void
{
    file_put_contents("{$base}/bin/" . EXE_DIRS[$driver] . '/fake.json', json_encode($scenario));
}

/**
 * Every run of the fake executable behind $driver.
 *
 * @return array<int, array{cwd: string, argv: string[], input: string}>
 */
function runs(string $base, string $driver): array
{
    $log = "{$base}/bin/" . EXE_DIRS[$driver] . '/calls.jsonl';

    if (!is_file($log)) {
        return [];
    }

    return array_map(static function (string $line): array {
        $run          = json_decode($line, true);
        $run['input'] = base64_decode($run['input']);

        return $run;
    }, file($log, FILE_IGNORE_NEW_LINES));
}

/** The command file of the one run of the executable behind $driver. */
function sent(string $base, string $driver): string
{
    $runs = runs($base, $driver);

    same(1, count($runs), "runs of the {$driver} executable");

    return $runs[0]['input'];
}

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

/**
 * A receipt that touches every item and payment field: lower-case Cyrillic
 * (upper-cased on the way out), all four VAT groups, a Macedonian product,
 * defaults left out, fractional quantities and prices, card plus cash.
 */
const ITEMS = [
    ['name' => 'Смоки', 'vat' => 'A', 'price' => 10, 'quantity' => 1, 'mkd' => false],
    ['name' => 'јогурт', 'vat' => 'B', 'price' => 45.5, 'quantity' => 2, 'mkd' => true],
    ['name' => 'Leb', 'vat' => 'V', 'price' => 30, 'quantity' => 0.5],
    ['name' => 'Вода', 'vat' => 'G', 'price' => 20.1, 'quantity' => 1.25],
];

const PAYMENTS = [
    ['amount' => 100, 'cash' => false],
    ['amount' => 50.35, 'cash' => true],
];

/**
 * What fiscal() sends for ITEMS and PAYMENTS with both counters at 40, as
 * captured from the drivers at 4c98806, before this suite existed. A change
 * to any byte of a sale receipt has to show up here.
 */
const FISCAL_BATCHES = [
    'fp700'      => ")01,0000,1\r\n*1\xD1\xCC\xCE\xCA\xC8\t\xC010.00*1.000\r\n+1\xA3\xCE\xC3\xD3\xD0\xD2\t@\xC145.50*2.000\r\n,1LEB\t\xC230.00*0.500\r\n-1\xC2\xCE\xC4\xC0\t\xC320.10*1.250\r\n.5\tD100.00\r\n/5\tP50.35\r\n08\r\n\r\n",
    'sy250'      => ")01\t1\t\t0\t\r\n*1\xD1\xCC\xCE\xCA\xC8\t1\t10.00\t1.000\t0\t\t\t\r\n+1\xA3\xCE\xC3\xD3\xD0\xD2\t2\t45.50\t2.000\t1\t\t\t\r\n,1LEB\t3\t30.00\t0.500\t0\t\t\t\r\n-1\xC2\xCE\xC4\xC0\t4\t20.10\t1.250\t0\t\t\t\r\n.51\t100.00\t\r\n/50\t50.35\t\r\n08\r\n",
    'severec'    => ")01,0001,1\r\n*1\xD1\xCC\xCE\xCA\xC8\tA10.00*1.000\r\n+1\xA3\xCE\xC3\xD3\xD0\xD2\t@B45.50*2.000\r\n,1LEB\tC30.00*0.500\r\n-1\xC2\xCE\xC4\xC0\tD20.10*1.250\r\n.5\tD100.00\r\n/5\tC50.35\r\n08\r\n",
    'razvigorec' => "#F\r\n@\xD1\xCC\xCE\xCA\xC8;A;10.00;1.000\r\n@\xA3\xCE\xC3\xD3\xD0\xD2;B;45.50;2.000\r\n@LEB;C;30.00;0.500\r\n@\xC2\xCE\xC4\xC0;D;20.10;1.250\r\n#M50;100\r\n",
];

// ---------------------------------------------------------------------------
// Regression guard
// ---------------------------------------------------------------------------

foreach (FISCAL_BATCHES as $name => $batch) {
    test("{$name}: fiscal() still sends exactly the bytes it sent before", function () use ($name, $batch) {
        $base = till();

        driver($name, $base)->fiscal(ITEMS, PAYMENTS);

        same($batch, sent($base, $name));
    });
}

test('the executables are started where they look for their files', function () {
    $base = till();

    driver('sy250', $base)->controlReport();
    driver('severec', $base)->controlReport();
    driver('razvigorec', $base)->controlReport();

    // ecrprint.exe resolves ecrprint.xml and ecrprint.in against its working
    // directory and takes no arguments; the Duna exes are handed the file.
    $ecrprint = runs($base, 'sy250')[0];
    same(realpath("{$base}/bin/accent"), realpath($ecrprint['cwd']), 'ecrprint working directory');
    same([], $ecrprint['argv'], 'ecrprint arguments');

    foreach (['severec' => 'severec.in', 'razvigorec' => 'Razvigorec.txt'] as $name => $file) {
        $run = runs($base, $name)[0];
        same(["{$base}/bin/{$name}/{$file}"], $run['argv'], "{$name} arguments");
    }
});

// ---------------------------------------------------------------------------
// Failure detection — every one of these must keep failing the request
// ---------------------------------------------------------------------------

test('ecrprint: a refused command fails the request', function () {
    $base = till();
    scenario($base, 'sy250', ['replies' => ['1' => "F\t-1004\tBad input\t"]]);

    throwsLike(\RuntimeException::class, 'rejected command 2 of 8', fn() => driver('sy250', $base)->fiscal(ITEMS, PAYMENTS));
});

test('ecrprint: a command that never got a clean answer fails the request', function () {
    $base = till();
    scenario($base, 'fp700', ['outcomes' => ['8' => '2, TIMEOUT_READING']]);

    throwsLike(\RuntimeException::class, 'TIMEOUT_READING', fn() => driver('fp700', $base)->fiscal(ITEMS, PAYMENTS));
});

test('ecrprint: a complaint on the console fails the request', function () {
    $base = till();
    scenario($base, 'sy250', ['console' => "File not found - ecrprint.in\r\n"]);

    throwsLike(\RuntimeException::class, 'ecrprint reported: File not found', fn() => driver('sy250', $base)->controlReport());
});

foreach (['severec', 'razvigorec'] as $name) {
    test("{$name}: an ERROR line in Result.out fails the request", function () use ($name) {
        $base = till();
        scenario($base, $name, ['result' => "Команда: x.in\r\nERROR: Нема врска со принтерот\r\n"]);

        throwsLike(\RuntimeException::class, 'ERROR: Нема врска', fn() => driver($name, $base)->fiscal(ITEMS, PAYMENTS));
    });

    test("{$name}: a new entry in Log\\Error fails the request", function () use ($name) {
        $base = till();
        scenario($base, $name, ['result' => null, 'error_log' => 'Modul: Printer / Metod: Open / Line: 12']);

        throwsLike(\RuntimeException::class, 'wrote to its error log', fn() => driver($name, $base)->fiscal(ITEMS, PAYMENTS));
    });
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------

// The fakes start with "#!/usr/bin/env php". Put a "php" first on the PATH
// they inherit that hands over to this very interpreter, so they run under
// the PHP running the tests rather than whichever one the shell would find.
// A wrapper script rather than a link, so the scratch directory holds
// nothing that leads back to the real binary.
$interpreter = scratchDir('php');
$wrapper     = $interpreter . DIRECTORY_SEPARATOR . 'php';
file_put_contents($wrapper, "#!/bin/sh\nexec '" . str_replace("'", "'\\''", PHP_BINARY) . "' \"\$@\"\n");
chmod($wrapper, 0755);
putenv('PATH=' . $interpreter . PATH_SEPARATOR . getenv('PATH'));

register_shutdown_function(static function (): void {
    foreach ($GLOBALS['scratch'] as $dir) {
        removeTree($dir);
    }
});

$filter = $argv[1] ?? '';
$failed = 0;
$ran    = 0;

foreach ($tests as $name => $body) {
    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }

    $ran++;

    try {
        $body();
        echo "  ok    {$name}\n";
    } catch (\Throwable $e) {
        $failed++;
        $detail = $e instanceof Failure ? $e->getMessage() : get_class($e) . ': ' . $e->getMessage() . "\n" . $e->getTraceAsString();
        echo "  FAIL  {$name}\n" . preg_replace('/^/m', '        ', $detail) . "\n";
    }
}

echo $failed === 0 ? "\n{$ran} passed\n" : "\n{$failed} of {$ran} failed\n";

exit($failed === 0 ? 0 : 1);
