<?php 

$content = "2E2";
sendCmd($content);
function sendCmd(string $command){
    $content = mb_convert_encoding($command, 'Windows-1251', 'UTF-8');
    file_put_contents(__DIR__ . DIRECTORY_SEPARATOR.'ecrprint.in', $content, LOCK_EX);
    // Build full path to your executable
    $exePath =__DIR__ . DIRECTORY_SEPARATOR . 'ecrprint.exe';
    // Run the executable
    exec($exePath, $output, $returnCode);
}
