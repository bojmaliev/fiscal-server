<?php 
define('TAB', chr(9));
define('NL', chr(10));
define('MKD_ITEM', chr(40));


if(!isset($_GET['q'])){
    return error();
}

$q = $_GET['q'];

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);


return match($q){
    'control-report'=> controlReport(),
    'fiscal'=> fiscal($input['items'] ?? []),
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

function itemToData(array $item): string {
    $name = $item['name'];
    $vat = vat($item['vat'] ?? 'A');
    $price = $item['price'];
    $quantity = $item['quantity'] ?? 1;
    $mkd = $item['mkd'] ?? false;

    return $name.TAB.($mkd ? MKD_ITEM : '').$vat.$price.'.00*'.$quantity.'.000';
}

function fiscal(array $items){
    if(count($items) == 0){
        http_response_code(400);
        return "Error";
    }
    $commands = [
        singleCommand('0', '1,0000,1'),
        ...array_map(fn(array $item)=>  singleCommand('1', itemToData($item)), $items),
        singleCommand('5'),
        singleCommand('8'),
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

function execute(string $command){
    $content = mb_convert_encoding($command, 'Windows-1251', 'UTF-8');
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
