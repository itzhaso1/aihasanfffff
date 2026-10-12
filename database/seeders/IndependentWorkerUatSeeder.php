<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ConversationHandoffReason;
use App\Enums\ConversationHandoffStatus;
use App\Enums\WorkerEarningPeriodStatus;
use App\Enums\WorkerEngagementStatus;
use App\Models\ChatCatalogItem;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\PlatformAdmin;
use App\Models\User;
use App\Models\Worker\WorkerDiscoverySettings;
use App\Models\Worker\WorkerDispute;
use App\Models\Worker\WorkerEarningPeriod;
use App\Models\Worker\WorkerEngagement;
use App\Models\Worker\WorkerEngagementProduct;
use App\Models\Worker\WorkerIdentityPrivate;
use App\Models\Worker\WorkerPayoutRecord;
use App\Models\Worker\WorkerProfile;
use App\Models\Worker\WorkerProfileLanguage;
use App\Models\Worker\WorkerSkill;
use App\Models\Worker\WorkerVerification;
use App\Models\Worker\WorkerVerificationDocument;
use App\Models\Worker\WorkerWorkExperience;
use App\Models\Worker\WorkerWorkSession;
use App\Models\Workspace;
use App\Services\Conversation\ConversationHandoffService;
use App\Services\Worker\WorkerDisputeService;
use App\Services\Worker\WorkerEarningsService;
use App\Services\Worker\WorkerEngagementCapabilityService;
use App\Services\Worker\WorkerEngagementService;
use App\Services\Worker\WorkerPayoutService;
use App\Services\Worker\WorkerWorkSessionService;
use App\Support\Auth\PlatformAdminSeedPassword;
use App\Support\Tenancy\WorkspaceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class IndependentWorkerUatSeeder extends Seeder
{
    private const COMPANY_A_EMAIL = 'uat.company.a@independent-worker.test';

    private const COMPANY_B_EMAIL = 'uat.company.b@independent-worker.test';

    private const WORKER_A_EMAIL = 'uat.worker.a@independent-worker.test';

    private const WORKER_B_EMAIL = 'uat.worker.b@independent-worker.test';

    private const WORKER_C_EMAIL = 'uat.worker.discovery@independent-worker.test';

    private const REVIEW_WORKER_EMAIL = 'uat.worker.review@independent-worker.test';

    private const COMPANY_A_SLUG = 'independent-worker-uat-company-a';

    private const COMPANY_B_SLUG = 'independent-worker-uat-company-b';

    private const PROFILE_PHOTO_PATH = 'uat/independent-worker/profile.png';

    private const VERIFICATION_DOCUMENT_PATH = 'uat/independent-worker/verification.pdf';

    public function __construct(
        private readonly ConversationHandoffService $handoff,
        private readonly WorkerDisputeService $disputes,
        private readonly WorkerEarningsService $earnings,
        private readonly WorkerEngagementCapabilityService $capabilities,
        private readonly WorkerEngagementService $engagements,
        private readonly WorkerPayoutService $payouts,
        private readonly WorkspaceContext $workspaceContext,
        private readonly WorkerWorkSessionService $sessions,
    ) {}

    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException('Independent Worker UAT data is restricted to local or testing environments.');
        }

        [$workerPassword, $workerPasswordSource] = $this->readUatPassword(
            'INDEPENDENT_WORKER_UAT_PASSWORD',
        );
        [$companyPassword, $companyPasswordSource] = $this->readUatPassword(
            'INDEPENDENT_COMPANY_UAT_PASSWORD',
        );

        $this->call(WorkerCapabilitySeeder::class);
        $this->call(WorkerSkillSeeder::class);
        $this->writeUatAssets();

        $platformAdmin = PlatformAdmin::query()->first();
        if ($platformAdmin === null) {
            throw new RuntimeException(
                'A platform admin is required to create the UAT payout record. Run the existing foundation seeder first.',
            );
        }

        $companyA = $this->seedUser(
            email: self::COMPANY_A_EMAIL,
            name: 'شركة UAT الرئيسية',
            phone: '+966500000101',
            password: $companyPassword,
        );
        $companyB = $this->seedUser(
            email: self::COMPANY_B_EMAIL,
            name: 'شركة UAT للعزل',
            phone: '+966500000102',
            password: $companyPassword,
        );
        $workerA = $this->seedUser(
            email: self::WORKER_A_EMAIL,
            name: 'عامل UAT الرئيسي',
            phone: '+966500000201',
            password: $workerPassword,
        );
        $workerB = $this->seedUser(
            email: self::WORKER_B_EMAIL,
            name: 'عامل UAT آخر',
            phone: '+966500000202',
            password: $workerPassword,
        );
        $workerC = $this->seedUser(
            email: self::WORKER_C_EMAIL,
            name: 'عامل UAT قابل للاكتشاف',
            phone: '+966500000203',
            password: $workerPassword,
        );
        $reviewWorker = $this->seedUser(
            email: self::REVIEW_WORKER_EMAIL,
            name: 'عامل UAT يحتاج مستندات',
            phone: '+966500000204',
            password: $workerPassword,
        );

        $workspaceA = $this->seedWorkspace(
            owner: $companyA,
            name: 'شركة UAT — المسار الرئيسي',
            slug: self::COMPANY_A_SLUG,
        );
        $workspaceB = $this->seedWorkspace(
            owner: $companyB,
            name: 'شركة UAT — مساحة العزل',
            slug: self::COMPANY_B_SLUG,
        );

        $profileA = $this->seedWorkerProfile(
            user: $workerA,
            displayName: 'سامي العُمري — عامل UAT',
            professionalSummary: 'متخصص مبيعات وخدمة عملاء لاختبارات مسار العامل المستقل.',
            discoverable: true,
            profilePhotoPath: self::PROFILE_PHOTO_PATH,
        );
        $profileB = $this->seedWorkerProfile(
            user: $workerB,
            displayName: 'ليان الحسن — عامل العزل',
            professionalSummary: 'متخصصة في متابعة العملاء واختبارات عزل الارتباطات.',
            discoverable: true,
            profilePhotoPath: self::PROFILE_PHOTO_PATH,
        );
        $profileC = $this->seedWorkerProfile(
            user: $workerC,
            displayName: 'نور الخطيب — عامل قابل للاكتشاف',
            professionalSummary: 'متخصصة مبيعات جاهزة لاستقبال عرض UAT جديد.',
            discoverable: true,
            profilePhotoPath: self::PROFILE_PHOTO_PATH,
        );
        $reviewProfile = $this->seedWorkerProfile(
            user: $reviewWorker,
            displayName: 'عامل يحتاج مستندات',
            professionalSummary: 'ملف اختبار لحالة طلب مستندات إضافية.',
            discoverable: false,
            profilePhotoPath: null,
            verificationStatus: WorkerProfile::VERIFICATION_DOCUMENTS_REQUIRED,
            lifecycleStatus: WorkerProfile::LIFECYCLE_VERIFICATION_PENDING,
        );

        $this->seedApprovedVerification($profileA, $workerA, $platformAdmin);
        $this->seedApprovedVerification($profileB, $workerB, $platformAdmin);
        $this->seedApprovedVerification($profileC, $workerC, $platformAdmin);
        $this->seedDocumentsRequiredVerification($reviewProfile, $reviewWorker);

        $now = CarbonImmutable::now();
        $productsA = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): array => $this->seedProducts($workspaceA),
        );
        $productsB = $this->workspaceContext->runFor(
            $workspaceB,
            fn (): array => $this->seedProducts($workspaceB),
        );

        $mainEngagement = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerEngagement => $this->ensureEngagement(
                workspace: $workspaceA,
                owner: $companyA,
                worker: $workerA,
                profile: $profileA,
                jobTitle: 'مندوب مبيعات — أجهزة UAT',
                jobDescription: 'عرض المنتجات، مساعدة العملاء، وتسجيل نتائج التواصل وفق دليل العمل.',
                startsAt: $now->subDay(),
                endsAt: $now->addDays(30),
                guide: $this->mainGuide(),
                products: $productsA,
                desiredStatus: WorkerEngagementStatus::Active,
            ),
        );
        $pausedEngagement = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerEngagement => $this->ensureEngagement(
                workspace: $workspaceA,
                owner: $companyA,
                worker: $workerB,
                profile: $profileB,
                jobTitle: 'دعم العملاء — ارتباط متوقف UAT',
                jobDescription: 'ارتباط متوقف لاختبار العزل وحالات إيقاف العمل.',
                startsAt: $now->subDay(),
                endsAt: $now->addDays(30),
                guide: $this->pausedGuide(),
                products: [$productsA[0]],
                desiredStatus: WorkerEngagementStatus::Paused,
            ),
        );
        $offeredEngagement = $this->workspaceContext->runFor(
            $workspaceB,
            fn (): WorkerEngagement => $this->ensureEngagement(
                workspace: $workspaceB,
                owner: $companyB,
                worker: $workerA,
                profile: $profileA,
                jobTitle: 'عرض مبيعات — شركة UAT للعزل',
                jobDescription: 'عرض مستقل لاختبار قبول العرض وعزل الشركة.',
                startsAt: $now,
                endsAt: $now->addDays(14),
                guide: $this->secondaryGuide(),
                products: [$productsB[0]],
                desiredStatus: WorkerEngagementStatus::Offered,
            ),
        );
        $companyBActiveEngagement = $this->workspaceContext->runFor(
            $workspaceB,
            fn (): WorkerEngagement => $this->ensureEngagement(
                workspace: $workspaceB,
                owner: $companyB,
                worker: $workerA,
                profile: $profileA,
                jobTitle: 'مسؤول مبيعات — شركة UAT للعزل',
                jobDescription: 'متابعة عملاء الشركة الثانية واختبار سياق Inbox مستقل.',
                startsAt: $now->subDay(),
                endsAt: $now->addDays(30),
                guide: $this->companyBGuide(),
                products: $productsB,
                desiredStatus: WorkerEngagementStatus::Active,
            ),
        );

        $this->workspaceContext->runFor($workspaceA, function () use (
            $companyA,
            $mainEngagement,
            $pausedEngagement,
        ): void {
            foreach ([
                [
                    $mainEngagement,
                    [
                        'inbox.messages.read',
                        'inbox.messages.send',
                        'customers.view',
                        'catalog.products.view',
                    ],
                ],
                [
                    $pausedEngagement,
                    [
                        'inbox.messages.read',
                        'catalog.products.view',
                    ],
                ],
            ] as [$engagement, $capabilityKeys]) {
                foreach ($capabilityKeys as $capabilityKey) {
                    $this->capabilities->grant($companyA, $engagement, $capabilityKey);
                }
            }
        });
        $this->workspaceContext->runFor($workspaceB, function () use (
            $companyB,
            $companyBActiveEngagement,
        ): void {
            foreach ([
                'inbox.messages.read',
                'inbox.messages.send',
                'customers.view',
                'catalog.products.view',
            ] as $capabilityKey) {
                $this->capabilities->grant(
                    $companyB,
                    $companyBActiveEngagement,
                    $capabilityKey,
                );
            }
        });

        $session = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerWorkSession => $this->ensureCompletedSession(
                worker: $workerA,
                engagement: $mainEngagement,
            ),
        );

        $approvedPeriod = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerEarningPeriod => $this->ensureEarningPeriod(
                owner: $companyA,
                engagement: $mainEngagement,
                startsAt: '2026-09-01 00:00:00',
                endsAt: '2026-09-30 23:59:59',
                finalStatus: WorkerEarningPeriodStatus::Approved,
                sourceSession: $session,
                baseAmountMinor: 10000,
                description: 'أجر أساسي لفترة الاختبار.',
            ),
        );
        $pendingPeriod = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerEarningPeriod => $this->ensureEarningPeriod(
                owner: $companyA,
                engagement: $mainEngagement,
                startsAt: '2026-10-01 00:00:00',
                endsAt: '2026-10-31 23:59:59',
                finalStatus: WorkerEarningPeriodStatus::PendingApproval,
                sourceSession: null,
                baseAmountMinor: 8500,
                description: 'فترة أرباح جاهزة للاعتراض قبل الاعتماد.',
            ),
        );
        $disputeReadyPeriod = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): WorkerEarningPeriod => $this->ensureEarningPeriod(
                owner: $companyA,
                engagement: $mainEngagement,
                startsAt: '2026-08-01 00:00:00',
                endsAt: '2026-08-31 23:59:59',
                finalStatus: WorkerEarningPeriodStatus::Approved,
                sourceSession: null,
                baseAmountMinor: 7000,
                description: 'فترة معتمدة قابلة لاعتراض Worker مستقل.',
            ),
        );

        $payout = $this->ensurePayout($platformAdmin, $approvedPeriod);
        $workerDispute = $this->ensureWorkerDispute($workerA, $mainEngagement);
        $conversationA = $this->workspaceContext->runFor(
            $workspaceA,
            fn (): Conversation => $this->ensureConversation(
                engagement: $mainEngagement,
                worker: $workerA,
                owner: $companyA,
                externalId: 'uat-worker-main-conversation',
                customerEmail: 'uat.customer@independent-worker.test',
                customerName: 'عميل UAT — شركة A',
                customerPhone: '+962790000303',
                messages: [
                    [
                        'external_id' => 'uat-worker-main-inbound-1',
                        'content' => 'مرحبًا حسن، هذه محادثة تجريبية من الشركة الأولى.',
                    ],
                    [
                        'external_id' => 'uat-worker-main-inbound-2',
                        'content' => 'نريد اختبار الـInbox الخاص بالشركة الأولى.',
                    ],
                    [
                        'external_id' => 'uat-worker-main-inbound-3',
                        'content' => 'هذه رسالة إضافية للتأكد من الترتيب.',
                    ],
                ],
            ),
        );
        $conversationB = $this->workspaceContext->runFor(
            $workspaceB,
            fn (): Conversation => $this->ensureConversation(
                engagement: $companyBActiveEngagement,
                worker: $workerA,
                owner: $companyB,
                externalId: 'uat-worker-company-b-conversation',
                customerEmail: 'uat.customer.company.b@independent-worker.test',
                customerName: 'عميل UAT — شركة B',
                customerPhone: '+962790000304',
                messages: [
                    [
                        'external_id' => 'uat-worker-company-b-inbound-1',
                        'content' => 'مرحبًا حسن، هذه محادثة تجريبية من الشركة الثانية.',
                    ],
                    [
                        'external_id' => 'uat-worker-company-b-inbound-2',
                        'content' => 'نريد اختبار الـInbox الخاص بالشركة الثانية.',
                    ],
                    [
                        'external_id' => 'uat-worker-company-b-inbound-3',
                        'content' => 'هذه رسالة إضافية للتأكد من عزل الشركة الثانية.',
                    ],
                ],
            ),
        );

        $summary = [
            'accounts' => [
                'company_a' => ['id' => $companyA->id, 'email' => $companyA->email],
                'company_b' => ['id' => $companyB->id, 'email' => $companyB->email],
                'worker_a' => ['id' => $workerA->id, 'email' => $workerA->email],
                'worker_b' => ['id' => $workerB->id, 'email' => $workerB->email],
                'worker_discovery' => ['id' => $workerC->id, 'email' => $workerC->email],
                'worker_review' => ['id' => $reviewWorker->id, 'email' => $reviewWorker->email],
            ],
            'passwords' => [
                'worker_source' => $workerPasswordSource,
                'company_source' => $companyPasswordSource,
                'printed' => false,
            ],
            'workspaces' => [
                'company_a' => ['id' => $workspaceA->id, 'name' => $workspaceA->name],
                'company_b' => ['id' => $workspaceB->id, 'name' => $workspaceB->name],
            ],
            'profiles' => [
                'worker_a' => $profileA->id,
                'worker_b' => $profileB->id,
                'worker_discovery' => $profileC->id,
                'worker_review' => $reviewProfile->id,
            ],
            'engagements' => [
                'active' => [
                    'id' => $mainEngagement->id,
                    'status' => $mainEngagement->status->value,
                ],
                'company_b_active' => [
                    'id' => $companyBActiveEngagement->id,
                    'status' => $companyBActiveEngagement->status->value,
                ],
                'paused' => [
                    'id' => $pausedEngagement->id,
                    'status' => $pausedEngagement->status->value,
                ],
                'offered' => [
                    'id' => $offeredEngagement->id,
                    'status' => $offeredEngagement->status->value,
                ],
            ],
            'products' => [
                'company_a' => collect($productsA)->pluck('id')->values()->all(),
                'company_b' => collect($productsB)->pluck('id')->values()->all(),
            ],
            'session' => [
                'id' => $session->id,
                'status' => $session->status->value,
            ],
            'earnings' => [
                'approved_with_payout' => [
                    'period_id' => $approvedPeriod->id,
                    'status' => $approvedPeriod->status->value,
                ],
                'pending_approval_ready_to_dispute' => [
                    'period_id' => $pendingPeriod->id,
                    'status' => $pendingPeriod->status->value,
                ],
                'approved_ready_to_dispute' => [
                    'period_id' => $disputeReadyPeriod->id,
                    'status' => $disputeReadyPeriod->status->value,
                ],
            ],
            'payout' => $payout === null
                ? null
                : ['id' => $payout->id, 'status' => $payout->status->value],
            'worker_dispute' => [
                'id' => $workerDispute->id,
                'status' => $workerDispute->status->value,
            ],
            'conversations' => [
                'company_a' => [
                    'id' => $conversationA->id,
                    'engagement_id' => $mainEngagement->id,
                    'assigned_user_id' => $conversationA->assigned_user_id,
                ],
                'company_b' => [
                    'id' => $conversationB->id,
                    'engagement_id' => $companyBActiveEngagement->id,
                    'assigned_user_id' => $conversationB->assigned_user_id,
                ],
            ],
            'conversation' => [
                'id' => $conversationA->id,
                'engagement_id' => $mainEngagement->id,
            ],
        ];

        if ($this->command !== null) {
            $this->command->info('Independent Worker UAT dataset is ready.');
            $this->command->line(json_encode(
                $summary,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));
        }
    }

    /**
     * @return array{0:string, 1:string}
     */
    private function readUatPassword(string $key): array
    {
        $configured = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if (is_string($configured) && trim($configured) !== '') {
            return [
                PlatformAdminSeedPassword::require($configured),
                $key,
            ];
        }

        return [
            PlatformAdminSeedPassword::fromEnvironment(),
            'PLATFORM_ADMIN_PASSWORD (local UAT fallback)',
        ];
    }

    private function seedUser(
        string $email,
        string $name,
        string $phone,
        string $password,
    ): User {
        return User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'phone' => $phone,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'phone_verified_at' => now(),
            ],
        );
    }

    private function seedWorkspace(User $owner, string $name, string $slug): Workspace
    {
        $workspace = Workspace::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'uuid' => (string) Str::uuid(),
                'name' => $name,
                'type' => 'company',
                'owner_user_id' => $owner->id,
                'status' => 'active',
                'settings' => [],
            ],
        );

        $workspace->forceFill([
            'name' => $name,
            'type' => 'company',
            'owner_user_id' => $owner->id,
            'status' => 'active',
        ])->save();

        if (! $workspace->users()->whereKey($owner->id)->exists()) {
            $workspace->users()->attach($owner->id, [
                'membership_role' => 'owner',
                'status' => 'active',
                'is_primary' => true,
                'joined_at' => now(),
            ]);
        } else {
            $workspace->users()->updateExistingPivot($owner->id, [
                'membership_role' => 'owner',
                'status' => 'active',
                'is_primary' => true,
                'joined_at' => now(),
            ]);
        }

        return $workspace->refresh();
    }

    private function seedWorkerProfile(
        User $user,
        string $displayName,
        string $professionalSummary,
        bool $discoverable,
        ?string $profilePhotoPath,
        string $verificationStatus = WorkerProfile::VERIFICATION_APPROVED,
        string $lifecycleStatus = WorkerProfile::LIFECYCLE_VERIFIED,
    ): WorkerProfile {
        $profile = WorkerProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'display_name' => $displayName,
                'profile_photo' => $profilePhotoPath,
                'country_code' => WorkerProfile::COUNTRY_JORDAN,
                'nationality' => 'أردني',
                'verification_status' => $verificationStatus,
                'lifecycle_status' => $lifecycleStatus,
                'availability_status' => WorkerProfile::AVAILABILITY_AVAILABLE,
                'professional_summary' => $professionalSummary,
                'sales_experience_level' => 'senior',
            ],
        );

        WorkerIdentityPrivate::query()->updateOrCreate(
            ['worker_profile_id' => $profile->id],
            [
                'legal_name' => $displayName,
                'date_of_birth' => '1992-04-15',
                'identity_number' => 'UAT-IDENTITY-'.$profile->id,
                'address' => 'عمّان — بيانات اختبار محمية',
            ],
        );
        WorkerDiscoverySettings::query()->updateOrCreate(
            ['worker_profile_id' => $profile->id],
            ['is_discoverable' => $discoverable],
        );

        $skillIds = WorkerSkill::query()
            ->whereIn('skill_key', ['sales', 'customer_support', 'inbox_operations'])
            ->pluck('id')
            ->all();
        $profile->skills()->sync($skillIds);

        foreach (['ar', 'en'] as $languageCode) {
            WorkerProfileLanguage::query()->updateOrCreate([
                'worker_profile_id' => $profile->id,
                'language_code' => $languageCode,
            ]);
        }

        WorkerWorkExperience::query()->updateOrCreate(
            [
                'worker_profile_id' => $profile->id,
                'title' => 'أخصائي مبيعات وخدمة عملاء',
                'organization_name' => 'شركة UAT السابقة',
            ],
            [
                'started_on' => '2022-01-01',
                'ended_on' => null,
                'is_current' => true,
                'description' => 'خبرة في شرح المنتجات ومتابعة العملاء.',
            ],
        );

        return $profile->refresh();
    }

    private function seedApprovedVerification(
        WorkerProfile $profile,
        User $worker,
        PlatformAdmin $platformAdmin,
    ): void {
        $verification = WorkerVerification::query()->firstOrNew([
            'worker_profile_id' => $profile->id,
        ]);
        $verification->forceFill([
            'status' => WorkerVerification::STATUS_APPROVED,
            'submitted_at' => now()->subDays(3),
            'reviewed_at' => now()->subDays(2),
            'reviewed_by' => $platformAdmin->id,
            'rejection_reason' => null,
            'review_notes' => 'UAT internal reviewer note — يجب ألا يظهر للعامل.',
        ])->save();

        WorkerVerificationDocument::query()->updateOrCreate(
            [
                'worker_profile_id' => $profile->id,
                'document_type_code' => 'freelance_certificate',
            ],
            [
                'worker_verification_id' => $verification->id,
                'disk' => 'local',
                'path' => self::VERIFICATION_DOCUMENT_PATH,
                'original_name' => 'uat-freelance-certificate.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 512,
                'status' => WorkerVerificationDocument::STATUS_APPROVED,
                'rejection_reason' => null,
                'uploaded_by' => $worker->id,
                'reviewed_by' => $platformAdmin->id,
                'reviewed_at' => now()->subDays(2),
            ],
        );
    }

    private function seedDocumentsRequiredVerification(
        WorkerProfile $profile,
        User $worker,
    ): void {
        $verification = WorkerVerification::query()->firstOrNew([
            'worker_profile_id' => $profile->id,
        ]);
        $verification->forceFill([
            'status' => WorkerVerification::STATUS_DOCUMENTS_REQUIRED,
            'submitted_at' => now()->subDay(),
            'reviewed_at' => null,
            'reviewed_by' => null,
            'rejection_reason' => 'يرجى رفع مستند إضافي واضح.',
            'review_notes' => null,
        ])->save();

        WorkerVerificationDocument::query()->updateOrCreate(
            [
                'worker_profile_id' => $profile->id,
                'document_type_code' => 'commercial_registration',
            ],
            [
                'worker_verification_id' => $verification->id,
                'disk' => 'local',
                'path' => self::VERIFICATION_DOCUMENT_PATH,
                'original_name' => 'uat-document-pending.pdf',
                'mime_type' => 'application/pdf',
                'size_bytes' => 512,
                'status' => WorkerVerificationDocument::STATUS_SUBMITTED,
                'rejection_reason' => null,
                'uploaded_by' => $worker->id,
                'reviewed_by' => null,
                'reviewed_at' => null,
            ],
        );
    }

    /**
     * @return array<int, ChatCatalogItem>
     */
    private function seedProducts(Workspace $workspace): array
    {
        $imagePath = 'uat/independent-worker/product.png';

        $first = ChatCatalogItem::query()->updateOrCreate(
            ['slug' => 'uat-smartphone-pro'],
            [
                'workspace_id' => $workspace->id,
                'name' => 'هاتف UAT Pro',
                'description' => 'هاتف ذكي تجريبي لاختبار المنتجات والأسعار المسموحة للعامل.',
                'short_description' => 'هاتف ذكي للاختبار.',
                'sku' => 'UAT-PHONE-001',
                'item_kind' => 'product',
                'tangibility' => 'tangible',
                'price' => '899.00',
                'worker_min_price' => '850.00',
                'worker_max_price' => '1100.00',
                'currency' => 'SAR',
                'stock' => 40,
                'inventory_policy' => 'finite_stock',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'image_path' => $imagePath,
                'attributes' => ['uat_key' => 'smartphone-pro'],
                'tags' => ['uat', 'worker'],
                'sort_order' => 10,
            ],
        );
        $second = ChatCatalogItem::query()->updateOrCreate(
            ['slug' => 'uat-headset'],
            [
                'workspace_id' => $workspace->id,
                'name' => 'سماعة UAT اللاسلكية',
                'description' => 'سماعة تجريبية مرتبطة بنفس تعاقد العامل.',
                'short_description' => 'سماعة لاسلكية للاختبار.',
                'sku' => 'UAT-HEADSET-001',
                'item_kind' => 'product',
                'tangibility' => 'tangible',
                'price' => '199.00',
                'worker_min_price' => '175.00',
                'worker_max_price' => '250.00',
                'currency' => 'SAR',
                'stock' => 80,
                'inventory_policy' => 'finite_stock',
                'is_active' => true,
                'is_visible' => true,
                'is_searchable' => true,
                'publication_status' => 'published',
                'published_at' => now(),
                'image_path' => $imagePath,
                'attributes' => ['uat_key' => 'headset'],
                'tags' => ['uat', 'worker'],
                'sort_order' => 20,
            ],
        );

        return [$first, $second];
    }

    /**
     * @param  array<string, mixed>  $guide
     * @param  array<int, ChatCatalogItem>  $products
     */
    private function ensureEngagement(
        Workspace $workspace,
        User $owner,
        User $worker,
        WorkerProfile $profile,
        string $jobTitle,
        string $jobDescription,
        CarbonImmutable $startsAt,
        CarbonImmutable $endsAt,
        array $guide,
        array $products,
        WorkerEngagementStatus $desiredStatus,
    ): WorkerEngagement {
        $engagement = WorkerEngagement::query()
            ->where('worker_profile_id', $profile->id)
            ->where('job_title', $jobTitle)
            ->first();
        $created = $engagement === null;

        if ($created) {
            $engagement = $this->engagements->createOffer($workspace, $owner, [
                'worker_profile_id' => $profile->id,
                'job_title' => $jobTitle,
                'job_description' => $jobDescription,
                'starts_at' => $startsAt->toDateTimeString(),
                'ends_at' => $endsAt->toDateTimeString(),
                'compensation_terms_json' => [
                    'uat_key' => Str::slug($jobTitle),
                    'currency' => 'SAR',
                    'payment_note' => 'بيانات تعويض يدوية للاختبار فقط.',
                ],
                'products' => collect($products)->map(fn (ChatCatalogItem $product): array => [
                    'id' => $product->id,
                    'minimum_selling_price' => $product->worker_min_price,
                    'maximum_selling_price' => $product->worker_max_price,
                ])->all(),
                'guide' => $guide,
            ]);
        } elseif ($engagement->status->allowsGuideManagement()) {
            $this->engagements->updateGuide($workspace, $owner, $engagement, $guide);
        }

        $engagement = $this->advanceEngagement(
            workspace: $workspace,
            owner: $owner,
            worker: $worker,
            engagement: $engagement,
            desiredStatus: $desiredStatus,
        );

        foreach ($products as $product) {
            WorkerEngagementProduct::query()->updateOrCreate(
                [
                    'workspace_id' => $workspace->id,
                    'worker_engagement_id' => $engagement->id,
                    'chat_catalog_item_id' => $product->id,
                ],
                [
                    'minimum_selling_price' => $product->worker_min_price,
                    'maximum_selling_price' => $product->worker_max_price,
                ],
            );
        }

        return $engagement->refresh();
    }

    private function advanceEngagement(
        Workspace $workspace,
        User $owner,
        User $worker,
        WorkerEngagement $engagement,
        WorkerEngagementStatus $desiredStatus,
    ): WorkerEngagement {
        if ($desiredStatus === WorkerEngagementStatus::Offered) {
            if ($engagement->status !== WorkerEngagementStatus::Offered) {
                throw new RuntimeException(
                    "The UAT offered engagement [{$engagement->id}] cannot be moved back to offered.",
                );
            }

            return $engagement;
        }

        if ($engagement->status === WorkerEngagementStatus::Offered) {
            $engagement = $this->engagements->accept($worker, $engagement->id);
        }

        if (
            $desiredStatus === WorkerEngagementStatus::Active
            && $engagement->status === WorkerEngagementStatus::Accepted
        ) {
            $engagement = $this->engagements->activate($workspace, $owner, $engagement);
        }

        if (
            $desiredStatus === WorkerEngagementStatus::Paused
            && in_array($engagement->status, [
                WorkerEngagementStatus::Accepted,
                WorkerEngagementStatus::Active,
            ], true)
        ) {
            if ($engagement->status === WorkerEngagementStatus::Accepted) {
                $engagement = $this->engagements->activate($workspace, $owner, $engagement);
            }

            $engagement = $this->engagements->pause($workspace, $owner, $engagement);
        }

        if (
            $desiredStatus === WorkerEngagementStatus::Active
            && $engagement->status === WorkerEngagementStatus::Paused
        ) {
            $engagement = $this->engagements->resume($workspace, $owner, $engagement);
        }

        if ($engagement->status !== $desiredStatus) {
            throw new RuntimeException(
                "The UAT engagement [{$engagement->id}] could not reach [{$desiredStatus->value}].",
            );
        }

        return $engagement;
    }

    private function ensureCompletedSession(
        User $worker,
        WorkerEngagement $engagement,
    ): WorkerWorkSession {
        $session = WorkerWorkSession::query()
            ->where('worker_engagement_id', $engagement->id)
            ->latest('id')
            ->first();

        if ($session?->status->value === 'started') {
            return $this->sessions->end($worker, $engagement->id);
        }

        if ($session !== null) {
            return $session;
        }

        $session = $this->sessions->start($worker, $engagement->id);
        $this->sessions->beginBreak($worker, $engagement->id);
        $this->sessions->resume($worker, $engagement->id);

        return $this->sessions->end($worker, $engagement->id);
    }

    private function ensureEarningPeriod(
        User $owner,
        WorkerEngagement $engagement,
        string $startsAt,
        string $endsAt,
        WorkerEarningPeriodStatus $finalStatus,
        ?WorkerWorkSession $sourceSession,
        int $baseAmountMinor,
        string $description,
    ): WorkerEarningPeriod {
        $period = WorkerEarningPeriod::query()
            ->where('worker_engagement_id', $engagement->id)
            ->where('starts_at', $startsAt)
            ->where('ends_at', $endsAt)
            ->first();

        if ($period === null) {
            $period = $this->earnings->createPeriod(
                $owner,
                $engagement,
                $startsAt,
                $endsAt,
            );
        }

        if ($period->status === WorkerEarningPeriodStatus::Draft) {
            if (! $period->earningLines()->exists()) {
                $this->earnings->createLine(
                    actor: $owner,
                    engagementId: $engagement->id,
                    periodId: $period->id,
                    attributes: [
                        'line_type' => 'base',
                        'source_type' => 'engagement_terms',
                        'amount_minor' => $baseAmountMinor,
                        'currency' => 'SAR',
                        'description' => $description,
                    ],
                );

                if ($sourceSession !== null) {
                    $this->earnings->createLine(
                        actor: $owner,
                        engagementId: $engagement->id,
                        periodId: $period->id,
                        attributes: [
                            'line_type' => 'commission',
                            'source_type' => 'work_session',
                            'source_id' => $sourceSession->id,
                            'amount_minor' => 2500,
                            'currency' => 'SAR',
                            'description' => 'حافز مرتبط بجلسة عمل مكتملة.',
                        ],
                    );
                }
            }

            $period = $this->earnings->submit($owner, $engagement->id, $period->id);
        }

        if (
            $finalStatus === WorkerEarningPeriodStatus::Approved
            && $period->status === WorkerEarningPeriodStatus::PendingApproval
        ) {
            $period = $this->earnings->approve(
                actor: $owner,
                engagementId: $engagement->id,
                periodId: $period->id,
                notes: 'اعتماد فترة UAT للاختبار اليدوي.',
            );
        }

        if (
            $finalStatus === WorkerEarningPeriodStatus::Approved
            && $period->status === WorkerEarningPeriodStatus::Disputed
        ) {
            return $period->refresh();
        }

        if ($period->status !== $finalStatus) {
            throw new RuntimeException(
                "The UAT earning period [{$period->id}] is [{$period->status->value}], expected [{$finalStatus->value}].",
            );
        }

        return $period->refresh();
    }

    private function ensurePayout(
        PlatformAdmin $platformAdmin,
        WorkerEarningPeriod $period,
    ): ?WorkerPayoutRecord {
        $existing = WorkerPayoutRecord::withoutGlobalScopes()
            ->where('worker_earning_period_id', $period->id)
            ->latest('id')
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->payouts->create($platformAdmin, $period->id);
    }

    private function ensureWorkerDispute(
        User $worker,
        WorkerEngagement $engagement,
    ): WorkerDispute {
        $existing = WorkerDispute::withoutGlobalScopes()
            ->where('worker_engagement_id', $engagement->id)
            ->where('reported_by_user_id', $worker->id)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        return $this->disputes->openForWorker(
            worker: $worker,
            engagementId: $engagement->id,
            attributes: [
                'category' => 'access',
                'description' => 'أحتاج توضيحًا حول صلاحية استخدام المنتجات المحددة لهذا العمل.',
            ],
        );
    }

    /**
     * @param  list<array{external_id:string, content:string}>  $messages
     */
    private function ensureConversation(
        WorkerEngagement $engagement,
        User $worker,
        User $owner,
        string $externalId,
        string $customerEmail,
        string $customerName,
        string $customerPhone,
        array $messages,
    ): Conversation {
        $customer = Customer::query()->updateOrCreate(
            ['email' => $customerEmail],
            [
                'workspace_id' => $engagement->workspace_id,
                'name' => $customerName,
                'phone' => $customerPhone,
                'party_type' => Customer::PARTY_TYPE_INDIVIDUAL,
                'country_code' => 'JO',
                'last_conversation_at' => now(),
            ],
        );
        $conversation = Conversation::query()->updateOrCreate(
            ['external_id' => $externalId],
            [
                'workspace_id' => $engagement->workspace_id,
                'customer_id' => $customer->id,
                'channel' => 'manual',
                'status' => 'open',
                'ai_enabled' => false,
                'last_message_at' => now(),
                'metadata' => ['uat_key' => $externalId],
            ],
        );

        $conversation = $this->ensureWorkerConversationAssignment(
            conversation: $conversation,
            worker: $worker,
            owner: $owner,
            engagement: $engagement,
        );

        foreach ($messages as $message) {
            Message::query()->updateOrCreate(
                ['external_message_id' => $message['external_id']],
                [
                    'workspace_id' => $engagement->workspace_id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customer->id,
                    'user_id' => null,
                    'worker_engagement_id' => null,
                    'direction' => 'inbound',
                    'message_type' => 'text',
                    'content' => $message['content'],
                    'ai_generated' => false,
                    'metadata' => ['uat_key' => $externalId],
                ],
            );
        }

        return $conversation->refresh();
    }

    private function ensureWorkerConversationAssignment(
        Conversation $conversation,
        User $worker,
        User $owner,
        WorkerEngagement $engagement,
    ): Conversation {
        if (
            (int) $conversation->assigned_user_id === $worker->id
            && (int) $conversation->assigned_worker_engagement_id === $engagement->id
        ) {
            return $conversation;
        }

        if ($conversation->handoff_status === ConversationHandoffStatus::None) {
            $conversation = $this->handoff->request(
                $conversation,
                ConversationHandoffReason::AgentRequested,
                ['requested_by' => 'staff', 'source' => 'uat-seeder'],
                queueImmediately: true,
            );
        }

        return $this->handoff->assign($conversation, $worker, $engagement, $owner);
    }

    /**
     * @return array<string, mixed>
     */
    private function mainGuide(): array
    {
        return [
            'company_overview' => 'شركة UAT تعمل في بيع الأجهزة والإكسسوارات وتقديم تجربة خدمة عملاء واضحة.',
            'products_context' => 'نبيع هواتف ذكية وسماعات لاسلكية. الأسعار المعروضة في هذا التعاقد هي المرجع المسموح للعامل.',
            'responsibilities' => [
                'استقبال استفسار العميل وفهم احتياجه.',
                'شرح خصائص المنتجات المحددة لهذا التعاقد.',
                'تسجيل نتيجة التواصل في المحادثة.',
            ],
            'workflow_steps' => [
                'ابدأ بتحية مهنية واسأل عن الاستخدام المطلوب.',
                'اقترح المنتج المناسب ثم وضّح السعر الأساسي والنطاق المسموح.',
                'أكد التوفر قبل إعطاء وعد نهائي للعميل.',
            ],
            'important_rules' => [
                'لا تعرض سعرًا أقل من الحد الأدنى أو أعلى من الحد الأعلى.',
                'لا تطلب بيانات دفع أو هوية خارج القنوات المعتمدة.',
                'لا تنشئ التزامًا استثنائيًا دون موافقة المسؤول.',
            ],
            'customer_guidance' => [
                'استخدم لغة عربية واضحة ومحترمة.',
                'لا تجادل العميل، وارفع الشكوى عند الحاجة.',
            ],
            'escalation_rules' => [
                'تواصل مع المسؤول عند طلب خصم خارج النطاق.',
                'تواصل مع المسؤول عند وجود شكوى حساسة أو طلب غير معتاد.',
            ],
            'work_schedule_notes' => 'من الأحد إلى الخميس، من 09:00 إلى 17:00 بتوقيت عمّان. الجلسات يسجلها الخادم.',
            'important_notes' => 'دليل العمل خاص بهذا التعاقد فقط ولا يمثل ملفًا عامًا للشركة.',
            'faq_items' => [
                [
                    'question' => 'ما السعر الذي أستطيع عرضه؟',
                    'answer' => 'استخدم الحد الأدنى والحد الأعلى الظاهرين في قسم المنتجات لهذا التعاقد.',
                ],
                [
                    'question' => 'متى أطلب تدخل المسؤول؟',
                    'answer' => 'عند الخصومات الاستثنائية أو الشكاوى أو أي طلب خارج التعليمات.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pausedGuide(): array
    {
        return [
            'company_overview' => 'ارتباط UAT ثانٍ لاختبار العزل والحالة المتوقفة مؤقتًا.',
            'responsibilities' => ['قراءة التعليمات ومراجعة المنتجات المحددة.'],
            'important_rules' => ['لا تبدأ جلسة جديدة أثناء الإيقاف المؤقت.'],
            'work_schedule_notes' => 'يُستأنف العمل بعد تغيير الحالة من قبل الشركة.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function companyBGuide(): array
    {
        return [
            'company_overview' => 'شركة UAT الثانية تختبر سياق Inbox مستقلًا عن الشركة الأولى.',
            'responsibilities' => [
                'متابعة استفسارات العملاء الخاصين بالشركة الثانية.',
                'تسجيل نتيجة التواصل داخل محادثة الشركة.',
            ],
            'workflow_steps' => [
                'ابدأ بتحية مهنية واضحة.',
                'اعرض منتجات الشركة الثانية فقط.',
                'ارفع الطلبات غير المعتادة إلى مسؤول الشركة.',
            ],
            'important_rules' => [
                'لا تخلط بيانات العملاء أو المنتجات بين الشركتين.',
                'لا تقدم سعرًا خارج النطاق المسموح للمنتج.',
            ],
            'work_schedule_notes' => 'الجلسات والتوقيتات مصدرها الخادم لأغراض اختبار UAT.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function secondaryGuide(): array
    {
        return [
            'company_overview' => 'شركة UAT ثانية لاختبار عرض مستقل وعزل بيانات الشركات.',
            'responsibilities' => ['مراجعة العرض قبل القبول.'],
            'workflow_steps' => ['اقرأ دليل العمل ثم قرر القبول أو الرفض.'],
            'important_notes' => 'هذا العرض منفصل عن ارتباطات الشركة الأولى.',
        ];
    }

    private function writeUatAssets(): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        if ($png === false) {
            throw new RuntimeException('Could not prepare the UAT image asset.');
        }

        Storage::disk('local')->put(self::PROFILE_PHOTO_PATH, $png);
        Storage::disk('local')->put('uat/independent-worker/product.png', $png);
        Storage::disk('local')->put(
            self::VERIFICATION_DOCUMENT_PATH,
            "%PDF-1.4\n% UAT verification placeholder\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
        );
    }
}
