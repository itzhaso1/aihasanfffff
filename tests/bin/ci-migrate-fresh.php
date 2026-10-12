<?php

declare(strict_types=1);

/**
 * Prove migrations on a clean isolated database. Never targets `hasem`.
 */
$root = dirname(__DIR__, 2);
chdir($root);

if (getenv('APP_ENV') === false || getenv('APP_ENV') === '') {
    putenv('APP_ENV=testing');
    $_ENV['APP_ENV'] = 'testing';
}

if (getenv('APP_KEY') === false || getenv('APP_KEY') === '') {
    putenv('APP_KEY=base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWMs5Cpj4Cp4Ixkc4A=');
    $_ENV['APP_KEY'] = 'base64:2fl+Ktvkfl+Fuz4Qp/A75G2RTiWMs5Cpj4Cp4Ixkc4A=';
}

require __DIR__.'/assert-not-production-database.php';

$connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');
$database = (string) (getenv('DB_DATABASE') ?: '');

if ($connection === 'sqlite') {
    if ($database === '' || $database === ':memory:') {
        fwrite(STDERR, "CI migrate:fresh needs a file SQLite path (not :memory:).\n");
        exit(1);
    }

    $dir = dirname($database);
    if ($dir !== '.' && $dir !== '' && ! is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    if (! file_exists($database)) {
        touch($database);
    }
}

$php = PHP_BINARY;
$artisan = $root.DIRECTORY_SEPARATOR.'artisan';

passthru(escapeshellarg($php).' '.escapeshellarg($artisan).' migrate:fresh --force --no-interaction', $fresh);
if ($fresh !== 0) {
    exit($fresh);
}

passthru(escapeshellarg($php).' '.escapeshellarg($artisan).' migrate:status --no-interaction', $status);
exit($status);
