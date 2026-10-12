<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Filesystem access for the training library: discovery inside the fixed folder layout
 * and bounded reads. Paths never come from input, and every file must resolve inside
 * the library directory. Discovery order is sorted, never filesystem order.
 */
final class TrainingSource
{
    private const FILE_PATTERN = '/^[a-z0-9][a-z0-9_-]*\.md$/';

    private const DOCUMENTATION_FILE = 'README.md';

    private const IGNORED_FILES = ['.gitkeep'];

    private readonly string $directory;

    public function __construct(
        private readonly TrainingLimits $limits = new TrainingLimits,
        ?string $directory = null,
    ) {
        $this->directory = $directory ?? __DIR__;
    }

    public function root(): string
    {
        $root = realpath($this->directory);
        if ($root === false || ! is_dir($root)) {
            throw TrainingException::missingDirectory();
        }

        return $root;
    }

    public function scan(): TrainingScan
    {
        $root = $this->root();
        $files = [];
        $problems = [];
        $missing = [];

        foreach ($this->entries($root) as $name) {
            $path = $root.DIRECTORY_SEPARATOR.$name;
            $isKnownFolder = is_dir($path) && TrainingType::forFolder($name) !== null;
            $isRootAllowed = is_file($path) && ($name === self::DOCUMENTATION_FILE || str_ends_with($name, '.php'));
            if (! $isKnownFolder && ! $isRootAllowed) {
                $problems[] = TrainingException::unexpectedFile($name);
            }
        }

        foreach (TrainingType::cases() as $type) {
            $folder = $root.DIRECTORY_SEPARATOR.$type->folder();
            if (! is_dir($folder)) {
                $missing[] = $type->folder();

                continue;
            }
            $this->scanFolder($root, $folder, $type->folder(), $type, $type->allowsSubfolders(), $files, $problems);
        }

        usort($files, static fn (TrainingSourceFile $a, TrainingSourceFile $b): int => strcmp($a->relativePath, $b->relativePath));

        return new TrainingScan($files, $problems, $missing);
    }

    public function read(TrainingSourceFile $file): string
    {
        $this->assertInside($this->root(), $file->absolutePath, $file->relativePath);
        if (! is_readable($file->absolutePath)) {
            throw TrainingException::because(TrainingIssueCode::Unreadable, 'File cannot be read.', $file->relativePath);
        }
        $size = filesize($file->absolutePath);
        if ($size === false) {
            throw TrainingException::because(TrainingIssueCode::Unreadable, 'File size cannot be read.', $file->relativePath);
        }
        if ($size > $this->limits->maxFileBytes) {
            throw TrainingException::because(TrainingIssueCode::FileTooLarge, 'File exceeds the '.$this->limits->maxFileBytes.' byte limit.', $file->relativePath);
        }
        $raw = file_get_contents($file->absolutePath);
        if ($raw === false) {
            throw TrainingException::because(TrainingIssueCode::Unreadable, 'File cannot be read.', $file->relativePath);
        }

        return $raw;
    }

    /**
     * @param  list<TrainingSourceFile>  $files
     * @param  list<TrainingException>  $problems
     */
    private function scanFolder(
        string $root,
        string $folder,
        string $relative,
        TrainingType $type,
        bool $allowSubfolders,
        array &$files,
        array &$problems,
    ): void {
        foreach ($this->entries($folder) as $name) {
            $path = $folder.DIRECTORY_SEPARATOR.$name;
            $relativePath = $relative.'/'.$name;
            if (in_array($name, self::IGNORED_FILES, true)) {
                continue;
            }
            if (is_dir($path)) {
                if ($allowSubfolders && preg_match('/^[a-z0-9][a-z0-9_-]*$/', $name) === 1) {
                    $this->scanFolder($root, $path, $relativePath, $type, false, $files, $problems);
                } else {
                    $problems[] = TrainingException::unexpectedFile($relativePath);
                }

                continue;
            }
            if ($name === self::DOCUMENTATION_FILE && $type === TrainingType::Knowledge && $relative === $type->folder()) {
                continue;
            }
            if (preg_match(self::FILE_PATTERN, $name) !== 1) {
                $problems[] = TrainingException::unexpectedFile($relativePath);

                continue;
            }
            $resolved = realpath($path);
            if ($resolved === false || ! $this->isInside($root, $resolved)) {
                $problems[] = TrainingException::outsideRoot($relativePath);

                continue;
            }
            $files[] = new TrainingSourceFile($relativePath, $type, $resolved);
        }
    }

    /**
     * @return list<string>
     */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff(scandir($directory) ?: [], ['.', '..']));
        sort($entries, SORT_STRING);

        return $entries;
    }

    private function assertInside(string $root, string $path, string $relativePath): void
    {
        $resolved = realpath($path);
        if ($resolved === false || ! $this->isInside($root, $resolved)) {
            throw TrainingException::outsideRoot($relativePath);
        }
    }

    private function isInside(string $root, string $resolved): bool
    {
        return str_starts_with($resolved, $root.DIRECTORY_SEPARATOR);
    }
}
