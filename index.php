<?php 


if(!isset($_GET['q'])){
    return error();
}

$q = $_GET['q'];

return match($q){
    'control-report'=> controlReport(),
    'fiscal'=> fiscal($_POST['items']),
    default=> error()
};

$sequence = '';

function error(){
    http_response_code(400);
    return "";
}

function controlReport(){
    $command = singleCommand('E', '2');
    execute($command);
}

// function fiscal(array $items){
//     $commands = [
//         singleCommand(),
//         singleCommand(),
//         singleCommand(),
//         singleCommand(),
//         singleCommand(),
//     ];
//     execute(implode(PHP_EOL, $commands));

// }
$seq = 32;
function singleCommand(string $command, string $data){
    global $seq;
    $current = $seq;
    $seq++;
    return chr($current).$command.$data;
}

function execute(string $command){
    $content = mb_convert_encoding($command, 'Windows-1251', 'UTF-8');
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
