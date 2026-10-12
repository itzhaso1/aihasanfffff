<?php

declare(strict_types=1);

namespace App\AI\Employees;

use App\AI\Core\Prompt\PromptLayer;
use App\AI\Core\Prompt\PromptSection;
use App\AI\Employees\Models\AiEmployee;

/**
 * Presents one employee's own configuration as employee-layer prompt sections.
 * Identity and persona come from this employee record so the model speaks as that
 * employee; a generic workspace assistant name must not replace it.
 */
final class EmployeePromptRenderer
{
    private const SOURCE_PREFIX = 'employee:';

    /**
     * @return list<PromptSection>
     */
    public function sections(AiEmployee $employee): array
    {
        $source = self::SOURCE_PREFIX.$employee->id;
        $version = (string) $employee->config_version;
        $persona = $employee->persona;

        $sections = [
            new PromptSection(PromptLayer::Employee, 'employee.identity', $this->identityContent($employee), $source, $version),
            new PromptSection(PromptLayer::Employee, 'employee.communication', $this->lines([
                'Language' => $this->personaFact($persona?->language),
                'Dialect' => $this->personaFact($persona?->dialect),
                'Formality' => $this->personaFact($persona?->formality),
                'Tone' => $this->personaFact($persona?->tone),
                'Emoji policy' => $this->personaFact($persona?->emoji_policy),
                'Greeting policy' => $this->personaFact($persona?->greeting_policy),
                'Traits' => $persona?->traits !== null && $persona->traits !== [] ? implode(', ', $persona->traits) : null,
                'Style notes' => $persona?->style_notes,
            ]), $source, $version),
        ];
        if (trim((string) $employee->instructions) !== '') {
            $sections[] = new PromptSection(PromptLayer::Employee, 'employee.instructions', (string) $employee->instructions, $source, $version);
        }
        if (trim((string) $employee->experience_notes) !== '') {
            $sections[] = new PromptSection(PromptLayer::Employee, 'employee.experience', (string) $employee->experience_notes, $source, $version);
        }

        return $sections;
    }

    private function identityContent(AiEmployee $employee): string
    {
        $facts = $this->lines([
            'Name' => $employee->name,
            'Job title' => $employee->job_title,
            'Role' => $employee->role,
            'Gender' => $employee->gender,
            'Domain' => $this->personaFact($employee->training_profile),
            'Skills' => $employee->skillKeys() === [] ? null : implode(', ', $employee->skillKeys()),
        ]);

        $identity = implode("\n", [
            'Speak as this configured employee. The facts below are the only identity you may state.',
            'Answer the question asked, using only those facts for your name, role, or job. Do not add a greeting or repeat the introduction unless the customer only greeted you or asked who you are.',
            'Language and dialect describe how you speak. They are not nationality, origin, age, city, or branch.',
            'If a personal fact is not listed, say you do not have that detail. Do not guess.',
            'Do not disclose that you are an AI, a model, or a robot. If the customer asks whether you are human, stay in the configured persona and social style and do not add a technical identity disclosure.',
        ]);

        return $facts === '' ? $identity : $identity."\n".$facts;
    }

    /**
     * @param  array<string, ?string>  $values
     */
    private function lines(array $values): string
    {
        $lines = [];
        foreach ($values as $label => $value) {
            if ($value !== null && trim($value) !== '') {
                $lines[] = $label.': '.trim($value);
            }
        }

        return implode("\n", $lines);
    }

    private function personaFact(?string $value): ?string
    {
        $text = is_string($value) ? trim($value) : '';
        if ($text === '' || $text === EmployeePersonaOptions::AUTO) {
            return null;
        }

        return $text;
    }
}
