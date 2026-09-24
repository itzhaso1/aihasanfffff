<?php

$user = 'info@hasim.online';
$to = 'hasan.baara2333@gmail.com';

echo "Connecting to Hostinger...\n";

$password = readline('Hostinger password: ');

$fp = fsockopen(
    'ssl://smtp.hostinger.com',
    465,
    $errno,
    $errstr,
    20
);

if (! $fp) {
    exit("CONNECT FAILED: $errstr ($errno)\n");
}

function smtp($fp, $command)
{
    fwrite($fp, $command."\r\n");

    $response = '';

    while (($line = fgets($fp, 515)) !== false) {
        $response .= $line;

        if (strlen($line) >= 4 && $line[3] === ' ') {
            break;
        }
    }

    echo $response;

    return $response;
}

echo 'SERVER: '.fgets($fp);

smtp($fp, 'EHLO hasim.online');

smtp($fp, 'AUTH LOGIN');
smtp($fp, base64_encode($user));
smtp($fp, base64_encode($password));

smtp($fp, "MAIL FROM:<$user>");
smtp($fp, "RCPT TO:<$to>");
smtp($fp, 'DATA');

fwrite(
    $fp,
    "From: $user\r\n".
    "To: $to\r\n".
    "Subject: HASEM Direct SMTP Test\r\n".
    "\r\n".
    "This is a direct SMTP test from HASEM.\r\n".
    ".\r\n"
);

echo "FINAL RESPONSE:\n";
echo fgets($fp);

smtp($fp, 'QUIT');

fclose($fp);
