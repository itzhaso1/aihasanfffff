<?php

declare(strict_types=1);

namespace App\AI\Context;

final readonly class ConversationState
{
    /** @var list<int> */
    public array $referencedProductIds;

    /**
     * @param  list<int>  $referencedProductIds
     */
    public function __construct(
        public ?int $employeeId = null,
        public bool $introduced = false,
        public ?string $salesStage = null,
        public ?string $lastIntent = null,
        array $referencedProductIds = [],
    ) {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            array_filter($referencedProductIds, static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0),
        )));
        sort($ids, SORT_NUMERIC);
        $this->referencedProductIds = $ids;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function fromConversationMetadata(array $metadata, ?int $fallbackEmployeeId = null): self
    {
        $raw = data_get($metadata, 'ai.state');
        if (! is_array($raw)) {
            return new self(employeeId: $fallbackEmployeeId);
        }

        return new self(
            employeeId: self::positiveInt($raw['employee_id'] ?? $fallbackEmployeeId),
            introduced: (bool) ($raw['introduced'] ?? false),
            salesStage: self::nullableString($raw['sales_stage'] ?? null),
            lastIntent: self::nullableString($raw['last_intent'] ?? null),
            referencedProductIds: self::productIds($raw['referenced_products'] ?? []),
        );
    }

    public function withEmployeeId(?int $employeeId): self
    {
        return new self($employeeId, $this->introduced, $this->salesStage, $this->lastIntent, $this->referencedProductIds);
    }

    public function withIntroduced(bool $introduced): self
    {
        return new self($this->employeeId, $introduced, $this->salesStage, $this->lastIntent, $this->referencedProductIds);
    }

    public function withSalesStage(?string $salesStage): self
    {
        return new self($this->employeeId, $this->introduced, self::nullableString($salesStage), $this->lastIntent, $this->referencedProductIds);
    }

    public function withLastIntent(?string $lastIntent): self
    {
        return new self($this->employeeId, $this->introduced, $this->salesStage, self::nullableString($lastIntent), $this->referencedProductIds);
    }

    /**
     * @param  list<int>  $productIds
     */
    public function withReferencedProducts(array $productIds): self
    {
        return new self($this->employeeId, $this->introduced, $this->salesStage, $this->lastIntent, $productIds);
    }

    /**
     * @return array<string, bool|int|null|string|list<int>>
     */
    public function toArray(): array
    {
        return [
            'employee_id' => $this->employeeId,
            'introduced' => $this->introduced,
            'sales_stage' => $this->salesStage,
            'last_intent' => $this->lastIntent,
            'referenced_products' => $this->referencedProductIds,
        ];
    }

    /**
     * @return list<int>
     */
    private static function productIds(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $value) {
            $candidate = match (true) {
                is_numeric($value) => (int) $value,
                is_array($value) && is_numeric($value['id'] ?? null) => (int) $value['id'],
                is_array($value) && is_numeric($value['catalog_item_id'] ?? null) => (int) $value['catalog_item_id'],
                default => null,
            };
            if ($candidate !== null && $candidate > 0) {
                $ids[] = $candidate;
            }
        }

        return $ids;
    }

    private static function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private static function nullableString(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}
