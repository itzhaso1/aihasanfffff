<?php

namespace App\Services\Backup;

class MysqlClientLocator
{
    /**
     * @param  list<string>  $names
     */
    public function find(array $names, ?string $configured = null): string
    {
        if (is_string($configured) && $configured !== '') {
            if (is_file($configured)) {
                return $configured;
            }

            throw BackupException::failed('Configured database client binary was not found.');
        }

        $candidates = [];
        foreach ($names as $name) {
            $candidates[] = $name;
            if (PHP_OS_FAMILY === 'Windows') {
                $candidates[] = 'C:\\xampp\\mysql\\bin\\'.$name.'.exe';
                $candidates[] = 'C:\\xampp\\mariadb\\bin\\'.$name.'.exe';
            }
        }

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        foreach ($names as $name) {
            $resolved = $this->which($name);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        throw BackupException::failed('Database client binary is missing: '.implode(', ', $names).'.');
    }

    private function which(string $name): ?string
    {
        $command = PHP_OS_FAMILY === 'Windows' ? 'where '.escapeshellarg($name) : 'command -v '.escapeshellarg($name);
        $output = [];
        $code = 1;
        @exec($command, $output, $code);
        $path = trim((string) ($output[0] ?? ''));

        return $code === 0 && $path !== '' && is_file($path) ? $path : null;
    }
}
