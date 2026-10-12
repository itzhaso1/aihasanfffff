<?php

namespace App\Http\Requests\Chat\Concerns;

trait NormalizesCatalogBooleanInput
{
    /**
     * @return list<string>
     */
    protected function catalogItemBooleanFields(): array
    {
        return [
            'is_active',
            'is_featured',
            'is_bestseller',
            'is_new',
            'is_recommended',
            'is_searchable',
            'is_visible',
        ];
    }

    /**
     * @return list<string>
     */
    protected function catalogPolicyBooleanFields(): array
    {
        return [
            'returnable',
            'refundable',
            'exchangeable',
            'cancellation_allowed',
            'warranty_available',
            'terms_required',
            'customer_confirmation_required',
            'non_returnable',
            'non_refundable',
            'remote',
            'onsite',
            'provider_required',
            'booking_required',
        ];
    }

    protected function normalizeCatalogBooleanInput(mixed $value): mixed
    {
        if (is_array($value)) {
            $last = end($value);

            return $this->normalizeCatalogBooleanInput($last === false ? null : $last);
        }

        if (is_bool($value) || $value === null) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            if ((int) $value === 1) {
                return true;
            }
            if ((int) $value === 0) {
                return false;
            }

            return $value;
        }

        if (! is_string($value)) {
            return $value;
        }

        $normalized = strtolower(trim($value));

        return match ($normalized) {
            '1', 'true', 'on', 'yes' => true,
            '0', 'false', 'off', 'no', '' => false,
            default => $value,
        };
    }

    protected function mergeNormalizedCatalogBooleans(): void
    {
        $merge = [];

        foreach ($this->catalogItemBooleanFields() as $field) {
            if (! $this->exists($field)) {
                continue;
            }
            $merge[$field] = $this->normalizeCatalogBooleanInput($this->input($field));
        }

        $policies = $this->input('policies');
        if (is_array($policies)) {
            foreach ($this->catalogPolicyBooleanFields() as $policyField) {
                if (! array_key_exists($policyField, $policies)) {
                    continue;
                }
                $policies[$policyField] = $this->normalizeCatalogBooleanInput($policies[$policyField]);
            }
            $merge['policies'] = $policies;
        }

        $variants = $this->input('variants');
        if (is_array($variants)) {
            foreach ($variants as $index => $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                foreach (['is_active', 'is_visible'] as $variantField) {
                    if (! array_key_exists($variantField, $variant)) {
                        continue;
                    }
                    $variants[$index][$variantField] = $this->normalizeCatalogBooleanInput($variant[$variantField]);
                }
            }
            $merge['variants'] = $variants;
        }

        $media = $this->input('media');
        if (is_array($media)) {
            foreach ($media as $index => $row) {
                if (! is_array($row) || ! array_key_exists('is_visible', $row)) {
                    continue;
                }
                $media[$index]['is_visible'] = $this->normalizeCatalogBooleanInput($row['is_visible']);
            }
            $merge['media'] = $media;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
