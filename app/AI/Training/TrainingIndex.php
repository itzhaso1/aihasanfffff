<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Deterministic lookup over validated documents. Every list is ordered by id, so
 * results never depend on filesystem order. Construction rejects duplicate ids,
 * duplicate bodies and profile includes that do not point at content documents.
 */
final class TrainingIndex
{
    private const VERSION_SCHEMA = 'hasim-training-library-v1';

    /** @var array<string, TrainingDocument> */
    private array $byId = [];

    /** @var array<string, list<string>> */
    private array $byType = [];

    /** @var array<string, array<string, list<string>>> */
    private array $bySelector = [];

    /**
     * @param  list<TrainingDocument>  $documents
     */
    public function __construct(array $documents)
    {
        usort($documents, static fn (TrainingDocument $a, TrainingDocument $b): int => strcmp($a->id, $b->id));

        $bodies = [];
        foreach ($documents as $document) {
            if (isset($this->byId[$document->id])) {
                throw TrainingException::duplicateId($document->id, $document->source);
            }
            if (isset($bodies[$document->bodyHash])) {
                throw TrainingException::duplicateContent($document->source, $bodies[$document->bodyHash]);
            }
            $bodies[$document->bodyHash] = $document->source;
            $this->byId[$document->id] = $document;
            $this->byType[$document->type->value][] = $document->id;
            foreach (TrainingDimension::cases() as $dimension) {
                foreach ($document->appliesTo->values($dimension) as $value) {
                    $this->bySelector[$dimension->value][$value][] = $document->id;
                }
            }
        }

        foreach ($this->byId as $document) {
            foreach ($document->includes as $include) {
                if (! isset($this->byId[$include]) || ! $this->byId[$include]->type->isPromptContent()) {
                    throw TrainingException::unknownInclude($include, $document->source);
                }
            }
        }
    }

    /**
     * @return list<TrainingDocument>
     */
    public function all(): array
    {
        return array_values($this->byId);
    }

    public function find(string $id): ?TrainingDocument
    {
        return $this->byId[$id] ?? null;
    }

    /**
     * @return list<TrainingDocument>
     */
    public function ofType(TrainingType $type): array
    {
        return $this->documents($this->byType[$type->value] ?? []);
    }

    /**
     * @return list<TrainingDocument>
     */
    public function withSelector(TrainingDimension $dimension, string $value): array
    {
        return $this->documents($this->bySelector[$dimension->value][$value] ?? []);
    }

    /**
     * @return array<string, int> Type value => document count.
     */
    public function typeCounts(): array
    {
        $counts = [];
        foreach (TrainingType::cases() as $type) {
            $counts[$type->value] = count($this->byType[$type->value] ?? []);
        }

        return $counts;
    }

    /**
     * @return array<string, array<string, int>> Dimension => selector value => document count.
     */
    public function selectorCounts(): array
    {
        $counts = [];
        foreach ($this->bySelector as $dimension => $values) {
            ksort($values, SORT_STRING);
            $counts[$dimension] = array_map('count', $values);
        }
        ksort($counts, SORT_STRING);

        return $counts;
    }

    /** Hash over every document id and content hash; changes whenever any document changes. */
    public function version(): string
    {
        $lines = [self::VERSION_SCHEMA];
        foreach ($this->byId as $id => $document) {
            $lines[] = $id.'|'.$document->contentHash;
        }

        return hash('sha256', implode("\n", $lines));
    }

    /**
     * @param  list<string>  $ids
     * @return list<TrainingDocument>
     */
    private function documents(array $ids): array
    {
        return array_map(fn (string $id): TrainingDocument => $this->byId[$id], $ids);
    }
}
