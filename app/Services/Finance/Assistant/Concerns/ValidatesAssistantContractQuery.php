<?php

namespace App\Services\Finance\Assistant\Concerns;

use Illuminate\Support\Facades\Validator;

trait ValidatesAssistantContractQuery
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{contract_number: ?string, customer_name: ?string}
     */
    protected function validatedContractQuery(array $arguments): array
    {
        $validated = Validator::validate($arguments, [
            'contract_number' => ['nullable', 'string', 'max:64'],
            'query' => ['nullable', 'string', 'max:64'],
            'customer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $number = trim((string) ($validated['contract_number'] ?? $validated['query'] ?? ''));
        $customerName = trim((string) ($validated['customer_name'] ?? ''));

        if ($number === '' && $customerName === '') {
            Validator::validate(['contract_number' => ''], ['contract_number' => ['required']]);
        }

        return [
            'contract_number' => $number !== '' ? $number : null,
            'customer_name' => $customerName !== '' ? $customerName : null,
        ];
    }
}
