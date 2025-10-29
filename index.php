<?php 
define('TAB', chr(9));
define('NL', chr(10));
define('MKD_ITEM', chr(40));


if(!isset($_GET['q'])){
    return error();
}

$q = $_GET['q'];


return match($q){
    'control-report'=> controlReport(),
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

function controlReport(){
    $command = singleCommand('E', '2');
    execute($command);
}

function win1251(string $content){
    return mb_convert_encoding($content, 'Windows-1251', 'UTF-8');
}

function itemToData(array $item): string {
    $name = win1251($item['name']);
    $description = win1251($item['description'] ?? '');
    $vat = vat($item['vat'] ?? 'A');
    $price = $item['price'];
    $quantity = $item['quantity'] ?? 1;
    $mkd = $item['mkd'] ?? false;

    return $name.($description ? NL.$description : '').TAB.($mkd ? MKD_ITEM : '').$vat.$price.'.00*'.$quantity.'.000';
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

    $footer = win1251($input['footer'] ?? '');

    if(count($items) == 0 || count($payments) == 0){
        http_response_code(400);
        return "Error";
    }
    $commands = [
        singleCommand('0', '1,0000,1'),
        ...array_map(fn(array $item)=>  singleCommand('1', itemToData($item)), $items),
        ...array_map(fn(array $item)=>  singleCommand('5', paymentToData($item)), $payments),
        singleCommand('8', $footer),
    ];
    execute(implode(NL, $commands));
    http_response_code(200);
    return "";
}
$seq = 32;
function singleCommand(string $command, string $data = ''){
    global $seq;
    $current = $seq;
    $seq++;
    return chr((int)$current).$command.$data;
}

function execute(string $content){
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
