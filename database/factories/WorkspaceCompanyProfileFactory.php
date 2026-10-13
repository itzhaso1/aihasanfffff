<?php

namespace Database\Factories;

use App\Models\Workspace;
use App\Models\WorkspaceCompanyProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WorkspaceCompanyProfile>
 */
class WorkspaceCompanyProfileFactory extends Factory
{
    protected $model = WorkspaceCompanyProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'company_name' => 'شركة تجريبية',
            'about' => 'نبيع منتجات التجزئة ونخدم العملاء عبر المحادثة.',
            'offerings' => 'ملابس وأحذية',
            'services' => 'توصيل واستبدال',
            'audience' => null,
            'reply_guidelines' => null,
        ];
    }
}
