<?php

declare(strict_types=1);

namespace Tests\Support\AI\Training;

use App\AI\Training\TrainingType;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Temporary training library: an empty layout or a copy of the real library.
 */
final class TrainingFixture
{
    public readonly string $directory;

    private function __construct()
    {
        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hasim-training-'.bin2hex(random_bytes(6));
        mkdir($this->directory);
        foreach (TrainingType::cases() as $type) {
            mkdir($this->directory.DIRECTORY_SEPARATOR.$type->folder());
        }
    }

    public static function empty(): self
    {
        return new self;
    }

    public static function copyOfRealLibrary(): self
    {
        $fixture = new self;
        $root = self::realDirectory();
        foreach (TrainingType::cases() as $type) {
            $source = $root.DIRECTORY_SEPARATOR.$type->folder();
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $item) {
                $relative = $type->folder().DIRECTORY_SEPARATOR.substr($item->getPathname(), strlen($source) + 1);
                $target = $fixture->directory.DIRECTORY_SEPARATOR.$relative;
                $item->isDir() ? @mkdir($target) : copy($item->getPathname(), $target);
            }
        }

        return $fixture;
    }

    public static function realDirectory(): string
    {
        return dirname(__DIR__, 4).DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'AI'.DIRECTORY_SEPARATOR.'Training';
    }

    public function path(string $relative): string
    {
        return $this->directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public function write(string $relative, string $content): void
    {
        $path = $this->path($relative);
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }
        file_put_contents($path, $content);
    }

    public function read(string $relative): string
    {
        return (string) file_get_contents($this->path($relative));
    }

    /**
     * @param  array<string, string>  $fields  Raw front matter lines keyed by field name.
     */
    public function document(string $relative, array $fields, string $body): void
    {
        $lines = ['---'];
        foreach ($fields as $key => $value) {
            $lines[] = str_starts_with($value, "\n") ? $key.':'.$value : $key.': '.$value;
        }
        $lines[] = '---';
        $this->write($relative, implode("\n", $lines)."\n".$body."\n");
    }

    /**
     * Writes a valid document with sensible defaults.
     *
     * @param  array<string, string>  $overrides
     */
    public function valid(string $relative, string $id, string $type, string $body, array $overrides = []): void
    {
        $this->document($relative, array_merge([
            'id' => $id,
            'type' => $type,
            'version' => '1',
            'priority' => 'normal',
            'always' => 'false',
            'max_chars' => '2000',
        ], $overrides), $body);
    }

    public function remove(): void
    {
        if (! is_dir($this->directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->directory);
    }
}
