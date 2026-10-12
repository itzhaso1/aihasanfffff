<?php

declare(strict_types=1);

namespace Tests\Support\AI\Constitution;

use App\AI\Constitution\ConstitutionManifest;

/**
 * Temporary copy of the real constitution directory for failure-path tests.
 */
final class ConstitutionFixture
{
    public readonly string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hasim-constitution-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        foreach (ConstitutionManifest::standard()->filenames() as $filename) {
            copy(self::realDirectory().DIRECTORY_SEPARATOR.$filename, $this->path($filename));
        }
    }

    public static function realDirectory(): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'AI'.DIRECTORY_SEPARATOR.'Constitution';
    }

    public function path(string $filename): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.$filename;
    }

    public function write(string $filename, string $content): void
    {
        file_put_contents($this->path($filename), $content);
    }

    public function read(string $filename): string
    {
        return (string) file_get_contents($this->path($filename));
    }

    public function remove(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
    }
}
