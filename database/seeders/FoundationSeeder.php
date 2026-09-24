<?php

namespace Database\Seeders;

use App\Models\MerchantDocumentType;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Support\Auth\PlatformAdminSeedPassword;
use App\Support\Authorization\FoundationRolePermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class FoundationSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $platformAdminPassword = PlatformAdminSeedPassword::fromEnvironment();

        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId(null);
        $registrar->forgetCachedPermissions();

        $permissions = [
            'workspace.view',
            'workspace.manage',
            'products.manage',
            'inventory.manage',
            'customers.manage',
            'orders.manage',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'conversations.manage',
            'chat.catalog.view',
            'chat.catalog.manage',
            'commerce.catalog.view',
            'commerce.catalog.manage',
            'commerce.catalog.settings.manage',
            'commerce.catalog.items.manage',
            'commerce.catalog.categories.manage',
            'commerce.catalog.inventory.view',
            'commerce.catalog.inventory.manage',
            'commerce.catalog.digital_inventory.view',
            'commerce.catalog.digital_inventory.manage',
            'commerce.cart.view',
            'commerce.cart.manage',
            'commerce.orders.view',
            'commerce.orders.manage',
            'commerce.orders.cancel',
            'commerce.intelligence.view',
            'commerce.intelligence.manage',
            'commerce.intelligence.evaluate',
            'ai.manage',
            'email.manage',
            'whatsapp.manage',
            'payments.manage',
            'employees.manage',
            'subscriptions.manage',
            'finance.view',
            'finance.manage',
            'finance.sales.view',
            'finance.price_lists.view',
            'finance.price_lists.manage',
            'finance.adjustments.view',
            'finance.adjustments.manage',
            'finance.salary_advances.view',
            'finance.salary_advances.manage',
            'finance.fiscal_years.view',
            'finance.fiscal_years.manage',
            'invoices.view',
            'invoices.create',
            'invoices.edit',
            'invoices.cancel',
            'invoices.delete',
            'invoices.issue',
            'invoices.send',
            'invoices.credit',
            'invoices.reverse_payment',
            'invoices.remind',
            'quotes.view',
            'quotes.create',
            'quotes.edit',
            'quotes.issue',
            'quotes.send',
            'quotes.accept',
            'quotes.reject',
            'quotes.convert',
            'quotes.cancel',
            'quotes.delete',
            'payments.view',
            'receipts.view',
            'receipts.send',
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'accounting.view',
            'accounting.manage',
            'payroll.view',
            'payroll.manage',
            'reports.view',
            'finance.settings',
            'journal.view',
            'journal.create',
            'journal.reverse',
            'inventory.adjust',
            'purchases.view',
            'purchases.manage',
            'crm.leads.view',
            'crm.leads.manage',
            'projects.view',
            'projects.manage',
            'contracts.view',
            'contracts.manage',
            'contracts.create',
            'contracts.update',
            'contracts.activate',
            'contracts.cancel',
            'contracts.renew',
            'contracts.delete',
            'contracts.attachments',
            'contracts.invoice',
            'contracts.payments',
        ];

        foreach (array_values(array_unique($permissions)) as $permission) {
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        foreach (['owner', 'admin', 'manager', 'agent', 'receptionist', 'staff_doctor', 'accountant'] as $roleName) {
            Role::findOrCreate($roleName, 'web');
        }

        $ownerRole = Role::findByName('owner', 'web');
        $adminRole = Role::findByName('admin', 'web');
        $managerRole = Role::findByName('manager', 'web');
        $agentRole = Role::findByName('agent', 'web');
        $receptionistRole = Role::findByName('receptionist', 'web');
        $staffDoctorRole = Role::findByName('staff_doctor', 'web');
        $accountantRole = Role::findByName('accountant', 'web');

        $allPermissions = Permission::query()->pluck('name')->all();
        $ownerRole->syncPermissions($allPermissions);
        $adminRole->syncPermissions($allPermissions);
        $managerRole->syncPermissions(FoundationRolePermissions::manager());
        $agentRole->syncPermissions([
            'workspace.view',
            'customers.manage',
            'orders.manage',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'conversations.manage',
            'chat.catalog.view',
            'chat.catalog.manage',
        ]);
        $receptionistRole->syncPermissions([
            'workspace.view',
            'customers.manage',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'conversations.manage',
            'payments.view',
            'payments.manage',
        ]);
        $staffDoctorRole->syncPermissions([
            'workspace.view',
        ]);
        $accountantRole->syncPermissions(FoundationRolePermissions::accountant());

        foreach ($this->commercialPlans() as $plan) {
            Plan::query()->updateOrCreate(['code' => $plan['code']], $plan);
        }

        // Hide legacy catalog rows; keep them for existing subscriptions.
        $officialCodes = ['starter', 'pro', 'business', 'enterprise'];
        if (Schema::hasColumn('plans', 'is_official')) {
            Plan::query()
                ->whereNotIn('code', $officialCodes)
                ->update([
                    'is_public' => false,
                    'is_official' => false,
                ]);
            Plan::query()
                ->whereIn('code', $officialCodes)
                ->update([
                    'is_public' => true,
                    'is_official' => true,
                ]);
        }

        $this->seedMerchantDocumentTypes();

        PlatformAdmin::query()->updateOrCreate(
            ['email' => env('PLATFORM_ADMIN_EMAIL', 'admin@hasem.local')],
            [
                'name' => env('PLATFORM_ADMIN_NAME', 'Platform Admin'),
                'password' => Hash::make($platformAdminPassword),
                'email_verified_at' => now(),
            ]
        );

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function commercialPlans(): array
    {
        $currency = (string) config('plans.currency', 'SAR');
        $matrix = config('plans.feature_matrix', []);
        $overageRules = $this->overageRulesFromMeters();

        $tierMeta = [
            'starter' => [
                'sort_order' => 10,
                'price' => 99,
                'trial_days' => 14,
                'description' => 'للبدء: موقع إلكتروني، تواصل، وذكاء اصطناعي أساسي بدون نقطة بيع أو واتساب.',
                'display_name_ar' => 'المبتدئة',
            ],
            'pro' => [
                'sort_order' => 20,
                'price' => 199,
                'trial_days' => 14,
                'description' => 'للنمو: نقطة بيع، مالية، واتساب، بريد احترافي، وتحليلات.',
                'display_name_ar' => 'الاحترافية',
            ],
            'business' => [
                'sort_order' => 30,
                'price' => 349,
                'trial_days' => 14,
                'description' => 'للأعمال: صلاحيات متقدمة، واجهة برمجة API، وإدارة عملاء CRM.',
                'display_name_ar' => 'الأعمال',
            ],
            'enterprise' => [
                'sort_order' => 40,
                'price' => 499,
                'trial_days' => 30,
                'description' => 'للمؤسسات: تخصيص كامل، تدقيق، وحدود استخدام مرتفعة.',
                'display_name_ar' => 'المؤسسات',
            ],
        ];

        $plans = [];

        // Official shared catalog (workspace_type=company, visible to all workspaces).
        foreach (['starter', 'pro', 'business', 'enterprise'] as $tier) {
            $meta = $tierMeta[$tier];
            $plans[] = $this->planRow(
                code: $tier,
                name: ucfirst($tier),
                displayNameAr: $meta['display_name_ar'],
                description: $meta['description'],
                tier: $tier,
                workspaceType: 'company',
                currency: $currency,
                price: $meta['price'],
                sortOrder: $meta['sort_order'],
                isPublic: true,
                isOfficial: true,
                trialDays: $meta['trial_days'],
                features: $matrix[$tier]['features'] ?? [],
                permissions: $this->permissionsForTier($tier),
                limits: $matrix[$tier]['limits'] ?? [],
                overageRules: $overageRules,
            );
        }

        // Individual plans (legacy codes kept, hidden from public catalog).
        $plans[] = $this->planRow(
            code: 'individual_free',
            name: 'Individual Free',
            displayNameAr: null,
            description: 'باقة فردية مجانية للمحادثات والردود الذكية.',
            tier: 'starter',
            workspaceType: 'individual',
            currency: $currency,
            price: 0,
            sortOrder: 5,
            isPublic: false,
            isOfficial: false,
            trialDays: 0,
            features: ['conversations', 'smart_replies', 'ai', 'subscription', 'usage'],
            permissions: ['workspace.view', 'conversations.manage', 'ai.manage', 'subscriptions.manage'],
            limits: array_merge($matrix['starter']['limits'] ?? [], [
                'ai_usage' => 500,
                'conversations' => 300,
                'whatsapp_numbers' => 0,
                'whatsapp_messages' => 0,
            ]),
            overageRules: $overageRules,
        );

        $plans[] = $this->planRow(
            code: 'individual_pro',
            name: 'Individual Pro',
            displayNameAr: null,
            description: 'باقة فردية احترافية مع واتساب واستخدام أعلى للذكاء الاصطناعي.',
            tier: 'pro',
            workspaceType: 'individual',
            currency: $currency,
            price: 19,
            sortOrder: 15,
            isPublic: false,
            isOfficial: false,
            trialDays: 7,
            features: ['conversations', 'smart_replies', 'ai', 'subscription', 'usage', 'whatsapp'],
            permissions: ['workspace.view', 'conversations.manage', 'ai.manage', 'whatsapp.manage', 'subscriptions.manage'],
            limits: array_merge($matrix['pro']['limits'] ?? [], [
                'ai_usage' => 5000,
                'conversations' => 5000,
                'whatsapp_numbers' => 1,
            ]),
            overageRules: $overageRules,
        );

        $typeLabels = [
            'company' => 'Company',
            'store' => 'Store',
        ];

        // Legacy typed plans kept for existing subscriptions — not public / not official.
        foreach (['company', 'store'] as $workspaceType) {
            $label = $typeLabels[$workspaceType];

            foreach ([
                ['code' => "{$workspaceType}_starter", 'name' => "{$label} Starter"],
                ['code' => "{$workspaceType}_basic", 'name' => "{$label} Basic"],
            ] as $row) {
                $plans[] = $this->planRow(
                    code: $row['code'],
                    name: $row['name'],
                    displayNameAr: $tierMeta['starter']['display_name_ar'],
                    description: $tierMeta['starter']['description'],
                    tier: 'starter',
                    workspaceType: $workspaceType,
                    currency: $currency,
                    price: $tierMeta['starter']['price'],
                    sortOrder: $tierMeta['starter']['sort_order'],
                    isPublic: false,
                    isOfficial: false,
                    trialDays: $tierMeta['starter']['trial_days'],
                    features: $matrix['starter']['features'] ?? [],
                    permissions: $this->permissionsForTier('starter'),
                    limits: $matrix['starter']['limits'] ?? [],
                    overageRules: $overageRules,
                );
            }

            foreach (['pro', 'business', 'enterprise'] as $tier) {
                $plans[] = $this->planRow(
                    code: "{$workspaceType}_{$tier}",
                    name: "{$label} ".ucfirst($tier),
                    displayNameAr: $tierMeta[$tier]['display_name_ar'],
                    description: $tierMeta[$tier]['description'],
                    tier: $tier,
                    workspaceType: $workspaceType,
                    currency: $currency,
                    price: $tierMeta[$tier]['price'],
                    sortOrder: $tierMeta[$tier]['sort_order'],
                    isPublic: false,
                    isOfficial: false,
                    trialDays: $tierMeta[$tier]['trial_days'],
                    features: $matrix[$tier]['features'] ?? [],
                    permissions: $this->permissionsForTier($tier),
                    limits: $matrix[$tier]['limits'] ?? [],
                    overageRules: $overageRules,
                );
            }
        }

        return $plans;
    }

    /**
     * @param  array<int, string>  $features
     * @param  array<int, string>  $permissions
     * @param  array<string, int|float|null>  $limits
     * @param  array<string, string>  $overageRules
     * @return array<string, mixed>
     */
    private function planRow(
        string $code,
        string $name,
        ?string $displayNameAr,
        string $description,
        string $tier,
        string $workspaceType,
        string $currency,
        float|int $price,
        int $sortOrder,
        bool $isPublic,
        bool $isOfficial,
        int $trialDays,
        array $features,
        array $permissions,
        array $limits,
        array $overageRules,
    ): array {
        return [
            'code' => $code,
            'name' => $name,
            'display_name_ar' => $displayNameAr,
            'description' => $description,
            'tier' => $tier,
            'workspace_type' => $workspaceType,
            'billing_period' => 'monthly',
            'trial_days' => $trialDays,
            'currency' => $currency,
            'price' => $price,
            'is_active' => true,
            'is_public' => $isPublic,
            'is_official' => $isOfficial,
            'sort_order' => $sortOrder,
            'features' => array_values($features),
            'permissions' => array_values($permissions),
            'limits' => $limits,
            'overage_rules' => $overageRules,
        ];
    }

    private function seedMerchantDocumentTypes(): void
    {
        if (! Schema::hasTable('merchant_document_types')) {
            return;
        }

        $types = [
            [
                'code' => 'freelance_certificate',
                'name_ar' => 'وثيقة عمل حر',
                'name_en' => 'Freelance certificate',
                'requires_number' => true,
                'requires_expiry' => true,
                'sort_order' => 10,
            ],
            [
                'code' => 'commercial_registration',
                'name_ar' => 'السجل التجاري',
                'name_en' => 'Commercial registration',
                'requires_number' => true,
                'requires_expiry' => true,
                'sort_order' => 20,
            ],
            [
                'code' => 'other_legal',
                'name_ar' => 'مستند نظامي آخر',
                'name_en' => 'Other legal document',
                'requires_number' => false,
                'requires_expiry' => false,
                'sort_order' => 30,
            ],
        ];

        foreach ($types as $type) {
            MerchantDocumentType::query()->updateOrCreate(
                ['code' => $type['code']],
                array_merge($type, ['is_active' => true])
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function overageRulesFromMeters(): array
    {
        $rules = [];
        foreach (config('plans.meters', []) as $meter => $meta) {
            $rules[$meter] = (string) ($meta['overage'] ?? 'hard_block');
        }

        return $rules;
    }

    /**
     * @return array<int, string>
     */
    private function permissionsForTier(string $tier): array
    {
        $starter = [
            'workspace.view',
            'customers.manage',
            'conversations.manage',
            'ai.manage',
            'subscriptions.manage',
            'payments.manage',
        ];

        $pro = array_values(array_unique(array_merge($starter, [
            'workspace.manage',
            'products.manage',
            'inventory.manage',
            'orders.manage',
            'pos.manage',
            'tables.manage',
            'menu.manage',
            'whatsapp.manage',
            'email.manage',
            'employees.manage',
            'finance.view',
            'finance.manage',
            'finance.sales.view',
            'finance.price_lists.view',
            'finance.price_lists.manage',
            'finance.adjustments.view',
            'finance.adjustments.manage',
            'finance.salary_advances.view',
            'finance.salary_advances.manage',
            'finance.fiscal_years.view',
            'finance.fiscal_years.manage',
            'invoices.view',
            'invoices.create',
            'invoices.edit',
            'invoices.cancel',
            'invoices.issue',
            'invoices.send',
            'invoices.credit',
            'invoices.reverse_payment',
            'invoices.remind',
            'quotes.view',
            'quotes.create',
            'quotes.edit',
            'quotes.issue',
            'quotes.send',
            'quotes.accept',
            'quotes.reject',
            'quotes.convert',
            'quotes.cancel',
            'quotes.delete',
            'payments.view',
            'receipts.view',
            'receipts.send',
            'expenses.view',
            'expenses.create',
            'expenses.edit',
            'accounting.view',
            'accounting.manage',
            'payroll.view',
            'reports.view',
            'finance.settings',
            'journal.view',
            'journal.create',
            'journal.reverse',
            'inventory.adjust',
            'purchases.view',
            'purchases.manage',
            'crm.leads.view',
            'crm.leads.manage',
            'projects.view',
            'projects.manage',
            'contracts.view',
            'contracts.manage',
            'contracts.create',
            'contracts.update',
            'contracts.activate',
            'contracts.cancel',
            'contracts.renew',
            'contracts.delete',
            'contracts.attachments',
            'contracts.invoice',
            'contracts.payments',
        ])));

        return match ($tier) {
            'starter' => $starter,
            'pro', 'business', 'enterprise' => $pro,
            default => $starter,
        };
    }
}
