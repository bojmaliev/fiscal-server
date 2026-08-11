<?php
// CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");
header("Access-Control-Max-Age: 86400");

// Handle preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}


define('TAB', chr(9));
define('NL', chr(10));

require_once __DIR__ . '/drivers/PrinterDriver.php';
require_once __DIR__ . '/drivers/ProcessRunner.php';
require_once __DIR__ . '/drivers/Accent/EcrPrintDriver.php';
require_once __DIR__ . '/drivers/Accent/FP700Driver.php';
require_once __DIR__ . '/drivers/Accent/SY250Driver.php';
require_once __DIR__ . '/drivers/Duna/DunaResult.php';
require_once __DIR__ . '/drivers/Duna/SeverecDriver.php';
require_once __DIR__ . '/drivers/Duna/RazvigorecDriver.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

// Fallbacks used when a request does not say otherwise. PRINTER_PORT and
// PRINTER_SPEED = null mean "leave the vendor config files exactly as they are",
// so an installation configured by hand keeps working untouched.
const PRINTER_DRIVER = 'fp700'; // 'fp700', 'sy250', 'severec' or 'razvigorec'
const PRINTER_PORT   = null;    // e.g. 'COM4'
const PRINTER_SPEED  = null;    // ecrprint: literal baud ('9600'). Severec: vendor code ('5').
const PRINTER_BASE_PATH = __DIR__;

// ---------------------------------------------------------------------------
// Driver factory
// ---------------------------------------------------------------------------

/**
 * Builds the driver for this request.
 *
 * The printer and serial port are selectable per request, so the calling
 * application can drive any till without anything being configured on the
 * machine itself:
 *
 *   ?q=fiscal&driver=severec&port=COM4
 *
 * All three keys — driver, port, speed — are optional and may be sent either
 * as query parameters or as fields in the JSON body, the query string winning
 * when both are present. Anything omitted falls back to the constants above.
 */
function createDriver(): PrinterDriver
{
    $driver = strtolower(requestSetting('driver') ?? PRINTER_DRIVER);
    $port   = requestSetting('port')  ?? PRINTER_PORT;
    $speed  = requestSetting('speed') ?? PRINTER_SPEED;

    // The port is written into a vendor config file, so keep it to COM1–COM999.
    if ($port !== null && !preg_match('/^COM\d{1,3}$/i', $port)) {
        throw new \InvalidArgumentException('Invalid port. Expected COM1 to COM999.');
    }

    if ($speed !== null && !ctype_digit($speed)) {
        throw new \InvalidArgumentException('Invalid speed. Expected digits only.');
    }

    $port = $port === null ? null : strtoupper($port);

    return match ($driver) {
        'fp700'      => new FP700Driver(PRINTER_BASE_PATH, $port, $speed),
        'sy250'      => new SY250Driver(PRINTER_BASE_PATH, $port, $speed),
        'severec'    => new SeverecDriver(PRINTER_BASE_PATH, $port, $speed),
        'razvigorec' => new RazvigorecDriver(PRINTER_BASE_PATH, $port, $speed),
        default      => throw new \InvalidArgumentException('Unknown printer driver: ' . $driver),
    };
}

// ---------------------------------------------------------------------------
// Route handlers
// ---------------------------------------------------------------------------

function handleFiscal(PrinterDriver $driver): void
{
    $input    = jsonInput();
    $items    = $input['items']    ?? [];
    $payments = $input['payments'] ?? [];

    if (empty($items) || empty($payments)) {
        throw new \InvalidArgumentException('items and payments are required');
    }

    $driver->fiscal($items, $payments);
}

function handleDepositWithdrawMoney(PrinterDriver $driver): void
{
    $input  = jsonInput();
    $amount = (float) ($input['amount'] ?? 0);
    $driver->depositWithdrawMoney($amount);
}

function handlePeriodShortReport(PrinterDriver $driver): void
{
    $input = jsonInput();
    [$from, $to] = parseDates($input);
    $driver->periodShortReport($from, $to);
}

// ---------------------------------------------------------------------------
// Utilities
// ---------------------------------------------------------------------------

function jsonInput(): array
{
    static $input = null;

    if ($input === null) {
        $input = json_decode(file_get_contents('php://input'), true) ?? [];
    }

    return $input;
}

/**
 * Reads an optional per-request setting from the query string, falling back to
 * the JSON body. Returns null when absent or blank, so callers can safely send
 * an empty value to mean "use the default".
 */
function requestSetting(string $key): ?string
{
    $value = $_GET[$key] ?? jsonInput()[$key] ?? null;

    if ($value === null || !is_scalar($value) || (string) $value === '') {
        return null;
    }

    return (string) $value;
}

/**
 * Validates and returns [from, to] dates from the input array.
 * Expects YYYY-MM-DD format.
 *
 * @throws \InvalidArgumentException on invalid dates.
 */
function parseDates(array $input): array
{
    $fromParts = explode('-', $input['from'] ?? '');
    $toParts   = explode('-', $input['to']   ?? '');

    if (
        count($fromParts) !== 3 || count($toParts) !== 3 ||
        !checkdate((int) $fromParts[1], (int) $fromParts[2], (int) $fromParts[0]) ||
        !checkdate((int) $toParts[1],   (int) $toParts[2],   (int) $toParts[0])
    ) {
        throw new \InvalidArgumentException('Invalid date. Expected YYYY-MM-DD format.');
    }

    return [$input['from'], $input['to']];
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

try {
    if (!isset($_GET['q'])) {
        throw new \InvalidArgumentException('Missing route parameter');
    }

    $driver = createDriver();

    match ($_GET['q']) {
        'close-day-report'       => $driver->closeDayReport(),
        'control-report'         => $driver->controlReport(),
        'deposit-withdraw-money' => handleDepositWithdrawMoney($driver),
        'period-short-report'    => handlePeriodShortReport($driver),
        'fiscal'                 => handleFiscal($driver),
        default                  => throw new \InvalidArgumentException("Unknown route: {$_GET['q']}"),
    };

    http_response_code(200);

} catch (\InvalidArgumentException $e) {
    respondError(400, $e->getMessage());
} catch (\Throwable $e) {
    respondError(500, $e->getMessage());
}

/**
 * Sends the failure reason to the caller instead of a bare status code — the
 * vendor executables report what went wrong (wrong COM port, printer offline,
 * rejected command) only in their output files, and that text is the whole
 * value of reading them.
 */
function respondError(int $status, string $message): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
}
