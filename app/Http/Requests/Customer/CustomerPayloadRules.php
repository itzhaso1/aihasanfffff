<?php

namespace App\Http\Requests\Customer;

use Illuminate\Contracts\Validation\ValidationRule;

class CustomerPayloadRules
{
    /**
     * Financial identity fields stored on the shared customers table.
     *
     * @return array<string, ValidationRule|array<int, string>|string>
     */
    public static function financial(): array
    {
        return [
            'party_type' => ['nullable', 'in:individual,company'],
            'vat_number' => ['nullable', 'string', 'max:32'],
            'commercial_registration' => ['nullable', 'string', 'max:32'],
            'address' => ['nullable', 'string', 'max:2000'],
            'building_number' => ['nullable', 'string', 'max:32'],
            'street' => ['nullable', 'string', 'max:191'],
            'district' => ['nullable', 'string', 'max:191'],
            'city' => ['nullable', 'string', 'max:191'],
            'postal_code' => ['nullable', 'string', 'max:16'],
            'country_code' => ['nullable', 'string', 'size:2'],
            'additional_number' => ['nullable', 'string', 'max:32'],
            'payment_terms' => ['nullable', 'string', 'max:255'],
        ];
    }
}
