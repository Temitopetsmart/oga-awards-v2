<?php
// A tiny SMTP server for tests/run.php: accepts every message and appends it to a file.
//   php tests/fake-smtp.php <port> <output-file>
// It stops by itself after 90 idle seconds. Never use it for anything real.
[$self, $port, $out] = $argv + [null, '2525', 'fake-smtp.out'];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "cannot listen: $errstr\n");
    exit(1);
}
while ($conn = @stream_socket_accept($server, 90)) {
    fwrite($conn, "220 fake-smtp\r\n");
    $inData = false;
    $buffer = '';
    while (($line = fgets($conn)) !== false) {
        if ($inData) {
            if (rtrim($line, "\r\n") === '.') {
                $inData = false;
                file_put_contents($out, $buffer . "\n=====END=====\n", FILE_APPEND);
                $buffer = '';
                fwrite($conn, "250 queued\r\n");
            } else {
                $buffer .= $line;
            }
            continue;
        }
        $cmd = strtoupper(substr($line, 0, 4));
        if ($cmd === 'EHLO' || $cmd === 'HELO') { fwrite($conn, "250 fake-smtp\r\n"); }
        elseif ($cmd === 'DATA') { fwrite($conn, "354 go ahead\r\n"); $inData = true; }
        elseif ($cmd === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
        else { fwrite($conn, "250 ok\r\n"); }
    }
    fclose($conn);
}