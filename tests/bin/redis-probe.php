<?php

declare(strict_types=1);

require __DIR__.'/../../vendor/autoload.php';

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);
$password = getenv('REDIS_PASSWORD') ?: null;

try {
    if (! class_exists('Redis')) {
        throw new RuntimeException('phpredis extension not loaded');
    }

    $redis = new Redis;
    $redis->connect($host, $port, 1.0);
    if ($password) {
        $redis->auth($password);
    }

    $token = 'hasem-plan1-'.uniqid('', true);
    $redis->set($token, 'ok', 30);
    $value = $redis->get($token);
    $redis->del($token);

    echo json_encode([
        'status' => 'OK',
        'ping' => $redis->ping(),
        'set_get' => $value === 'ok',
        'host' => $host,
        'port' => $port,
    ], JSON_UNESCAPED_UNICODE);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'status' => 'UNAVAILABLE',
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
    exit(2);
}
