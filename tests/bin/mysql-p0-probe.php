<?php

use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$c = config('database.connections.mysql');

echo 'driver='.$c['driver'].PHP_EOL;
echo 'host='.$c['host'].PHP_EOL;
echo 'port='.$c['port'].PHP_EOL;
echo 'configured_database='.$c['database'].PHP_EOL;

try {
    $pdo = new PDO(
        'mysql:host='.$c['host'].';port='.$c['port'],
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    echo 'version='.$pdo->query('SELECT VERSION()')->fetchColumn().PHP_EOL;
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `hasem_p0_tenancy_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    echo 'test_database=hasem_p0_tenancy_test'.PHP_EOL;
    echo 'status=READY'.PHP_EOL;
} catch (Throwable $e) {
    echo 'status=BLOCKED'.PHP_EOL;
    echo 'error='.$e->getMessage().PHP_EOL;
    exit(2);
}
