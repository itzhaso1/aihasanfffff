<?php

declare(strict_types=1);

namespace App\Services\Worker;

use App\Enums\WorkerEngagementDurationUnit;
use App\Enums\WorkerEngagementStatus;
use App\Http\Requests\Worker\WorkerEngagementGuideRules;
use App\Models\ChatCatalogItem;
use App\Models\User;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementAcceptance;
use App\Models\Worker\WorkerEngagementGuide;
use App\Models\Worker\WorkerEngagementProduct;
use App\Models\Worker\WorkerProfile;
use App\Models\Workspace;
use App\Services\Audit\AuditLogService;
use App\Support\Authorization\WorkspaceAccess;
use App\Support\Money\Money;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class WorkerEngagementService
{
    public function __construct(
        private readonly AuditLogService $auditLog,
        private readonly WorkspaceAccess $workspaceAccess,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkerContractRateService $contractRates,
    ) {}

    /**
     * @param array{
     *     worker_profile_id:int,
     *     job_title?:string|null,
     *     job_description?:string|null,
     *     starts_at?:string|null,
     *     ends_at?:string|null,
     *     duration_value?:int|string|null,
     *     duration_unit?:string|null,
     *     compensation_terms_json?:array<string, mixed>|null
     *     products?:list<array{
     *         id:int,
     *         minimum_selling_price?:int|float|string|null,
     *         maximum_selling_price?:int|float|string|null
     *     }>|null
     *     guide?:array<string, mixed>|null
     * } $attributes
     */
    public function createOffer(
        Workspace $workspace,
        User $actor,
        array $attributes,
        ?Request $request = null,
    ): WorkerEngagement {
        $this->assertCompanyCanManage($workspace, $actor);
        $this->assertCurrentWorkspace($workspace);

        $workerProfile = WorkerProfile::query()
            ->whereKey($attributes['worker_profile_id'])
            ->first();

        if ($workerProfile === null || ! $this->isEligible($workerProfile)) {
            throw WorkerEngagementException::workerNotEligible();
        }

        [$startsAt, $endsAt] = $this->resolveEngagementDates($attributes);
        $contractRate = null;
        if (
            isset($attributes['duration_value'], $attributes['duration_unit'])
            && $attributes['duration_value'] !== null
            && $attributes['duration_unit'] !== null
        ) {
            $contractRate = $this->contractRates->resolveForOffer(
                $workerProfile,
                (int) $attributes['duration_value'],
                (string) $attributes['duration_unit'],
            );
        }

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $attributes,
            $workerProfile,
            $startsAt,
            $endsAt,
            $contractRate,
            $request,
        ): WorkerEngagement {
            $engagement = WorkerEngagement::query()->create([
                'worker_profile_id' => $workerProfile->id,
                'workspace_id' => $workspace->id,
                'status' => WorkerEngagementStatus::Draft,
                'job_title' => $attributes['job_title'] ?? null,
                'job_description' => $attributes['job_description'] ?? null,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'duration_value' => $attributes['duration_value'] ?? null,
                'duration_unit' => $attributes['duration_unit'] ?? null,
                'offered_contract_amount_minor' => $contractRate?->amount_minor,
                'offered_contract_currency' => $contractRate?->currency,
                'offered_contract_rate_id' => $contractRate?->id,
                'compensation_terms_json' => $attributes['compensation_terms_json'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->createProductAssignments(
                $workspace,
                $engagement,
                is_array($attributes['products'] ?? null) ? $attributes['products'] : [],
            );
            $this->persistGuide(
                $workspace,
                $engagement,
                is_array($attributes['guide'] ?? null) ? $attributes['guide'] : [],
            );

            $this->transitionAndAudit(
                $engagement,
                WorkerEngagementStatus::Offered,
                $actor,
                $request,
                ['worker_profile_id' => $workerProfile->id],
            );

            return $engagement->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{0: CarbonImmutable|string|null, 1: CarbonImmutable|string|null}
     */
    private function resolveEngagementDates(array $attributes): array
    {
        $durationValue = $attributes['duration_value'] ?? null;
        $durationUnit = $attributes['duration_unit'] ?? null;

        if ($durationValue === null && $durationUnit === null) {
            return [
                $attributes['starts_at'] ?? null,
                $attributes['ends_at'] ?? null,
            ];
        }

        if ($attributes['ends_at'] ?? null) {
            throw new InvalidArgumentException(
                'A manually supplied end date cannot be used with an engagement duration.',
            );
        }

        if (
            $durationValue === null
            || $durationUnit === null
            || (! is_int($durationValue) && ! is_string($durationValue))
            || (is_string($durationValue) && ! ctype_digit($durationValue))
        ) {
            throw new InvalidArgumentException('A valid engagement duration is required.');
        }

        $startsAt = $attributes['starts_at'] ?? null;
        if (! is_string($startsAt) || trim($startsAt) === '') {
            throw new InvalidArgumentException('An engagement start date is required with a duration.');
        }

        $value = (int) $durationValue;
        if ($value < 1) {
            throw new InvalidArgumentException('An engagement duration must be greater than zero.');
        }

        $unit = WorkerEngagementDurationUnit::tryFrom((string) $durationUnit);
        if ($unit === null) {
            throw new InvalidArgumentException('The engagement duration unit is not supported.');
        }

        $start = CarbonImmutable::parse($startsAt);

        return [$start, $unit->addTo($start, $value)];
    }

    /**
     * @param  array<string, mixed>  $guide
     */
    public function updateGuide(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        array $guide,
        ?Request $request = null,
    ): ?WorkerEngagementGuide {
        $this->assertCompanyCanManage($workspace, $actor);
        $this->assertCurrentWorkspace($workspace);

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $engagement,
            $guide,
            $request,
        ): ?WorkerEngagementGuide {
            $lockedEngagement = $this->lockWorkspaceEngagement($workspace, $engagement);
            if (
                ! $lockedEngagement->status instanceof WorkerEngagementStatus
                || ! $lockedEngagement->status->allowsGuideManagement()
            ) {
                throw WorkerEngagementException::guideNotEditable();
            }

            $hadGuide = $lockedEngagement->guide()->exists();
            $savedGuide = $this->persistGuide($workspace, $lockedEngagement, $guide);

            $this->auditLog->log(
                action: 'worker.engagement.guide.updated',
                entityType: WorkerEngagement::class,
                entityId: $lockedEngagement->id,
                oldValues: ['has_guide' => $hadGuide],
                newValues: ['has_guide' => $savedGuide !== null],
                actor: $actor,
                request: $request,
                workspaceId: $workspace->id,
            );

            return $savedGuide;
        });
    }

    /**
     * @return Collection<int, ChatCatalogItem>
     */
    public function availableProducts(Workspace $workspace): Collection
    {
        $this->assertCurrentWorkspace($workspace);

        return ChatCatalogItem::queryForWorkspace((int) $workspace->id)
            ->where('is_active', true)
            ->where('is_visible', true)
            ->where('publication_status', 'published')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get([
                'id',
                'name',
                'description',
                'short_description',
                'price',
                'worker_min_price',
                'worker_max_price',
                'currency',
                'image_path',
            ]);
    }

    /**
     * @return Collection<int, WorkerEngagement>
     */
    public function forWorkspace(Workspace $workspace): Collection
    {
        $this->assertCurrentWorkspace($workspace);

        return WorkerEngagement::query()
            ->with(['workerProfile', 'creator'])
            ->latest('id')
            ->get();
    }

    /**
     * @return Collection<int, WorkerEngagement>
     */
    public function forWorker(User $worker): Collection
    {
        return WorkerEngagement::withoutGlobalScopes()
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            })
            ->with(['workspace', 'workerProfile'])
            ->latest('id')
            ->get();
    }

    public function completeExpiredEngagements(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $completed = 0;

        WorkerEngagement::withoutGlobalScopes()
            ->whereIn('status', [
                WorkerEngagementStatus::Active->value,
                WorkerEngagementStatus::Paused->value,
            ])
            ->whereNotNull('ends_at')
            ->where('ends_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function (Collection $engagements) use (&$completed, $now): void {
                foreach ($engagements as $candidate) {
                    if ($this->completeExpiredEngagement($candidate, $now)) {
                        $completed++;
                    }
                }
            });

        return $completed;
    }

    public function forWorkerOrFail(User $worker, int $engagementId): WorkerEngagement
    {
        return $this->ownedEngagementQuery($worker)
            ->with(['workspace', 'workerProfile'])
            ->whereKey($engagementId)
            ->firstOrFail();
    }

    private function completeExpiredEngagement(
        WorkerEngagement $candidate,
        CarbonInterface $now,
    ): bool {
        return $this->workspaceContext->runForId(
            (int) $candidate->workspace_id,
            function () use ($candidate, $now): bool {
                return DB::transaction(function () use ($candidate, $now): bool {
                    $engagement = WorkerEngagement::query()
                        ->whereKey($candidate->id)
                        ->lockForUpdate()
                        ->first();

                    if (
                        $engagement === null
                        || ! in_array($engagement->status, [
                            WorkerEngagementStatus::Active,
                            WorkerEngagementStatus::Paused,
                        ], true)
                        || $engagement->ends_at === null
                        || $engagement->ends_at->isAfter($now)
                    ) {
                        return false;
                    }

                    $this->transitionAndAudit(
                        $engagement,
                        WorkerEngagementStatus::Completed,
                        null,
                        null,
                        [
                            'source' => 'worker_engagement_expiration',
                            'reason' => 'ends_at_reached',
                        ],
                    );

                    return true;
                });
            },
        );
    }

    public function accept(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->respondToOffer(
            $worker,
            $engagementId,
            WorkerEngagementStatus::Accepted,
            $request,
        );
    }

    public function reject(
        User $worker,
        int $engagementId,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->respondToOffer(
            $worker,
            $engagementId,
            WorkerEngagementStatus::Rejected,
            $request,
        );
    }

    private function respondToOffer(
        User $worker,
        int $engagementId,
        WorkerEngagementStatus $decision,
        ?Request $request,
    ): WorkerEngagement {
        return DB::transaction(function () use (
            $worker,
            $engagementId,
            $decision,
            $request,
        ): WorkerEngagement {
            $engagement = $this->ownedEngagementQuery($worker)
                ->whereKey($engagementId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertWorkerCanRespond($engagement);

            if (
                $decision === WorkerEngagementStatus::Accepted
                && $engagement->ends_at !== null
                && $engagement->ends_at->lessThanOrEqualTo(now())
            ) {
                throw WorkerEngagementException::offerExpired();
            }

            return $this->workspaceContext->runForId(
                (int) $engagement->workspace_id,
                function () use ($engagement, $worker, $decision, $request): WorkerEngagement {
                    if (
                        $decision === WorkerEngagementStatus::Accepted
                        && $engagement->offered_contract_amount_minor !== null
                        && $engagement->agreed_contract_amount_minor === null
                    ) {
                        $engagement->forceFill([
                            'agreed_contract_amount_minor' => $engagement->offered_contract_amount_minor,
                            'agreed_contract_currency' => $engagement->offered_contract_currency,
                            'agreed_contract_at' => now(),
                        ])->save();
                    }

                    $this->transitionAndAudit($engagement, $decision, $worker, $request);
                    $this->recordResponse($engagement, $worker, $decision, $request);

                    return $engagement->refresh();
                },
            );
        });
    }

    public function activate(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->transitionForWorkspace(
            $workspace,
            $actor,
            $engagement,
            WorkerEngagementStatus::Active,
            $request,
        );
    }

    public function pause(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->transitionForWorkspace(
            $workspace,
            $actor,
            $engagement,
            WorkerEngagementStatus::Paused,
            $request,
        );
    }

    public function resume(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->transitionForWorkspace(
            $workspace,
            $actor,
            $engagement,
            WorkerEngagementStatus::Active,
            $request,
        );
    }

    public function complete(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        ?Request $request = null,
    ): WorkerEngagement {
        return $this->transitionForWorkspace(
            $workspace,
            $actor,
            $engagement,
            WorkerEngagementStatus::Completed,
            $request,
        );
    }

    public function terminate(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        string $reason,
        ?Request $request = null,
    ): WorkerEngagement {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A termination reason is required.');
        }

        return DB::transaction(function () use (
            $workspace,
            $actor,
            $engagement,
            $reason,
            $request,
        ): WorkerEngagement {
            $this->assertCompanyCanManage($workspace, $actor);
            $this->assertCurrentWorkspace($workspace);
            $lockedEngagement = $this->lockWorkspaceEngagement($workspace, $engagement);
            $lockedEngagement->forceFill(['termination_reason' => $reason])->save();

            $this->transitionAndAudit(
                $lockedEngagement,
                WorkerEngagementStatus::Terminated,
                $actor,
                $request,
                ['termination_reason' => $reason],
            );

            return $lockedEngagement->refresh();
        });
    }

    private function transitionForWorkspace(
        Workspace $workspace,
        User $actor,
        WorkerEngagement $engagement,
        WorkerEngagementStatus $next,
        ?Request $request,
    ): WorkerEngagement {
        return DB::transaction(function () use (
            $workspace,
            $actor,
            $engagement,
            $next,
            $request,
        ): WorkerEngagement {
            $this->assertCompanyCanManage($workspace, $actor);
            $this->assertCurrentWorkspace($workspace);
            $lockedEngagement = $this->lockWorkspaceEngagement($workspace, $engagement);

            if ($next === WorkerEngagementStatus::Active) {
                $this->assertWorkerCanBeActive($lockedEngagement);
            }

            $this->transitionAndAudit($lockedEngagement, $next, $actor, $request);

            return $lockedEngagement->refresh();
        });
    }

    private function lockWorkspaceEngagement(
        Workspace $workspace,
        WorkerEngagement $engagement,
    ): WorkerEngagement {
        if ((int) $engagement->workspace_id !== (int) $workspace->id) {
            throw WorkerEngagementException::workspaceMismatch();
        }

        return WorkerEngagement::query()
            ->whereKey($engagement->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertCompanyCanManage(Workspace $workspace, User $actor): void
    {
        if (! $this->workspaceAccess->canManageMembers($actor, $workspace)) {
            throw new AuthorizationException('You are not authorized to manage worker engagements.');
        }
    }

    private function assertCurrentWorkspace(Workspace $workspace): void
    {
        if ((int) $this->workspaceContext->workspaceId() !== (int) $workspace->id) {
            throw WorkerEngagementException::workspaceMismatch();
        }
    }

    private function assertWorkerCanRespond(WorkerEngagement $engagement): void
    {
        if (! $this->isEligible($engagement->workerProfile)) {
            throw WorkerEngagementException::workerNotEligible();
        }
    }

    private function assertWorkerCanBeActive(WorkerEngagement $engagement): void
    {
        $workerProfile = WorkerProfile::query()
            ->whereKey($engagement->worker_profile_id)
            ->lockForUpdate()
            ->first();

        if ($workerProfile === null || ! $this->isEligible($workerProfile)) {
            throw WorkerEngagementException::workerNotEligible();
        }

        $alreadyActive = WorkerEngagement::query()
            ->where('worker_profile_id', $workerProfile->id)
            ->where('status', WorkerEngagementStatus::Active->value)
            ->whereKeyNot($engagement->id)
            ->exists();

        if ($alreadyActive) {
            throw WorkerEngagementException::activeEngagementExists();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assignments
     */
    private function createProductAssignments(
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $assignments,
    ): void {
        if ($assignments === []) {
            return;
        }

        $normalized = [];
        foreach ($assignments as $assignment) {
            $productId = $assignment['id'] ?? null;
            if (! is_int($productId) && ! (is_string($productId) && ctype_digit($productId))) {
                throw WorkerEngagementException::invalidProductAssignment();
            }

            $productId = (int) $productId;
            if ($productId < 1 || array_key_exists($productId, $normalized)) {
                throw WorkerEngagementException::invalidProductAssignment();
            }

            $minimum = $this->normalizeAssignmentPrice($assignment['minimum_selling_price'] ?? null);
            $maximum = $this->normalizeAssignmentPrice($assignment['maximum_selling_price'] ?? null);

            if (
                $minimum !== null
                && $maximum !== null
                && Money::cmp($minimum, $maximum) > 0
            ) {
                throw WorkerEngagementException::invalidProductPriceRange();
            }

            $normalized[$productId] = [
                'minimum_selling_price' => $minimum,
                'maximum_selling_price' => $maximum,
            ];
        }

        $products = ChatCatalogItem::queryForWorkspace((int) $workspace->id)
            ->whereIn('id', array_keys($normalized))
            ->get(['id'])
            ->keyBy('id');

        if ($products->count() !== count($normalized)) {
            throw WorkerEngagementException::invalidProductAssignment();
        }

        foreach ($normalized as $productId => $prices) {
            WorkerEngagementProduct::query()->create([
                'workspace_id' => $workspace->id,
                'worker_engagement_id' => $engagement->id,
                'chat_catalog_item_id' => $productId,
                'minimum_selling_price' => $prices['minimum_selling_price'],
                'maximum_selling_price' => $prices['maximum_selling_price'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $guide
     */
    private function persistGuide(
        Workspace $workspace,
        WorkerEngagement $engagement,
        array $guide,
    ): ?WorkerEngagementGuide {
        $normalized = WorkerEngagementGuideRules::normalize($guide);
        if ($normalized === []) {
            $engagement->guide()->delete();

            return null;
        }

        $payload = [
            'workspace_id' => $workspace->id,
            'company_overview' => $normalized['company_overview'] ?? null,
            'products_context' => $normalized['products_context'] ?? null,
            'responsibilities' => $normalized['responsibilities'] ?? [],
            'workflow_steps' => $normalized['workflow_steps'] ?? [],
            'important_rules' => $normalized['important_rules'] ?? [],
            'customer_guidance' => $normalized['customer_guidance'] ?? [],
            'escalation_rules' => $normalized['escalation_rules'] ?? [],
            'work_schedule_notes' => $normalized['work_schedule_notes'] ?? null,
            'important_notes' => $normalized['important_notes'] ?? null,
            'faq_items' => $normalized['faq_items'] ?? [],
        ];

        return WorkerEngagementGuide::query()->updateOrCreate(
            ['worker_engagement_id' => $engagement->id],
            $payload,
        );
    }

    private function normalizeAssignmentPrice(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            throw WorkerEngagementException::invalidProductPrice();
        }

        try {
            $price = Money::of($value);
        } catch (InvalidArgumentException) {
            throw WorkerEngagementException::invalidProductPrice();
        }
        if (Money::cmp($price, '0') < 0) {
            throw WorkerEngagementException::invalidProductPrice();
        }

        return $price;
    }

    private function isEligible(WorkerProfile $workerProfile): bool
    {
        return $workerProfile->verification_status === WorkerProfile::VERIFICATION_APPROVED
            && $workerProfile->lifecycle_status === WorkerProfile::LIFECYCLE_VERIFIED;
    }

    private function ownedEngagementQuery(User $worker): Builder
    {
        return WorkerEngagement::withoutGlobalScopes()
            ->whereHas('workerProfile', function (Builder $query) use ($worker): void {
                $query->where('user_id', $worker->id);
            });
    }

    private function recordResponse(
        WorkerEngagement $engagement,
        User $worker,
        WorkerEngagementStatus $decision,
        ?Request $request,
    ): WorkerEngagementAcceptance {
        return WorkerEngagementAcceptance::query()->create([
            'worker_engagement_id' => $engagement->id,
            'user_id' => $worker->id,
            'party' => 'worker',
            'decision' => $decision->value,
            'accepted_at' => $decision === WorkerEngagementStatus::Accepted ? now() : null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request?->userAgent(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transitionAndAudit(
        WorkerEngagement $engagement,
        WorkerEngagementStatus $next,
        ?User $actor,
        ?Request $request,
        array $meta = [],
    ): void {
        $previous = $engagement->status;
        $engagement->transitionTo($next);

        $this->auditLog->log(
            action: 'worker.engagement.transitioned',
            entityType: WorkerEngagement::class,
            entityId: $engagement->id,
            oldValues: ['status' => $previous->value],
            newValues: ['status' => $next->value],
            actor: $actor,
            request: $request,
            meta: array_merge($meta, [
                'from_status' => $previous->value,
                'to_status' => $next->value,
            ]),
            workspaceId: $engagement->workspace_id,
        );
    }
}
