<?php

declare(strict_types=1);

namespace App\Services\Worker;

use RuntimeException;

final class WorkerVerificationRequirementCatalog
{
    /**
     * @return list<string>
     */
    public function requiredDocumentTypes(string $countryCode): array
    {
        $countries = config('worker_verification_requirements.countries', []);

        if (! is_array($countries) || ! array_key_exists($countryCode, $countries)) {
            throw new RuntimeException('Unsupported worker verification country.');
        }

        $required = $countries[$countryCode]['required'] ?? [];

        return is_array($required) ? array_values($required) : [];
    }
}
