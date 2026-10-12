<?php

declare(strict_types=1);

namespace App\AI\Employees\Presets;

/**
 * A starter template. It is never an employee and never read by the runtime; creating
 * an employee copies its values into fresh records.
 */
final readonly class EmployeePreset
{
    /**
     * @param  array<string, mixed>  $employee  Employee fields in the API array shape.
     */
    public function __construct(
        public string $key,
        public int $version,
        public string $displayName,
        public ?string $avatarKey,
        public ?string $summary,
        public array $employee,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'display_name' => $this->displayName,
            'avatar_key' => $this->avatarKey,
            'summary' => $this->summary,
            'role' => $this->employee['role'] ?? null,
            'job_title' => $this->employee['job_title'] ?? null,
            'persona' => $this->employee['persona'] ?? [],
            'skills' => $this->employee['skills'] ?? [],
            'training_profile' => $this->employee['training_profile'] ?? null,
        ];
    }
}
