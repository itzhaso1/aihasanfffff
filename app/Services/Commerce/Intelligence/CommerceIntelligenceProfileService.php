<?php

namespace App\Services\Commerce\Intelligence;

use App\Enums\Commerce\CommerceDomain;
use App\Models\Commerce\CommerceIntelligenceProfile;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Tenancy\WorkspaceContext;

final class CommerceIntelligenceProfileService
{
    public function __construct(
        private readonly AuditLogService $audit,
        private readonly WorkspaceContext $workspaceContext,
    ) {}

    public function forWorkspace(Workspace $workspace): CommerceIntelligenceProfile
    {
        return $this->workspaceContext->runFor($workspace, function () use ($workspace): CommerceIntelligenceProfile {
            return CommerceIntelligenceProfile::query()->firstOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'primary_domain' => CommerceDomain::Custom,
                    'secondary_domains' => [],
                    'enabled_capabilities' => ['attributes', 'requirements', 'compatibility', 'rules'],
                    'metadata' => ['version' => '1.0'],
                ],
            );
        });
    }

    public function updateProfile(
        Workspace $workspace,
        CommerceDomain|string $primaryDomain,
        array $secondaryDomains = [],
        array $enabledCapabilities = [],
        array $metadata = [],
        ?User $actor = null,
    ): CommerceIntelligenceProfile {
        $domainEnum = $primaryDomain instanceof CommerceDomain
            ? $primaryDomain
            : (CommerceDomain::tryFrom($primaryDomain) ?? CommerceDomain::Custom);

        $profile = $this->forWorkspace($workspace);

        $profile->update([
            'primary_domain' => $domainEnum,
            'secondary_domains' => array_values(array_unique($secondaryDomains)),
            'enabled_capabilities' => array_values(array_unique($enabledCapabilities)),
            'metadata' => array_merge($profile->metadata ?? [], $metadata),
        ]);

        $this->audit->log(
            action: 'commerce.intelligence.profile_updated',
            entityType: CommerceIntelligenceProfile::class,
            entityId: (int) $profile->id,
            actor: $actor,
            meta: [
                'primary_domain' => $domainEnum->value,
                'secondary_domains' => $secondaryDomains,
            ],
            workspaceId: (int) $workspace->id,
        );

        return $profile->fresh() ?? $profile;
    }
}
