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

require_once __DIR__ . '/PrinterDriver.php';
require_once __DIR__ . '/EcrPrintDriver.php';
require_once __DIR__ . '/FP700Driver.php';
require_once __DIR__ . '/SY250Driver.php';

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

const PRINTER_DRIVER = 'fp700'; // 'fp700' or 'sy250'
const PRINTER_BASE_PATH = __DIR__;

// ---------------------------------------------------------------------------
// Driver factory
// ---------------------------------------------------------------------------

function createDriver(): PrinterDriver
{
    return match (PRINTER_DRIVER) {
        'fp700' => new FP700Driver(PRINTER_BASE_PATH),
        'sy250' => new SY250Driver(PRINTER_BASE_PATH),
        default  => throw new \RuntimeException('Unknown printer driver: ' . PRINTER_DRIVER),
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
    return json_decode(file_get_contents('php://input'), true) ?? [];
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
    http_response_code(400);
} catch (\Throwable $e) {
    http_response_code(500);
}
