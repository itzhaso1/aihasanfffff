<?php

namespace Database\Factories;

use App\Models\EmailBranding;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailBranding>
 */
class EmailBrandingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'company_name' => fake()->company(),
            'company_description' => fake()->sentence(),
            'logo_path' => null,
            'primary_color' => '#0F172A',
            'secondary_color' => '#2563EB',
            'background_color' => '#F4F6FB',
            'text_color' => '#111827',
            'header_style' => 'solid',
            'show_header_logo' => true,
            'show_header_company_name' => true,
            'header_links' => [],
            'show_footer_company_name' => true,
            'show_footer_email' => true,
            'show_footer_phone' => true,
            'show_footer_website' => true,
            'show_footer_address' => true,
            'show_footer_social' => true,
            'show_footer_copyright' => true,
            'show_cta' => false,
        ];
    }
}
