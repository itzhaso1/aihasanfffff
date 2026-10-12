<?php

declare(strict_types=1);

$xml = simplexml_load_file($argv[1] ?? 'storage/logs/ci/phpunit-sqlite-full.xml');
if ($xml === false) {
    fwrite(STDERR, "Unable to read JUnit XML\n");
    exit(1);
}

$fail = [];
$err = [];
foreach ($xml->xpath('//testcase') as $case) {
    $name = ((string) $case['classname']).'::'.((string) $case['name']);
    if (isset($case->failure)) {
        $fail[] = $name.' | '.trim((string) $case->failure['message']);
    }
    if (isset($case->error)) {
        $err[] = $name.' | '.trim((string) $case->error['message']);
    }
}

echo 'FAIL='.count($fail).PHP_EOL;
foreach ($fail as $line) {
    echo $line.PHP_EOL;
}
echo 'ERR='.count($err).PHP_EOL;
foreach ($err as $line) {
    echo $line.PHP_EOL;
}
