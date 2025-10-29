<?php 


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

$sequence = '';
const TAB = chr(9);
const NL = chr(10);
const MKD_ITEM = chr(40);
$VAT = [
    'A'=> chr(192), 
    'B'=> chr(193), 
    'V'=> chr(194), 
    'G'=> chr(195)
];

function error(){
    http_response_code(400);
    return "";
}

function controlReport(){
    $command = singleCommand('E', '2');
    execute($command);
}

function itemToData(array $item): string {
    global $VAT;
    $name = $item['name'];
    $vat = $VAT[$item['vat']];
    $price = $item['price'];
    $quantity = $item['quantity'];
    $mkd = $item['mkd'];

    return $name.TAB.($mkd ? MKD_ITEM : '').$vat.$price.'.00*'.$quantity.'000';
}

function fiscal(array $items){
    $commands = [
        singleCommand('0', '1,0000,1'),
        ...array_map(fn(array $item)=>  singleCommand('1', itemToData($item)), $items),
        singleCommand('5'),
        singleCommand('8'),
    ];
    execute(implode(NL, $commands));

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
