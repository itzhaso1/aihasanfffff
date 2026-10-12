<?php

namespace Tests\Feature\Feature\Platform;

use App\Models\PlatformAdmin;
use App\Models\User;
use App\Services\AI\AiSafety;
use App\Services\Platform\PlatformAiConfiguration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAiModelSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_open_ai_model_settings(): void
    {
        $this->get('/platform/ai')->assertRedirect(route('platform.login'));
    }

    public function test_regular_user_cannot_open_ai_model_settings(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/platform/ai')
            ->assertRedirect(route('platform.login'));
    }

    public function test_platform_admin_can_change_the_site_wide_model(): void
    {
        $admin = PlatformAdmin::factory()->create();

        $this->actingAs($admin, 'platform_admin')
            ->get(route('platform.ai.edit'))
            ->assertOk()
            ->assertSee('إعداد نموذج الذكاء الاصطناعي', false)
            ->assertSee('الموديل المعتمد', false)
            ->assertSee('احتياطي المنصة', false);

        $this->actingAs($admin, 'platform_admin')
            ->put(route('platform.ai.update'), [
                'provider' => 'openai',
                'model' => 'gpt-4o-mini',
                'fallback_provider' => 'google_ai_studio',
                'fallback_model' => 'gemini-2.5-flash',
            ])
            ->assertRedirect(route('platform.ai.edit'));

        $selected = app(AiSafety::class)->selected();
        $this->assertSame('openai', $selected->provider);
        $this->assertSame('gpt-4o-mini', $selected->model);
        $this->assertSame('google_ai_studio', $selected->fallbackProvider);
        $this->assertSame('gemini-2.5-flash', $selected->fallbackModel);
        $this->assertFalse($selected->usingFallback);
        $this->assertSame('openai', app(AiSafety::class)->resolveProvider('google_ai_studio'));
        $this->assertSame('gpt-4o-mini', app(AiSafety::class)->resolveModel('google_ai_studio', 'gemini-2.0-flash'));
    }

    public function test_platform_fallback_is_used_when_selected_model_leaves_the_allow_list(): void
    {
        $this->setPlatformAi('openai', 'gpt-4o-mini', 'google_ai_studio', 'gemini-2.5-flash');
        config(['ai.allowed_models.openai' => []]);

        app(PlatformAiConfiguration::class)->forget();

        $selected = app(AiSafety::class)->selected();
        $this->assertSame('google_ai_studio', $selected->provider);
        $this->assertSame('gemini-2.5-flash', $selected->model);
        $this->assertTrue($selected->usingFallback);
    }

    public function test_platform_uses_config_default_when_selected_and_fallback_models_leave_allow_list(): void
    {
        $this->setPlatformAi('google_ai_studio', 'gemini-3.6-flash');

        app(PlatformAiConfiguration::class)->forget();

        $selected = app(AiSafety::class)->selected();
        $this->assertSame('google_ai_studio', $selected->provider);
        $this->assertContains($selected->model, config('ai.allowed_models.google_ai_studio'));
        $this->assertTrue($selected->usingFallback);
    }
}
