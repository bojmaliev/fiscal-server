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
    'ReceiptResult',
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

function copyTree(string $from, string $to): void
{
    @mkdir($to, 0777, true);

    foreach (array_diff((array) scandir($from), ['.', '..']) as $entry) {
        $source = $from . DIRECTORY_SEPARATOR . $entry;
        $target = $to . DIRECTORY_SEPARATOR . $entry;

        is_dir($source) ? copyTree($source, $target) : copy($source, $target);
    }
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

/**
 * Every file and directory under $dir with its permissions, size, content
 * hash and modification time — enough to tell whether anything was created,
 * removed, rewritten or chmod-ed.
 *
 * @return array<string, string>
 */
function snapshot(string $dir): array
{
    $entries = [];

    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iterator as $path => $info) {
        $entries[substr($path, strlen($dir))] = $info->isDir()
            ? sprintf('dir %o', $info->getPerms() & 0777)
            : sprintf('%o %d %s %d', $info->getPerms() & 0777, $info->getSize(), sha1_file($path), $info->getMTime());
    }

    ksort($entries);

    return $entries;
}

// ---------------------------------------------------------------------------
// HTTP: index.php, served the way serve.bat serves it
// ---------------------------------------------------------------------------

/** @var resource[] Built-in servers, stopped when the run ends. */
$servers = [];

/**
 * Copies index.php and drivers/ into a scratch till and serves it with PHP's
 * built-in server, as serve.bat serves the real one. PRINTER_BASE_PATH is
 * index.php's own directory, so every request works on the scratch bin/.
 * The server's log goes next to the till rather than into it, so that a
 * snapshot of the till sees only what the request did.
 *
 * @return int The port it listens on.
 */
function serve(string $base): int
{
    copy(ROOT . '/index.php', "{$base}/index.php");
    copyTree(ROOT . '/drivers', "{$base}/drivers");

    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $name   = stream_socket_get_name($socket, false);
    $port   = (int) substr($name, strrpos($name, ':') + 1);
    fclose($socket);

    $log = "{$base}.server.log";
    $GLOBALS['scratch'][] = $log;
    $GLOBALS['servers'][] = proc_open(
        [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $base],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        $base
    );

    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

        if ($probe !== false) {
            fclose($probe);

            return $port;
        }

        usleep(50000);
    }

    throw new Failure("the built-in server did not come up on port {$port}");
}

/**
 * Sends one request, as HTTP/1.0 so the server closes the connection when it
 * is done and the body needs no de-chunking. A JSON body makes it a POST.
 *
 * @return array{status: int, headers: array<string, string>, body: string}
 */
function request(int $port, string $target, ?array $json = null): array
{
    $socket = fsockopen('127.0.0.1', $port, $errno, $error, 5);

    if ($socket === false) {
        throw new Failure("cannot connect to port {$port}: {$error}");
    }

    stream_set_timeout($socket, 30);

    $payload = $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE);
    $head    = ($json === null ? 'GET' : 'POST') . " {$target} HTTP/1.0\r\nHost: 127.0.0.1\r\n";

    if ($json !== null) {
        $head .= "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n";
    }

    fwrite($socket, $head . "\r\n" . $payload);
    $response = (string) stream_get_contents($socket);
    fclose($socket);

    [$rawHead, $body] = array_pad(explode("\r\n\r\n", $response, 2), 2, '');
    $lines   = explode("\r\n", $rawHead);
    $status  = preg_match('#^HTTP/\S+ (\d{3})#', (string) array_shift($lines), $m) ? (int) $m[1] : 0;
    $headers = [];

    foreach ($lines as $line) {
        [$field, $value] = array_pad(explode(':', $line, 2), 2, '');
        $headers[strtolower(trim($field))] = trim($value);
    }

    return ['status' => $status, 'headers' => $headers, 'body' => $body];
}

/** The VERSION index.php declares. */
function indexVersion(): string
{
    return preg_match("/^const VERSION\s*=\s*'([^']*)';/m", (string) file_get_contents(ROOT . '/index.php'), $m)
        ? $m[1]
        : '';
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
// Receipt number
// ---------------------------------------------------------------------------

/**
 * How a SY250 answers a receipt that goes through: the shape of the live run
 * quoted in API.md, except that the close answer carries a different number
 * from the open one here, so a test can tell which of the two was read.
 */
const SY250_REPLIES = ['0' => "P\t1634\t", '1' => "P\t1634\t", '5' => "R\t0.00\t", '8' => "P\t1635\t"];

test('sy250: the receipt number is the slip number the close command answers with', function () {
    $base = till();
    scenario($base, 'sy250', ['replies' => SY250_REPLIES]);

    $receipt = driver('sy250', $base)->fiscal(ITEMS, PAYMENTS);

    same(['receipt_number' => '1635', 'fiscal_memory_serial' => null], $receipt->toArray());
});

test('sy250: no receipt number unless the close answer carries one', function () {
    foreach (['', 'P', "P\t", "P\t16a4\t", "P\t-1634\t", "R\t1634\t", "P\t\t1634\t"] as $close) {
        $base = till();
        scenario($base, 'sy250', ['replies' => ['8' => $close] + SY250_REPLIES]);

        same(
            ['receipt_number' => null, 'fiscal_memory_serial' => null],
            driver('sy250', $base)->fiscal(ITEMS, PAYMENTS)->toArray(),
            'close answered ' . show($close)
        );
    }
});

test('fp700: reports no receipt number, whatever the printer answers', function () {
    // Nothing in docs/accent describes the FP700's answers, so even an answer
    // shaped like a SY250 slip number must not be taken for one.
    $base = till();
    scenario($base, 'fp700', ['replies' => ['0' => '1634,0012', '8' => "P\t1634\t"]]);

    same(['receipt_number' => null, 'fiscal_memory_serial' => null], driver('fp700', $base)->fiscal(ITEMS, PAYMENTS)->toArray());
});

foreach (['severec' => ['1', 'DU330999004'], 'razvigorec' => ['3', 'DU125000002']] as $name => [$number, $serial]) {
    test("{$name}: the receipt number and serial come from Result.out", function () use ($name, $number, $serial) {
        // What the fake writes by default…
        $base = till();
        same(
            ['receipt_number' => '42', 'fiscal_memory_serial' => 'DU330999004'],
            driver($name, $base)->fiscal(ITEMS, PAYMENTS)->toArray(),
            'the default success report'
        );

        // …and the vendor's own sample, byte for byte.
        $base   = till();
        $sample = mb_convert_encoding(file_get_contents(ROOT . "/docs/{$name}/Result.out"), 'UTF-8', 'Windows-1251');
        scenario($base, $name, ['result' => $sample]);

        same(
            ['receipt_number' => $number, 'fiscal_memory_serial' => $serial],
            driver($name, $base)->fiscal(ITEMS, PAYMENTS)->toArray(),
            "docs/{$name}/Result.out"
        );
    });

    test("{$name}: no receipt number or serial unless Result.out states them", function () use ($name) {
        $serialLine = "Сериски број на фискална меморија: DU330999004\r\n";

        foreach ([
            'no Result.out at all'    => [null, null, null],
            'only the command line'   => ["Команда: x.in\r\n", null, null],
            'two different numbers'   => ["Команда: x.in\r\n{$serialLine}Број на фискална сметка: 42\r\nБрој на фискална сметка: 43\r\n", null, 'DU330999004'],
            'the same number twice'   => ["{$serialLine}Број на фискална сметка: 42\r\nБрој на фискална сметка: 42\r\n", '42', 'DU330999004'],
            'a number with a gap'     => ["{$serialLine}Број на фискална сметка: 4 2\r\n", null, 'DU330999004'],
            'an empty number'         => ["{$serialLine}Број на фискална сметка:\r\n", null, 'DU330999004'],
            'a serial in two words'   => ["Сериски број на фискална меморија: DU 330999004\r\nБрој на фискална сметка: 42\r\n", '42', null],
        ] as $case => [$result, $number, $serial]) {
            $base = till();
            scenario($base, $name, ['result' => $result]);

            same(
                ['receipt_number' => $number, 'fiscal_memory_serial' => $serial],
                driver($name, $base)->fiscal(ITEMS, PAYMENTS)->toArray(),
                $case
            );
        }
    });
}

test('http: fiscal answers with the receipt as JSON, the reports keep an empty 200', function () {
    $base = till();
    $port = serve($base);
    $sale = ['items' => ITEMS, 'payments' => PAYMENTS];

    scenario($base, 'sy250', ['replies' => SY250_REPLIES]);

    foreach ([
        'fp700'   => '{"receipt_number":null,"fiscal_memory_serial":null}',
        'sy250'   => '{"receipt_number":"1635","fiscal_memory_serial":null}',
        'severec' => '{"receipt_number":"42","fiscal_memory_serial":"DU330999004"}',
    ] as $driver => $body) {
        $response = request($port, "/index.php?q=fiscal&driver={$driver}", $sale);

        same(200, $response['status'], "status of q=fiscal on {$driver}");
        same('application/json; charset=utf-8', $response['headers']['content-type'] ?? null, "Content-Type on {$driver}");
        same($body, $response['body'], "body of q=fiscal on {$driver}");
    }

    // A receipt that printed stays a success when its number cannot be read.
    scenario($base, 'sy250', ['replies' => ['8' => 'P'] + SY250_REPLIES]);
    $response = request($port, '/index.php?q=fiscal&driver=sy250', $sale);
    same([200, '{"receipt_number":null,"fiscal_memory_serial":null}'], [$response['status'], $response['body']], 'an unreadable close answer');

    foreach ([
        ['/index.php?q=close-day-report&driver=sy250', null],
        ['/index.php?q=control-report&driver=sy250', null],
        ['/index.php?q=deposit-withdraw-money&driver=sy250', ['amount' => 500]],
        ['/index.php?q=period-short-report&driver=sy250', ['from' => '2026-08-01', 'to' => '2026-08-11']],
    ] as [$target, $json]) {
        $response = request($port, $target, $json);

        same([200, ''], [$response['status'], $response['body']], $target);
        check(!isset($response['headers']['content-type']) || !str_contains($response['headers']['content-type'], 'json'), "{$target} says it sends JSON");
    }

    $response = request($port, '/index.php?q=fiscal&driver=sy250', ['items' => ITEMS]);
    same([400, '{"error":"items and payments are required"}'], [$response['status'], $response['body']], 'q=fiscal without payments');
});

// ---------------------------------------------------------------------------
// q=ping
// ---------------------------------------------------------------------------

test('ping: answers without touching a file or running an executable', function () {
    $base    = till();
    $port    = serve($base);
    $version = indexVersion();

    check(preg_match('/^\d+\.\d+\.\d+$/', $version) === 1, 'index.php declares no VERSION like 1.2.3: ' . show($version));

    $before = snapshot($base);

    // Constructing a driver reads its vendor config file and, given a port,
    // rewrites it; printing runs the executable. With all six locked away, a
    // ping that did either would come back 500 instead of 200.
    $locked = [];

    foreach ([
        'accent/ecrprint.xml',
        'accent/ecrprint.exe',
        'severec/FISKAL.INI',
        'severec/Severec.exe',
        'razvigorec/Razvigorec.ini',
        'razvigorec/Razvigorec.exe',
    ] as $file) {
        $path = "{$base}/bin/{$file}";

        check(is_file($path) && !is_link($path), "{$file} is not a plain file");
        $locked[$path] = fileperms($path) & 0777;
        chmod($path, 0);
    }

    try {
        foreach ([
            '/index.php?q=ping'                                    => 'fp700',
            '/index.php?q=ping&driver=sy250&port=COM7&speed=19200' => 'sy250',
            '/index.php?q=ping&driver=SEVEREC&port=com4&speed=5'   => 'severec',
            '/index.php?q=ping&driver=razvigorec&port=COM5'        => 'razvigorec',
        ] as $target => $driver) {
            $response = request($port, $target);

            same(200, $response['status'], "status of {$target}");
            same('application/json; charset=utf-8', $response['headers']['content-type'] ?? null, "Content-Type of {$target}");
            same($driver, $response['headers']['x-fiscal-driver'] ?? null, "X-Fiscal-Driver of {$target}");
            same(
                ['ok' => true, 'service' => 'fiscal-server', 'version' => $version, 'driver' => $driver],
                json_decode($response['body'], true),
                "body of {$target}"
            );
        }
    } finally {
        foreach ($locked as $path => $mode) {
            chmod($path, $mode);
        }
    }

    same($before, snapshot($base), 'the till after four pings');
});

test('ping: refuses exactly what the printing routes refuse', function () {
    $base = till();
    $port = serve($base);

    foreach ([
        'driver=bogus'                 => 'Unknown printer driver: bogus',
        'port=LPT1'                    => 'Invalid port. Expected COM1 to COM999.',
        'port=COM1000'                 => 'Invalid port. Expected COM1 to COM999.',
        'speed=fast'                   => 'Invalid speed. Expected digits only.',
        'driver=razvigorec&speed=9600' => 'The Razvigorec driver has no speed setting; omit "speed".',
    ] as $query => $error) {
        foreach (['ping', 'control-report'] as $route) {
            $response = request($port, "/index.php?q={$route}&{$query}");

            same(400, $response['status'], "status of q={$route}&{$query}");
            same(['error' => $error], json_decode($response['body'], true), "body of q={$route}&{$query}");
        }
    }

    // As on every route, the settings may come in the JSON body instead, and
    // the query string wins when both are given.
    $response = request($port, '/index.php?q=ping', ['driver' => 'razvigorec', 'speed' => '9600']);
    same(400, $response['status'], 'status with the settings in the body');

    $response = request($port, '/index.php?q=ping&driver=severec', ['driver' => 'sy250']);
    same('severec', json_decode($response['body'], true)['driver'] ?? null, 'driver named in both');

    same([], array_merge(runs($base, 'fp700'), runs($base, 'severec'), runs($base, 'razvigorec')), 'executable runs');
});

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
    foreach ($GLOBALS['servers'] as $server) {
        proc_terminate($server);
        proc_close($server);
    }

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
