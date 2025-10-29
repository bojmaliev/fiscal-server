<?php 


if(!isset($_GET['q'])){
    return error();
}

$q = $_GET['q'];

return match($q){
    'control-report'=> controlReport(),
    default=> error()
};

function error(){
    http_response_code(400);
    return "";
}

function controlReport(){
    $command = singleCommand('2', 'E', '2');
    execute($command);
}


    // $content = "2E2";
// sendCmd($content);

function singleCommand(string $sequence, string $command, string $data){
    return $sequence.$command.$data.'\n';
}

function execute(string $command){
    $content = mb_convert_encoding($command, 'Windows-1251', 'UTF-8');
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
