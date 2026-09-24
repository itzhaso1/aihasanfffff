<?php

namespace Tests\Feature\Feature\Security;

use App\Http\Middleware\SecurityHeaders;
use App\Models\Plan;
use App\Models\PosMenuItem;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Email\EmailBrandingService;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SecurityHeadersMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        config()->set('security-headers.enabled', true);
        config()->set('security-headers.hsts.enabled', false);
    }

    public function test_public_web_response_includes_baseline_security_headers(): void
    {
        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_https_production_request_includes_hsts(): void
    {
        config()->set('security-headers.hsts.enabled', true);
        config()->set('security-headers.hsts.max_age', 31536000);

        $request = Request::create('/privacy', 'GET', server: $this->httpsServer());
        $response = app(SecurityHeaders::class)->handle(
            $request,
            fn (): Response => response('ok'),
        );

        $this->assertSame('max-age=31536000', $response->headers->get('Strict-Transport-Security'));
    }

    public function test_http_local_request_does_not_receive_hsts(): void
    {
        config()->set('security-headers.hsts.enabled', true);

        $response = $this->get('/privacy');

        $response->assertOk();
        $response->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_api_json_response_keeps_body_and_status_and_adds_headers(): void
    {
        $response = $this->getJson('/api/auth/me');

        $response->assertUnauthorized();
        $response->assertJsonStructure(['message']);
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_public_qr_menu_page_is_not_broken_by_security_headers(): void
    {
        $workspace = $this->qrMenuWorkspace();

        $response = $this->get(route('menu.general', ['workspace' => $workspace->slug]));

        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertSee($workspace->name, false);
    }

    public function test_email_branding_preview_keeps_sameorigin_framing_for_workspace_iframe(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();

        Sanctum::actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->post(route('workspace.emails.branding.preview'), [
            'company_name' => 'Header Test Co',
        ]);

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('text/html', (string) $response->headers->get('Content-Type'));
    }

    public function test_webhook_json_response_is_unchanged_and_includes_headers(): void
    {
        config()->set('meta.webhook_verify_token', 'meta-verify-token');

        $payload = ['object' => 'whatsapp', 'entry' => []];
        $response = $this->postJson('/meta-webhook', $payload);

        $response->assertForbidden();
        $response->assertJsonPath('message', 'Invalid signature');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    public function test_existing_controller_frame_options_are_not_overridden(): void
    {
        [$owner, $workspace] = $this->workspaceOwner();

        Sanctum::actingAs($owner);
        $this->withSession(['current_workspace_id' => $workspace->id]);

        $response = $this->post(route('workspace.emails.branding.preview'), [
            'company_name' => 'Frame Options',
        ]);

        $response->assertOk();
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    /**
     * @return array<string, string>
     */
    private function httpsServer(): array
    {
        return [
            'HTTPS' => 'on',
            'SERVER_PORT' => '443',
        ];
    }

    private function qrMenuWorkspace(): Workspace
    {
        [$owner, $workspace] = $this->workspaceOwner();
        $this->enableWorkspaceFeatures($workspace, ['pos', 'qr_menu']);

        PosMenuItem::factory()->create([
            'workspace_id' => $workspace->id,
            'name' => 'QR Header Latte',
            'is_active' => true,
        ]);

        return $workspace;
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function workspaceOwner(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Security Headers Co',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $this->enableWorkspaceFeature($workspace, 'email');

        $plan = Plan::query()->where('workspace_type', 'company')->where('is_active', true)->first();
        if ($plan) {
            Subscription::withoutGlobalScopes()->updateOrCreate(
                ['workspace_id' => $workspace->id],
                [
                    'workspace_id' => $workspace->id,
                    'plan_id' => $plan->id,
                    'status' => 'active',
                    'starts_at' => now()->subDay(),
                    'ends_at' => now()->addMonth(),
                ]
            );
        }

        app(EmailBrandingService::class)->forWorkspace($workspace);

        return [$user, $workspace];
    }
}
