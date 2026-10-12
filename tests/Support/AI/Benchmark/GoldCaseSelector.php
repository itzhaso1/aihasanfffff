<?php

declare(strict_types=1);

namespace Tests\Support\AI\Benchmark;

use RuntimeException;

/**
 * Read-only selection of 30 records from each of the 50 documented Gold parts.
 * Parts 02–50 are contiguous slices of the cleaned combined file, in cleanup-report order.
 */
final class GoldCaseSelector
{
    /** @var list<array{0: string, 1: int}> */
    private const PARTS_02_50 = [
        ['الملف_01/Part_02', 501],
        ['الملف_01/Part_03', 501],
        ['الملف_01/Part_04', 501],
        ['الملف_01/Part_05', 501],
        ['الملف_02/Part_01', 468],
        ['الملف_02/Part_02', 444],
        ['الملف_02/Part_03', 422],
        ['الملف_02/Part_04', 472],
        ['الملف_02/Part_05', 439],
        ['الملف_03/Part_01', 412],
        ['الملف_03/Part_02', 428],
        ['الملف_03/Part_03', 448],
        ['الملف_03/Part_04', 422],
        ['الملف_03/Part_05', 395],
        ['الملف_04/Part_01', 437],
        ['الملف_04/Part_02', 442],
        ['الملف_04/Part_03', 426],
        ['الملف_04/Part_04', 424],
        ['الملف_04/Part_05', 442],
        ['الملف_05/Part_01', 444],
        ['الملف_05/Part_02', 440],
        ['الملف_05/Part_03', 440],
        ['الملف_05/Part_04', 443],
        ['الملف_05/Part_05', 437],
        ['الملف_06/Part_01', 425],
        ['الملف_06/Part_02', 421],
        ['الملف_06/Part_03', 432],
        ['الملف_06/Part_04', 427],
        ['الملف_06/Part_05', 423],
        ['الملف_07/Part_01', 466],
        ['الملف_07/Part_02', 427],
        ['الملف_07/Part_03', 429],
        ['الملف_07/Part_04', 431],
        ['الملف_07/Part_05', 428],
        ['الملف_08/Part_01', 502],
        ['الملف_08/Part_02', 502],
        ['الملف_08/Part_03', 502],
        ['الملف_08/Part_04', 502],
        ['الملف_08/Part_05', 502],
        ['الملف_09/Part_01', 502],
        ['الملف_09/Part_02', 502],
        ['الملف_09/Part_03', 502],
        ['الملف_09/Part_04', 502],
        ['الملف_09/Part_05', 501],
        ['الملف_10/Part_01', 502],
        ['الملف_10/Part_02', 502],
        ['الملف_10/Part_03', 502],
        ['الملف_10/Part_04', 502],
        ['الملف_10/Part_05', 501],
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public function select(string $root, int $perPart = 30): array
    {
        if (count(self::PARTS_02_50) !== 49) {
            throw new RuntimeException('Expected 49 documented parts after Part 01.');
        }

        $part01 = $this->readJsonl($root.'/ai-benchmark/gold-dataset/Part_01_Cleaned/Part_01_behavioral_dataset_clean.jsonl');
        $rest = $this->readJsonl($root.'/ai-benchmark/gold-dataset/Behavioral_Dataset_Final_Clean/behavioral_dataset.jsonl');
        $expected = array_sum(array_map(static fn (array $part): int => $part[1], self::PARTS_02_50));
        if (count($rest) !== $expected) {
            throw new RuntimeException('Parts 02–50 retained counts do not match the combined file.');
        }

        $cases = [];
        $number = 1;
        foreach ($this->take($part01, $perPart, 'Part_01_Cleaned', 'part-01') as $case) {
            $cases[] = ['benchmark_id' => sprintf('v1-%06d', $number++)] + $case;
        }
        $offset = 0;
        foreach (self::PARTS_02_50 as $index => $part) {
            $slice = array_slice($rest, $offset, $part[1]);
            $offset += $part[1];
            $label = 'part-'.str_pad((string) ($index + 2), 2, '0', STR_PAD_LEFT);
            foreach ($this->take($slice, $perPart, $part[0], $label) as $case) {
                $cases[] = ['benchmark_id' => sprintf('v1-%06d', $number++)] + $case;
            }
        }

        if (count($cases) !== 50 * $perPart) {
            throw new RuntimeException('Benchmark selection did not produce 50 equal parts.');
        }

        return $cases;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    private function take(array $records, int $perPart, string $sourcePart, string $partKey): array
    {
        if (count($records) < $perPart) {
            throw new RuntimeException('Part '.$sourcePart.' has fewer than '.$perPart.' records.');
        }

        $selected = [];
        foreach (array_slice($records, 0, $perPart) as $index => $record) {
            $selected[] = $this->normalize($record, $sourcePart, $partKey, $index);
        }

        return $selected;
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, mixed>
     */
    private function normalize(array $record, string $sourcePart, string $partKey, int $index): array
    {
        foreach (['question', 'intent', 'domain', 'decision_behavior', 'verification_behavior', 'clarification_behavior'] as $field) {
            if (! isset($record[$field]) || ! is_string($record[$field]) || trim($record[$field]) === '') {
                throw new RuntimeException('Gold record is missing '.$field.' in '.$sourcePart.'.');
            }
        }

        if (isset($record['answers'])) {
            $answers = $record['answers'];
            if (! is_array($answers) || count($answers) < 3) {
                throw new RuntimeException('Part 01 record is missing three answers.');
            }
            $answer1 = $answers[0] ?? null;
            $answer2 = $answers[1] ?? null;
            $answer3 = $answers[2] ?? null;
        } else {
            $answer1 = $record['answer_1'] ?? null;
            $answer2 = $record['answer_2'] ?? null;
            $answer3 = $record['answer_3'] ?? null;
        }
        foreach (['answer_1' => $answer1, 'answer_2' => $answer2, 'answer_3' => $answer3] as $name => $value) {
            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException('Gold record is missing '.$name.' in '.$sourcePart.'.');
            }
        }

        return [
            'part' => $partKey,
            'source_part' => $sourcePart,
            'source_index' => $record['id'] ?? $index,
            'question' => $record['question'],
            'intent' => $record['intent'],
            'domain' => $record['domain'],
            'decision_behavior' => $record['decision_behavior'],
            'verification_behavior' => $record['verification_behavior'],
            'clarification_behavior' => $record['clarification_behavior'],
            'answer_1' => $answer1,
            'answer_2' => $answer2,
            'answer_3' => $answer3,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readJsonl(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Gold dataset file is missing: '.$path);
        }
        $rows = [];
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Gold dataset file could not be read.');
        }
        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $decoded = json_decode($line, true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('Gold dataset line is not a JSON object.');
                }
                $rows[] = $decoded;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }
}
