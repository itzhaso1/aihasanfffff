<?php

declare(strict_types=1);

namespace App\AI\Employees\Legacy;

use App\AI\Skills\SkillRegistry;
use App\Support\Chat\ChatAiRole;

/**
 * Legacy import adapter: role-specific defaults still exist for migration purposes, but
 * the canonical definitions and validation now live in {@see SkillRegistry}.
 */
final class LegacyRoleSkillDefaults
{
    /** @var array<string, list<string>> */
    private const ROLE_DEFAULT_KEYS = [
        ChatAiRole::SALES_AGENT => ['sales', 'product_search', 'product_recommendation', 'human_handoff'],
        ChatAiRole::CUSTOMER_SUPPORT => ['customer_support', 'complaint_handling', 'faq', 'product_search', 'human_handoff'],
        ChatAiRole::PRODUCT_ADVISOR => ['product_search', 'product_recommendation', 'sales', 'human_handoff'],
        ChatAiRole::BOOKING_AGENT => ['customer_support', 'faq', 'human_handoff'],
        ChatAiRole::RECEPTIONIST => ['faq', 'customer_support', 'human_handoff'],
        ChatAiRole::GENERAL => ['faq', 'product_search', 'human_handoff'],
    ];

    /**
     * @return array<string, array<string, mixed>> Skill key => settings.
     */
    public static function for(string $role): array
    {
        return SkillRegistry::emptySettingsForKeys(self::ROLE_DEFAULT_KEYS[ChatAiRole::normalize($role)]);
    }
}
