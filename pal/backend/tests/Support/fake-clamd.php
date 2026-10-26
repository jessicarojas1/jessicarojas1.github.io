<?php

// Minimal clamd stand-in speaking the INSTREAM protocol, for tests only.
// Usage: php fake-clamd.php [mode]   mode: normal (default) | garbage
// Prints the chosen port on stdout, then serves connections until killed.
declare(strict_types=1);

$mode = $argv[1] ?? 'normal';
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (false === $server) {
    fwrite(STDERR, "listen failed: $errstr\n");
    exit(1);
}
$name = (string) stream_socket_get_name($server, false);
fwrite(STDOUT, substr($name, strrpos($name, ':') + 1)."\n");
fflush(STDOUT);

while (true) {
    $client = @stream_socket_accept($server, -1);
    if (false === $client) {
        continue;
    }
    $command = '';
    while (!str_ends_with($command, "\0") && !feof($client)) {
        $command .= (string) fread($client, 1);
    }
    $data = '';
    while (!feof($client)) {
        $header = (string) fread($client, 4);
        if (4 !== strlen($header)) {
            break;
        }
        $length = unpack('N', $header)[1];
        if (0 === $length) {
            break;
        }
        $chunk = '';
        while (strlen($chunk) < $length && !feof($client)) {
            $chunk .= (string) fread($client, $length - strlen($chunk));
        }
        $data .= $chunk;
    }
    $reply = match (true) {
        'garbage' === $mode => "PONG?\0",
        str_contains($data, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE') => "stream: Eicar-Test-Signature FOUND\0",
        default => "stream: OK\0",
    };
    fwrite($client, $reply);
    fclose($client);
}
