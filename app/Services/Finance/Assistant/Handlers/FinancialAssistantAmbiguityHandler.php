<?php

namespace App\Services\Finance\Assistant\Handlers;

use App\Models\Customer;
use App\Models\Finance\FinanceSupplier;
use App\Models\Workspace;
use App\Services\Finance\Assistant\Conversation\ConversationEntityState;

final class FinancialAssistantAmbiguityHandler
{
    /**
     * @return array{
     *     intent: string,
     *     answer_ar: string,
     *     answer_en: string,
     *     entity: string
     * }|null
     */
    public function match(Workspace $workspace, string $question, ConversationEntityState $state): ?array
    {
        if ($state->entityFocus !== null || $state->customerName !== null) {
            return null;
        }

        $name = $this->bareName($question);
        if ($name === null) {
            return null;
        }

        $hasCustomer = Customer::query()
            ->where('workspace_id', $workspace->id)
            ->where('name', 'like', '%'.$name.'%')
            ->exists();

        $hasSupplier = FinanceSupplier::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($builder) use ($name): void {
                $builder->where('name', 'like', '%'.$name.'%')
                    ->orWhere('arabic_name', 'like', '%'.$name.'%');
            })
            ->exists();

        if (! $hasCustomer || ! $hasSupplier) {
            return null;
        }

        return [
            'intent' => 'entity_ambiguity',
            'entity' => $name,
            'answer_ar' => 'وجدت "'.$name.'" كعميل وكمورد. تقصد رصيد العميل، فواتيره، أم مستحقات المورد؟',
            'answer_en' => 'I found "'.$name.'" as both a customer and a supplier. Do you mean the customer balance, their invoices, or supplier payables?',
        ];
    }

    private function bareName(string $question): ?string
    {
        $trimmed = trim($question);
        if (preg_match('/^(?:طيب\s+)?([\p{L}][\p{L}\s]{1,38}[\p{L}])\??$/u', $trimmed, $matches) !== 1) {
            return null;
        }

        $name = trim(preg_replace('/\s+/u', ' ', $matches[1]) ?? $matches[1]);

        return $name !== '' ? $name : null;
    }
}
