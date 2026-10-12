<?php

declare(strict_types=1);

namespace App\AI\Evaluation\Rules;

use App\AI\Employees\EmployeePersonaOptions;
use App\AI\Evaluation\Contracts\EvaluationRule;
use App\AI\Evaluation\EvaluationCategory;
use App\AI\Evaluation\EvaluationFinding;
use App\AI\Evaluation\EvaluationRequest;
use App\AI\Evaluation\EvaluationSeverity;

final class PersonaAlignmentRule implements EvaluationRule
{
    public const NAME = 'persona_alignment';

    public function name(): string
    {
        return self::NAME;
    }

    public function evaluate(EvaluationRequest $request): array
    {
        $expectations = $request->expectations;
        $text = $request->candidateText();
        $findings = [];

        if ($expectations->hasTrainingSignals()) {
            $present = array_map(
                static fn ($version): string => $version->id,
                $request->decision->trainingVersions,
            );
            foreach ($expectations->requiredTrainingIds as $id) {
                if (! in_array($id, $present, true)) {
                    $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::Persona, EvaluationSeverity::Warn, 'training_mismatch');
                    break;
                }
            }
        }

        if ($text === '' || ! $expectations->hasPersonaSignals()) {
            return $findings;
        }

        $wantsArabic = in_array($expectations->language, ['ar', 'ar-sa'], true)
            || in_array($expectations->dialect, ['saudi', 'gulf', 'najdi', 'hijazi'], true)
            || in_array($expectations->dialectHint, ['saudi', 'gulf'], true);
        if ($wantsArabic && $this->mostlyLatin($text)) {
            $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::Persona, EvaluationSeverity::Warn, 'persona_language_mismatch');
        }

        if (
            $expectations->firstContact
            && $expectations->greetingPolicy === EmployeePersonaOptions::GREETING_POLICIES[0]
            && ! $this->hasGreeting($text)
        ) {
            $findings[] = new EvaluationFinding(self::NAME, EvaluationCategory::Persona, EvaluationSeverity::Warn, 'missing_greeting');
        }

        return $findings;
    }

    private function mostlyLatin(string $text): bool
    {
        $letters = preg_replace('/[^\p{L}]+/u', '', $text) ?? '';
        if (mb_strlen($letters) < 12) {
            return false;
        }
        $latin = preg_replace('/[^A-Za-z]+/', '', $letters) ?? '';

        return (mb_strlen($latin) / mb_strlen($letters)) >= 0.7;
    }

    private function hasGreeting(string $text): bool
    {
        return preg_match('/^(هلا|مرحبا|السلام|سلام|اهلا)/u', $text) === 1;
    }
}
