<?php

namespace Tests\Feature\Feature\Meta;

use App\Jobs\Customer\ResolveCustomerChannelProfileJob;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\CustomerChannelIdentity;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Services\Customer\CustomerChannelIdentityService;
use App\Services\Meta\Facebook\FacebookProfileResolver;
use App\Services\Meta\Facebook\FacebookWebhookService;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FacebookProfileResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('meta.graph_api_version', 'v26.0');
        Cache::flush();
    }

    public function test_resolves_display_name_from_first_and_last_name(): void
    {
        [$identity] = $this->identityWithPageConnection('page-1', 'psid-100');
        $this->fakeGraphProfile('psid-100', [
            'first_name' => 'Layla',
            'last_name' => 'Hassan',
            'profile_pic' => ['data' => ['url' => 'https://cdn.example/avatar.jpg']],
        ]);

        $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertSame('Layla Hassan', $profile['display_name'] ?? null);
        $this->assertSame('https://cdn.example/avatar.jpg', $profile['avatar_url'] ?? null);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), '/psid-100')) {
                return false;
            }
            $fields = $request->data()['fields'] ?? null;

            return $fields === 'first_name,last_name,profile_pic';
        });
    }

    public function test_resolves_first_name_only(): void
    {
        [$identity] = $this->identityWithPageConnection('page-2', 'psid-200');
        $this->fakeGraphProfile('psid-200', [
            'first_name' => 'Omar',
            'last_name' => '',
        ]);

        $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertSame('Omar', $profile['display_name'] ?? null);
    }

    public function test_resolves_last_name_only(): void
    {
        [$identity] = $this->identityWithPageConnection('page-3', 'psid-300');
        $this->fakeGraphProfile('psid-300', [
            'first_name' => '',
            'last_name' => 'Saleh',
        ]);

        $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertSame('Saleh', $profile['display_name'] ?? null);
    }

    public function test_graph_api_failure_is_logged_and_returns_null(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        [$identity] = $this->identityWithPageConnection('page-4', 'psid-400');

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => 'Invalid OAuth access token - Cannot parse access token',
                    'type' => 'OAuthException',
                    'code' => 190,
                ],
            ], 400),
        ]);

        $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertNull($profile);
        $this->assertTrue(
            collect($logged)->contains(
                fn (MessageLogged $event): bool => $event->message === 'facebook.profile.graph_failed'
                    && ($event->context['http_status'] ?? null) === 400,
            ),
        );
    }

    public function test_no_page_connection_returns_null_and_logs(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Facebook User',
            'phone' => 'fb:psid-500',
            'client_reference' => 'facebook:psid-500',
        ]);
        $identity = CustomerChannelIdentity::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_user_id' => 'psid-500',
        ]);

        Http::fake();

        $profile = app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertNull($profile);
        $this->assertTrue(
            collect($logged)->contains(
                fn (MessageLogged $event): bool => $event->message === 'facebook.profile.page_connection_missing',
            ),
        );
        Http::assertNothingSent();
    }

    public function test_job_updates_customer_name_on_success(): void
    {
        [$identity, $customer] = $this->identityWithPageConnection('page-6', 'psid-600', 'Facebook User');
        $this->fakeGraphProfile('psid-600', [
            'first_name' => 'Nour',
            'last_name' => 'Ali',
        ]);

        (new ResolveCustomerChannelProfileJob((int) $identity->id, (int) $identity->workspace_id))
            ->handle(app(CustomerChannelIdentityService::class));

        $customer = Customer::withoutGlobalScopes()->findOrFail($customer->id);
        $identity = CustomerChannelIdentity::withoutGlobalScopes()->findOrFail($identity->id);

        $this->assertSame('Nour Ali', $identity->display_name);
        $this->assertSame('Nour Ali', $customer->name);
    }

    public function test_logs_do_not_contain_page_access_token(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        [$identity] = $this->identityWithPageConnection('page-7', 'psid-700');
        $secretToken = 'page-access-token-super-secret-xyz';

        Http::fake([
            'graph.facebook.com/*' => Http::response([
                'error' => [
                    'message' => '(#100) access_token='.$secretToken.' is invalid',
                ],
            ], 400),
        ]);

        app(FacebookProfileResolver::class)->resolveForIdentity($identity);

        $this->assertNotEmpty($logged);
        foreach ($logged as $event) {
            $encoded = json_encode([
                'message' => $event->message,
                'context' => $event->context,
            ]);
            $this->assertIsString($encoded);
            $this->assertStringNotContainsString($secretToken, $encoded);
            $this->assertStringNotContainsString('page-access-token-test', $encoded);
        }
    }

    public function test_existing_fallback_customer_redispatches_profile_job_on_duplicate_inbound(): void
    {
        Bus::fake([ResolveCustomerChannelProfileJob::class]);

        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);
        $pageId = '112233445566778';
        $senderId = '998877665544332';

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => $pageId,
            'display_name' => 'Test Page',
            'status' => 'connected',
            'scopes' => ['pages_messaging'],
            'metadata' => ['channel' => 'facebook_messenger'],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('page-token');
        $connection->save();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Facebook User',
            'phone' => 'fb:'.$senderId,
            'client_reference' => 'facebook:'.$senderId,
        ]);

        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:'.$senderId,
            'status' => 'open',
            'metadata' => [
                'facebook_user_id' => $senderId,
                'facebook_page_id' => $pageId,
            ],
        ]);

        Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'existing',
            'external_message_id' => 'mid.fb.existing',
        ]);

        CustomerChannelIdentity::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_user_id' => $senderId,
        ]);

        app(FacebookWebhookService::class)->storeIncomingMessage($workspace->id, [
            'sender_id' => $senderId,
            'recipient_id' => $pageId,
            'message_id' => 'mid.fb.existing',
            'text' => 'existing',
            'message_type' => 'text',
            'attachments' => [],
            'metadata' => [],
        ]);

        Bus::assertDispatched(ResolveCustomerChannelProfileJob::class);
    }

    /**
     * @return array{0: CustomerChannelIdentity, 1: Customer}
     */
    private function identityWithPageConnection(
        string $pageId,
        string $psid,
        string $customerName = 'Facebook User',
    ): array {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::FACEBOOK_PAGE,
            'external_id' => $pageId,
            'display_name' => 'Page',
            'status' => 'connected',
            'scopes' => ['pages_messaging'],
            'metadata' => [],
            'connected_by_user_id' => $user->id,
        ]);
        $connection->storeAccessToken('page-access-token-test');
        $connection->save();

        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => $customerName,
            'phone' => 'fb:'.$psid,
            'client_reference' => 'facebook:'.$psid,
        ]);

        Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_id' => 'facebook:'.$psid,
            'status' => 'open',
            'metadata' => [
                'facebook_user_id' => $psid,
                'facebook_page_id' => $pageId,
            ],
        ]);

        $identity = CustomerChannelIdentity::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => 'facebook_messenger',
            'external_user_id' => $psid,
        ]);

        return [$identity, $customer];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function fakeGraphProfile(string $psid, array $body): void
    {
        Http::fake([
            'graph.facebook.com/*' => function ($request) use ($psid, $body) {
                if (! str_contains($request->url(), '/'.$psid)) {
                    return Http::response(['error' => ['message' => 'not found']], 404);
                }

                return Http::response($body, 200);
            },
        ]);
    }
}
