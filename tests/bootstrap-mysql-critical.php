<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

/**
 * MySQL critical suite bootstrap: refuse production DB names, then rebuild an isolated schema.
 */
require __DIR__.'/bootstrap.php';

$connection = strtolower((string) (getenv('DB_CONNECTION') ?: ''));
if (! in_array($connection, ['mysql', 'mariadb'], true)) {
    return;
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$exitCode = $kernel->call('migrate:fresh', [
    '--database' => 'mysql',
    '--force' => true,
]);

if ($exitCode !== 0) {
    fwrite(STDERR, "MySQL critical bootstrap: migrate:fresh failed with exit code {$exitCode}.\n");
    exit($exitCode);
}

RefreshDatabaseState::$migrated = false;

fwrite(STDOUT, 'MySQL critical bootstrap: fresh schema on '.getenv('DB_DATABASE')."\n");
