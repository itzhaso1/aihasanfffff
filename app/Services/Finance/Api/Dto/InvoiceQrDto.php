<?php

namespace App\Services\Finance\Api\Dto;

final readonly class InvoiceQrDto
{
    /**
     * @param  array<int|string, string>  $tags
     */
    public function __construct(
        public string $base64,
        public string $profile,
        public array $tags,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $value = function (int $tag): ?string {
            return $this->tags[$tag] ?? $this->tags[(string) $tag] ?? null;
        };

        return [
            'qr_base64' => $this->base64,
            'profile' => $this->profile,
            'tags' => [
                'seller_name' => $value(1),
                'seller_vat' => $value(2),
                'timestamp' => $value(3),
                'total_with_vat' => $value(4),
                'vat_total' => $value(5),
                'invoice_hash' => $value(6),
            ],
        ];
    }
}
