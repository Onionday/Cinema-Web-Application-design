<?php
/* =====================================================================
 * tests/mail_sink.php - catches the site's emails during a test run
 *
 * Nothing is delivered anywhere: each message is appended to a text file
 * so run_tests.php can check that the acknowledgement was produced and
 * addressed to the local coursework mailbox.
 *
 *   Windows (XAMPP): PHP's mail() talks SMTP, so this runs as a tiny
 *                    SMTP server:   php mail_sink.php smtp <port> <file>
 *   Linux / macOS:   PHP's mail() pipes to sendmail_path, so this reads
 *                    the message:   php mail_sink.php pipe <file>
 * ===================================================================== */

$mode = $argv[1] ?? '';

function save(string $file, string $text): void
{
    file_put_contents($file, $text . "\n=====END=====\n", FILE_APPEND | LOCK_EX);
}

if ($mode === 'pipe') {
    save($argv[2], stream_get_contents(STDIN));
    exit(0);
}

if ($mode !== 'smtp') {
    fwrite(STDERR, "usage: php mail_sink.php smtp <port> <file> | pipe <file>\n");
    exit(1);
}

$port = (int) $argv[2];
$file = $argv[3];
$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);
if (!$server) {
    fwrite(STDERR, "mail sink could not listen: $errstr\n");
    exit(1);
}

while (true) {
    $c = @stream_socket_accept($server, -1);
    if (!$c) {
        continue;
    }
    fwrite($c, "220 cinesphere-test-sink\r\n");
    $envelope = '';
    while (($line = fgets($c)) !== false) {
        $cmd = strtoupper(substr(trim($line), 0, 4));
        if ($cmd === 'DATA') {
            fwrite($c, "354 end with <CRLF>.<CRLF>\r\n");
            $data = '';
            while (($l = fgets($c)) !== false && rtrim($l, "\r\n") !== '.') {
                $data .= $l;
            }
            save($file, $envelope . $data);
            $envelope = '';
            fwrite($c, "250 queued\r\n");
        } elseif ($cmd === 'QUIT') {
            fwrite($c, "221 bye\r\n");
            break;
        } else {
            if ($cmd === 'MAIL' || $cmd === 'RCPT') {
                $envelope .= trim($line) . "\n";
            }
            fwrite($c, "250 ok\r\n");
        }
    }
    fclose($c);
}
