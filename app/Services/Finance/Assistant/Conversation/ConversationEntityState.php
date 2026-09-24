<?php

namespace App\Services\Finance\Assistant\Conversation;

/**
 * Slot memory for multi-turn finance assistant turns.
 *
 * Numbers are never stored here — only entity references and period hints.
 */
final class ConversationEntityState
{
    public const FOCUS_CUSTOMER = 'customer';

    public const FOCUS_INVOICE = 'invoice';

    public const FOCUS_CONTRACT = 'contract';

    public const FOCUS_METRIC = 'metric';

    /**
     * @param  list<string>  $lastTools
     * @param  list<string>  $lastMetrics
     */
    public function __construct(
        public readonly ?string $customerName = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $contractNumber = null,
        public readonly ?string $entityFocus = null,
        public readonly ?string $periodPreset = null,
        public readonly ?string $periodFrom = null,
        public readonly ?string $periodTo = null,
        public readonly array $lastTools = [],
        public readonly array $lastMetrics = [],
    ) {}

    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null || $data === []) {
            return new self;
        }

        return new self(
            customerName: self::nullableString($data['customer_name'] ?? null),
            invoiceNumber: self::nullableString($data['invoice_number'] ?? null),
            contractNumber: self::nullableString($data['contract_number'] ?? null),
            entityFocus: self::nullableString($data['entity_focus'] ?? null),
            periodPreset: self::nullableString($data['period_preset'] ?? null),
            periodFrom: self::nullableString($data['period_from'] ?? null),
            periodTo: self::nullableString($data['period_to'] ?? null),
            lastTools: self::stringList($data['last_tools'] ?? []),
            lastMetrics: self::stringList($data['last_metrics'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'customer_name' => $this->customerName,
            'invoice_number' => $this->invoiceNumber,
            'contract_number' => $this->contractNumber,
            'entity_focus' => $this->entityFocus,
            'period_preset' => $this->periodPreset,
            'period_from' => $this->periodFrom,
            'period_to' => $this->periodTo,
            'last_tools' => $this->lastTools === [] ? null : $this->lastTools,
            'last_metrics' => $this->lastMetrics === [] ? null : $this->lastMetrics,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    public function isEmpty(): bool
    {
        return $this->toArray() === [];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public function with(array $overrides): self
    {
        return new self(
            customerName: array_key_exists('customerName', $overrides)
                ? self::nullableString($overrides['customerName'])
                : $this->customerName,
            invoiceNumber: array_key_exists('invoiceNumber', $overrides)
                ? self::nullableString($overrides['invoiceNumber'])
                : $this->invoiceNumber,
            contractNumber: array_key_exists('contractNumber', $overrides)
                ? self::nullableString($overrides['contractNumber'])
                : $this->contractNumber,
            entityFocus: array_key_exists('entityFocus', $overrides)
                ? self::nullableString($overrides['entityFocus'])
                : $this->entityFocus,
            periodPreset: array_key_exists('periodPreset', $overrides)
                ? self::nullableString($overrides['periodPreset'])
                : $this->periodPreset,
            periodFrom: array_key_exists('periodFrom', $overrides)
                ? self::nullableString($overrides['periodFrom'])
                : $this->periodFrom,
            periodTo: array_key_exists('periodTo', $overrides)
                ? self::nullableString($overrides['periodTo'])
                : $this->periodTo,
            lastTools: array_key_exists('lastTools', $overrides)
                ? self::stringList($overrides['lastTools'])
                : $this->lastTools,
            lastMetrics: array_key_exists('lastMetrics', $overrides)
                ? self::stringList($overrides['lastMetrics'])
                : $this->lastMetrics,
        );
    }

    private static function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value,
        ), fn (string $item): bool => $item !== ''));
    }
}
