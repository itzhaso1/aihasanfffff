<?php

namespace App\Services\Backup;

class SecretRedactor
{
    /**
     * @param  list<string>  $secrets
     */
    public function redact(string $text, array $secrets = []): string
    {
        $replacements = [];
        $appKey = (string) config('app.key');
        if ($appKey !== '') {
            $replacements[$appKey] = '[redacted]';
        }
        foreach ($secrets as $secret) {
            $secret = (string) $secret;
            if ($secret !== '' && $secret !== 'null') {
                $replacements[$secret] = '[redacted]';
            }
        }

        if ($replacements !== []) {
            $text = strtr($text, $replacements);
        }

        $text = preg_replace('/(--password=)\S+/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/(-p)(\S+)/', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('/(password\s*=\s*)([^\s]+)/i', '$1[redacted]', $text) ?? $text;
        $text = preg_replace('#(mysql://[^:]+:)[^@]+@#', '$1[redacted]@', $text) ?? $text;

        return $text;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return list<string>
     */
    public function secretsFromConnection(array $connection): array
    {
        $secrets = [
            (string) ($connection['password'] ?? ''),
            (string) config('app.key'),
        ];

        $user = (string) ($connection['username'] ?? '');
        $host = (string) ($connection['host'] ?? '');
        $database = (string) ($connection['database'] ?? '');
        if ($user !== '' && $host !== '') {
            $secrets[] = $user.':'.($connection['password'] ?? '').'@'.$host;
        }
        if ($user !== '' && $database !== '') {
            $secrets[] = $user.'@'.$host.'/'.$database;
        }

        return array_values(array_filter($secrets, fn (string $value): bool => $value !== ''));
    }
}
