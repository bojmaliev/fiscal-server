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


if(!isset($_GET['q'])){
    return error();
}

$q = $_GET['q'];


return match($q){
    'close-day-report'=> closeDayReport(),
    'control-report'=> controlReport(),
    'deposit-withdraw-money'=> depositWithdrawMoney(),
    'period-short-report'=> periodShortReport(),
    'fiscal'=> fiscal(),
    default=> error()
};

function vat(string $vat){
    return match($vat){
        'A'=> chr(192), 
        'B'=> chr(193), 
        'V'=> chr(194), 
        'G'=> chr(195),
        default=> throw 'Not valid vat'
    };
}

function error(){
    http_response_code(400);
    return "";
}

function closeDayReport() {
    $command = singleCommand('E');
    execute($command);
}

function controlReport(){
    $command = singleCommand('E', '2');
    execute($command);
}

function depositWithdrawMoney(){
    $input = input();
    $amount = $input['amount'] ?? 0;
    $command = singleCommand('F', $amount.".00");
    execute($command);
}


function periodShortReport() {
    $input = input();

    // Split the dates into parts
    $fromParts = explode('-', $input['from'] ?? '');
    $toParts   = explode('-', $input['to'] ?? '');

    // Validate format and actual date
    if (
        count($fromParts) !== 3 || count($toParts) !== 3 ||
        !checkdate($fromParts[1], $fromParts[2], $fromParts[0]) ||
        !checkdate($toParts[1], $toParts[2], $toParts[0])
    ) {
       throw new Exception('Error. Not valid date');
    }

    // Build dmY format with last 2 digits of year
    $from = $fromParts[2] . $fromParts[1] . substr($fromParts[0], -2);
    $to   = $toParts[2] . $toParts[1] . substr($toParts[0], -2);

    $command = singleCommand('O', $from . ',' . $to);
    execute($command);
}

function win1251(string $content){
    return mb_convert_encoding($content, 'Windows-1251', 'UTF-8');
}

function itemToData(array $item): string {
    $name = win1251($item['name']);
    $vat = vat($item['vat'] ?? 'A');
    $price = $item['price'];
    $quantity = $item['quantity'] ?? 1;
    $mkd = $item['mkd'] ?? false;

    return $name.TAB.($mkd ? '@': '').$vat.$price.'.00*'.$quantity.'.000';
}

function paymentToData(array $item): string {
    $cash = $item['cash'] ?? true;
    $amount = $item['amount'];

    return TAB.($cash ? 'P' : 'D').$amount.'.000';
}

function input(){
    return json_decode(file_get_contents('php://input'), true);
}

function fiscal(){
    $input = input();
    $items = $input['items'] ?? [];
    $payments = $input['payments'] ?? [];


    if(count($items) == 0 || count($payments) == 0){
        http_response_code(400);
        return "Error";
    }
    $commands = [
        singleCommand('0', '1,0000,1'),
        ...array_map(fn(array $item)=>  singleCommand('1', itemToData($item)), $items),
        ...array_map(fn(array $item)=>  singleCommand('5', paymentToData($item)), $payments),
        singleCommand('8'),
    ];
    execute(implode(NL, $commands));
    http_response_code(200);
    return "";
}
function getNextSeq(): int {
    $seqFile = __DIR__ . '/seq.txt';
    $seq = 32; // default start

    if (file_exists($seqFile)) {
        $seq = (int) file_get_contents($seqFile);
    }

    $seq++;
    if ($seq > 255) {
        $seq = 32; // wrap around
    }

    file_put_contents($seqFile, $seq, LOCK_EX);

    return $seq;
}

function singleCommand(string $command, string $data = ''){
    $current = getNextSeq();
    return chr((int)$current).$command.$data;
}

function execute(string $content){
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
