<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Strict parser for the front matter subset training files use:
 * `key: scalar`, `key: [a, b]`, a block list under `key:`, and one level of
 * `key:` → `subkey: [..]` or `subkey:` → block list. Anything else is rejected.
 */
final class TrainingFrontMatterParser
{
    private const DELIMITER = '---';

    private const KEY = '[a-z][a-z0-9_]*';

    /**
     * @return array{0: array<string, mixed>, 1: string} Fields and body.
     */
    public function parse(string $normalized, string $source): array
    {
        $lines = explode("\n", $normalized);
        if (($lines[0] ?? null) !== self::DELIMITER) {
            throw TrainingException::because(TrainingIssueCode::MissingFrontMatter, 'File must start with a front matter block.', $source);
        }
        $end = array_search(self::DELIMITER, array_slice($lines, 1, preserve_keys: true), true);
        if ($end === false) {
            throw TrainingException::because(TrainingIssueCode::InvalidFrontMatter, 'Front matter block is not closed.', $source);
        }

        $fields = $this->fields(array_slice($lines, 1, $end - 1), $source);
        $body = implode("\n", array_slice($lines, $end + 1));

        return [$fields, $body];
    }

    /**
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private function fields(array $lines, string $source): array
    {
        $fields = [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = rtrim($lines[$i]);
            if ($this->isSkippable($line)) {
                continue;
            }
            if (str_contains($line, "\t") || preg_match('/^('.self::KEY.'):(?:\s+(.*))?$/', $line, $match) !== 1) {
                throw $this->invalid('unexpected line "'.$line.'"', $source);
            }
            $key = $match[1];
            if (array_key_exists($key, $fields)) {
                throw $this->invalid('duplicate key "'.$key.'"', $source);
            }
            $inline = trim($match[2] ?? '');
            if ($inline !== '') {
                $fields[$key] = $this->value($inline, $source);

                continue;
            }
            [$fields[$key], $i] = $this->block($lines, $i + 1, $source);
        }

        return $fields;
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: array<array-key, mixed>, 1: int} Block value and last consumed index.
     */
    private function block(array $lines, int $start, string $source): array
    {
        $list = [];
        $map = [];
        $currentKey = null;
        $i = $start;
        for (; $i < count($lines); $i++) {
            $line = rtrim($lines[$i]);
            if ($this->isSkippable($line)) {
                continue;
            }
            if (! str_starts_with($line, ' ')) {
                break;
            }
            if (preg_match('/^  - (.+)$/', $line, $item) === 1 && $map === []) {
                $list[] = $this->scalar(trim($item[1]), $source);
            } elseif (preg_match('/^  ('.self::KEY.'):(?:\s+(.*))?$/', $line, $sub) === 1 && $list === []) {
                $currentKey = $sub[1];
                if (array_key_exists($currentKey, $map)) {
                    throw $this->invalid('duplicate key "'.$currentKey.'"', $source);
                }
                $inline = trim($sub[2] ?? '');
                $map[$currentKey] = $inline === '' ? [] : $this->value($inline, $source);
            } elseif (preg_match('/^    - (.+)$/', $line, $item) === 1 && $currentKey !== null && is_array($map[$currentKey])) {
                $map[$currentKey][] = $this->scalar(trim($item[1]), $source);
            } else {
                throw $this->invalid('unexpected indentation in "'.trim($line).'"', $source);
            }
        }

        if ($list === [] && $map === []) {
            throw $this->invalid('a key without a value needs an indented block', $source);
        }

        return [$list !== [] ? $list : $map, $i - 1];
    }

    private function value(string $inline, string $source): mixed
    {
        if (str_starts_with($inline, '[')) {
            if (! str_ends_with($inline, ']')) {
                throw $this->invalid('inline list is not closed', $source);
            }
            $inner = trim(substr($inline, 1, -1));

            return $inner === '' ? [] : array_map(
                fn (string $item): mixed => $this->scalar(trim($item), $source),
                explode(',', $inner),
            );
        }

        return $this->scalar($inline, $source);
    }

    private function scalar(string $raw, string $source): string|int|bool
    {
        if ($raw === '' || preg_match('/^[\[\]{}&*!|>%@`]/', $raw) === 1) {
            throw $this->invalid('unsupported value "'.$raw.'"', $source);
        }

        return match (true) {
            $raw === 'true' => true,
            $raw === 'false' => false,
            preg_match('/^-?\d+$/', $raw) === 1 => (int) $raw,
            preg_match('/^"(.*)"$/', $raw, $quoted) === 1, preg_match("/^'(.*)'$/", $raw, $quoted) === 1 => $quoted[1],
            default => $raw,
        };
    }

    private function isSkippable(string $line): bool
    {
        return trim($line) === '' || str_starts_with(ltrim($line), '#');
    }

    private function invalid(string $reason, string $source): TrainingException
    {
        return TrainingException::because(TrainingIssueCode::InvalidFrontMatter, 'Invalid front matter: '.$reason.'.', $source);
    }
}
