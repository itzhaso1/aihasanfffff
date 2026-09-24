<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorkspace;
use Database\Factories\EmailBrandingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'workspace_id',
    'company_name',
    'company_description',
    'logo_path',
    'primary_color',
    'secondary_color',
    'background_color',
    'text_color',
    'header_style',
    'show_header_logo',
    'show_header_company_name',
    'header_links',
    'footer_email',
    'footer_phone',
    'footer_website',
    'footer_address',
    'footer_copyright',
    'show_footer_company_name',
    'show_footer_email',
    'show_footer_phone',
    'show_footer_website',
    'show_footer_address',
    'show_footer_social',
    'show_footer_copyright',
    'social_linkedin',
    'social_x',
    'social_facebook',
    'social_instagram',
    'social_youtube',
    'cta_label',
    'cta_url',
    'show_cta',
])]
class EmailBranding extends WorkspaceScopedModel
{
    /** @use HasFactory<EmailBrandingFactory> */
    use BelongsToWorkspace, HasFactory;

    protected static function newFactory(): EmailBrandingFactory
    {
        return EmailBrandingFactory::new();
    }

    public const HEADER_STYLES = ['solid', 'split', 'minimal', 'centered'];

    protected function casts(): array
    {
        return [
            'header_links' => 'array',
            'show_header_logo' => 'boolean',
            'show_header_company_name' => 'boolean',
            'show_footer_company_name' => 'boolean',
            'show_footer_email' => 'boolean',
            'show_footer_phone' => 'boolean',
            'show_footer_website' => 'boolean',
            'show_footer_address' => 'boolean',
            'show_footer_social' => 'boolean',
            'show_footer_copyright' => 'boolean',
            'show_cta' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
