<?php

declare(strict_types=1);

/**
 * Refuse the production database name in automated tests and CI.
 * Production / .env.example default is `hasem`. Isolated CI names are allowed.
 */
if (defined('HASEM_CI_DB_SAFETY_CHECKED')) {
    return;
}

define('HASEM_CI_DB_SAFETY_CHECKED', true);

$forbiddenExact = [
    'hasem',
    'mysql',
    'sys',
    'information_schema',
    'performance_schema',
];

$candidates = [];

foreach (['DB_DATABASE', 'MYSQL_DATABASE', 'MARIADB_DATABASE'] as $key) {
    $value = getenv($key);
    if ($value !== false && $value !== '') {
        $candidates[$key] = $value;
    }
}

$url = getenv('DB_URL');
if (is_string($url) && $url !== '') {
    $path = parse_url($url, PHP_URL_PATH);
    if (is_string($path) && $path !== '' && $path !== '/') {
        $candidates['DB_URL'] = ltrim($path, '/');
    }
}

foreach ($candidates as $source => $raw) {
    $name = strtolower(trim((string) $raw));
    if (in_array($name, $forbiddenExact, true)) {
        fwrite(STDERR, "CI database safety: {$source} must not be production/system database [{$name}].\n");
        exit(1);
    }
}

$connection = strtolower((string) (getenv('DB_CONNECTION') ?: ''));
$database = strtolower(trim((string) (getenv('DB_DATABASE') ?: '')));

if (in_array($connection, ['mysql', 'mariadb'], true) && $database === '') {
    fwrite(STDERR, "CI database safety: MySQL/MariaDB requires an explicit isolated DB_DATABASE.\n");
    exit(1);
}

$label = $database !== '' ? $database : ($connection !== '' ? $connection : 'unset');
fwrite(STDOUT, 'CI database safety: OK ('.$label.")\n");
