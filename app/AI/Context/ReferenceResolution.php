<?php

declare(strict_types=1);

namespace App\AI\Context;

use App\AI\Core\Decision\AiReference;
use App\AI\Core\Support\TypedList;

final readonly class ReferenceResolution
{
    /** @var list<int> */
    public array $productIds;

    /** @var list<AiReference> */
    public array $references;

    /** @var list<array<string, mixed>> */
    public array $catalogItems;

    /**
     * @param  list<int>  $productIds
     * @param  list<AiReference>  $references
     * @param  list<array<string, mixed>>  $catalogItems
     */
    public function __construct(
        public bool $resolved = false,
        public ?string $source = null,
        array $productIds = [],
        array $references = [],
        public string $retrievalStatus = 'not_loaded',
        array $catalogItems = [],
    ) {
        $ids = array_values(array_unique(array_map(
            static fn (mixed $value): int => (int) $value,
            array_filter($productIds, static fn (mixed $value): bool => is_numeric($value) && (int) $value > 0),
        )));
        sort($ids, SORT_NUMERIC);
        $this->productIds = $ids;
        $this->references = TypedList::of($references, AiReference::class, 'Context references');
        $this->catalogItems = array_values(array_filter(
            $catalogItems,
            static fn (mixed $item): bool => is_array($item),
        ));
    }

    /**
     * @return array<string, bool|string|list<int>|list<array{type:string,id:string}>|null>
     */
    public function toArray(): array
    {
        return [
            'resolved' => $this->resolved,
            'source' => $this->source,
            'retrieval_status' => $this->retrievalStatus,
            'product_ids' => $this->productIds,
            'references' => array_map(static fn (AiReference $reference): array => $reference->toArray(), $this->references),
        ];
    }
}
