<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\WorkspaceCompanyProfileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

#[Fillable([
    'workspace_id',
    'company_name',
    'about',
    'offerings',
    'services',
    'audience',
    'reply_guidelines',
])]
class WorkspaceCompanyProfile extends WorkspaceScopedModel
{
    /** @use HasFactory<WorkspaceCompanyProfileFactory> */
    use BelongsToWorkspace, HasFactory;

    public const NAME_MAX = 120;

    public const ABOUT_MAX = 2000;

    public const OFFERINGS_MAX = 2000;

    public const SERVICES_MAX = 2000;

    public const AUDIENCE_MAX = 1000;

    public const REPLY_GUIDELINES_MAX = 2000;

    public function isPresent(): bool
    {
        foreach (['company_name', 'about', 'offerings', 'services', 'audience', 'reply_guidelines'] as $field) {
            if (trim((string) $this->getAttribute($field)) !== '') {
                return true;
            }
        }

        return false;
    }
}
