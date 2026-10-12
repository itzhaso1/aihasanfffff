<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
chdir($root);

$lines = file($root.'/tests/ci/pint-enforced-paths.txt', FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "Missing tests/ci/pint-enforced-paths.txt\n");
    exit(1);
}

$paths = [];
foreach ($lines as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    $paths[] = $line;
}

$args = [PHP_BINARY, $root.'/vendor/bin/pint'];
if (in_array('--test', $argv, true)) {
    $args[] = '--test';
}
array_push($args, ...$paths);

$command = implode(' ', array_map('escapeshellarg', $args));
passthru($command, $code);
exit($code);
