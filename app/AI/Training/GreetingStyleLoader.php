<?php

declare(strict_types=1);

namespace App\AI\Training;

use RuntimeException;

/**
 * Read-only loader for the frozen greeting-style JSONL. It never writes the file.
 */
final class GreetingStyleLoader
{
    public const RELATIVE_PATH = 'ai-benchmark/gold-dataset/Greeting_Style/greeting_style_saudi.jsonl';

    public const RECORD_COUNT = 187;

    /** @var list<string> */
    public const FIELDS = ['category', 'case', 'user', 'answer_1', 'answer_2'];

    /** @var array<string, list<GreetingStyleRecord>> */
    private static array $cache = [];

    public function __construct(private readonly ?string $path = null) {}

    public static function frozenPath(): string
    {
        return dirname(__DIR__, 3).DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::RELATIVE_PATH);
    }

    /**
     * @return list<GreetingStyleRecord>
     */
    public function records(): array
    {
        $path = $this->path ?? self::frozenPath();
        if (! isset(self::$cache[$path])) {
            self::$cache[$path] = $this->read($path);
        }

        return self::$cache[$path];
    }

    /**
     * @return list<GreetingStyleRecord>
     */
    private function read(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Greeting style dataset is missing at '.$path);
        }

        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException('Greeting style dataset could not be read at '.$path);
        }
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            throw new RuntimeException('Greeting style dataset must be UTF-8 without a BOM.');
        }
        if (! mb_check_encoding($raw, 'UTF-8')) {
            throw new RuntimeException('Greeting style dataset must be UTF-8.');
        }

        $normalized = str_replace("\r\n", "\n", $raw);
        $normalized = str_replace("\r", "\n", $normalized);
        if (str_ends_with($normalized, "\n")) {
            $normalized = substr($normalized, 0, -1);
        }
        if ($normalized === '') {
            throw new RuntimeException('Greeting style dataset is empty.');
        }

        $records = [];
        foreach (explode("\n", $normalized) as $index => $line) {
            if ($line === '') {
                throw new RuntimeException('Greeting style dataset line '.($index + 1).' is empty.');
            }
            try {
                $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $exception) {
                throw new RuntimeException('Greeting style dataset line '.($index + 1).' is not one JSON object.', 0, $exception);
            }
            if (! is_array($decoded) || array_is_list($decoded)) {
                throw new RuntimeException('Greeting style dataset line '.($index + 1).' is not one JSON object.');
            }
            if (array_keys($decoded) !== self::FIELDS) {
                throw new RuntimeException('Greeting style dataset line '.($index + 1).' must contain exactly category, case, user, answer_1, answer_2.');
            }
            foreach (self::FIELDS as $field) {
                if (! is_string($decoded[$field]) || trim($decoded[$field]) === '') {
                    throw new RuntimeException('Greeting style dataset line '.($index + 1).' has an empty '.$field.'.');
                }
            }
            $records[] = new GreetingStyleRecord(
                category: $decoded['category'],
                case: $decoded['case'],
                user: $decoded['user'],
                answer_1: $decoded['answer_1'],
                answer_2: $decoded['answer_2'],
            );
        }

        if (count($records) !== self::RECORD_COUNT) {
            throw new RuntimeException('Greeting style dataset must contain exactly '.self::RECORD_COUNT.' records.');
        }

        return $records;
    }
}

final readonly class GreetingStyleRecord
{
    public function __construct(
        public string $category,
        public string $case,
        public string $user,
        public string $answer_1,
        public string $answer_2,
    ) {}
}
