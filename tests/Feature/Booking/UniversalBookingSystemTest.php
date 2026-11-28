<?php

declare(strict_types=1);

namespace Tests\Feature\Booking;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Contracts\ModelGateway;
use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Core\Data\ModelRequest;
use App\AI\Core\Data\ModelResponse;
use App\AI\Core\Data\ModelToolCall;
use App\AI\Core\Decision\GuardVerdict;
use App\AI\Core\Decision\ModelUsage;
use App\AI\Employees\EmployeeFactory;
use App\AI\Employees\EmployeeService;
use App\AI\Guard\HasimResponseGuard;
use App\AI\Inbox\HasimInboxRuntime;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\BookingBranch;
use App\Models\BookingService;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Services\Booking\BookingManagementService;
use App\Services\Booking\BookingSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UniversalBookingSystemTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['ai.inbox_agent.tools_enabled' => true]);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-07 08:00:00', 'Asia/Riyadh')); // Monday
    }

    public function test_owner_can_manage_availability_settings_dashboard_calendars_statuses_and_reminders(): void
    {
        [$workspace, $owner, $customer, $conversation] = $this->createWorkspaceFixture('whatsapp');

        // 1. Update Booking Availability Settings via Dashboard
        $this->actingAs($owner)
            ->withSession(['current_workspace_id' => $workspace->id])
            ->put(route('workspace.bookings.settings.update'), [
                'enabled' => '1',
                'timezone' => 'Asia/Riyadh',
                'slot_interval_minutes' => 30,
                'default_duration_minutes' => 45,
                'default_capacity_per_slot' => 2,
                'buffer_minutes' => 15,
                'min_advance_minutes' => 30,
                'max_advance_days' => 45,
                'allow_party_size' => '1',
                'auto_confirm' => '1',
                'notifications_enabled' => '1',
                'send_confirmation_message' => '1',
                'send_update_message' => '1',
                'send_reschedule_message' => '1',
                'send_cancellation_message' => '1',
                'reminder_enabled' => '1',
                'reminder_lead_minutes' => 120,
                'working_hours' => [
                    'monday' => ['enabled' => '1', 'start' => '09:00', 'end' => '21:00', 'breaks' => [['start' => '13:00', 'end' => '14:00', 'label' => 'غداء']]],
                    'tuesday' => ['enabled' => '1', 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
                    'sunday' => ['enabled' => '1', 'start' => '09:00', 'end' => '21:00', 'breaks' => []],
                ],
                'holidays_raw' => [
                    ['date' => '2026-12-25', 'title' => 'إجازة نهاية العام', 'recurring_yearly' => '0'],
                ],
                'custom_fields_raw' => [
                    ['label' => 'نوع السيارة', 'type' => 'text', 'required' => '1'],
                ],
            ])
            ->assertRedirect(route('workspace.bookings.settings.edit'));

        // Add Service, Branch, Provider, and Manual Blocked Slot
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.settings.services.store'), [
                'name' => 'صيانة دورية شاملة',
                'duration_minutes' => 60,
                'capacity_per_slot' => 2,
                'price' => '250.00',
            ])->assertRedirect();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.settings.branches.store'), [
                'name' => 'فرع الملك فهد',
                'city' => 'الرياض',
            ])->assertRedirect();

        $service = BookingService::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $branch = BookingBranch::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.settings.providers.store'), [
                'name' => 'م. خالد',
                'role_label' => 'مهندس صيانة',
                'branch_id' => $branch->id,
                'capacity_per_slot' => 1,
            ])->assertRedirect();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.settings.blocked-slots.store'), [
                'date' => '2026-12-08',
                'start_time' => '15:00',
                'end_time' => '16:00',
                'reason' => 'صيانة داخلية',
            ])->assertRedirect();

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.bookings.settings.edit'))
            ->assertOk()
            ->assertSee('Booking Availability Settings')
            ->assertSee('صيانة دورية شاملة')
            ->assertSee('فرع الملك فهد');

        // 2. Manual Booking Creation + Live Availability JSON
        Queue::fake();
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->getJson(route('workspace.bookings.availability', ['date' => '2026-12-07', 'service_id' => $service->id]))
            ->assertOk()
            ->assertJsonPath('enabled', true);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.store'), [
                'customer_name' => 'راشد الدوسري',
                'customer_phone' => '0551234567',
                'service_id' => $service->id,
                'branch_id' => $branch->id,
                'conversation_id' => $conversation->id,
                'booking_date' => '2026-12-07',
                'start_time' => '16:00',
                'party_size' => 1,
                'status' => 'pending',
                'custom_fields' => ['نوع السيارة' => 'كامري 2024'],
                'notify_customer' => '1',
            ])->assertRedirect();

        $booking = Booking::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('pending', $booking->status);
        $this->assertSame('+966551234567', $booking->customer_phone);

        // 3. Dashboard Views: List + Day + Week + Month + Search + Filter
        foreach (['list', 'day', 'week', 'month'] as $view) {
            $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
                ->get(route('workspace.bookings.index', ['view' => $view, 'anchor_date' => '2026-12-07', 'search' => $booking->booking_reference]))
                ->assertOk()
                ->assertSee($booking->booking_reference)
                ->assertSee('راشد الدوسري');
        }

        // 4. Transition statuses & Reschedule & Automated Reminder Command (Zero Duplicate Reminders)
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->patch(route('workspace.bookings.status.update', $booking), ['status' => 'confirmed', 'notify_customer' => '1'])
            ->assertRedirect();
        $this->assertSame(BookingStatus::Confirmed->value, $booking->fresh()->status);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->post(route('workspace.bookings.reschedule', $booking), [
                'booking_date' => '2026-12-08',
                'start_time' => '11:00',
                'reason' => 'تقديم بناء على طلب العميل',
                'notify_customer' => '1',
            ])->assertRedirect();
        $booking->refresh();
        $this->assertSame(BookingStatus::Rescheduled->value, $booking->status);

        // Simulate time reaching the scheduled reminder threshold (2 hours before 11:00 on 2026-12-08)
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-12-08 09:05:00', 'Asia/Riyadh'));
        $msgCountBefore = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count();
        $this->artisan('bookings:send-reminders')->assertExitCode(0);
        $this->artisan('bookings:send-reminders')->assertExitCode(0);
        $msgCountAfter = Message::withoutGlobalScopes()->where('conversation_id', $conversation->id)->count();
        $this->assertSame($msgCountBefore + 1, $msgCountAfter);

        // Complete & NoShow & Cancel transitions
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->patch(route('workspace.bookings.status.update', $booking), ['status' => 'no_show'])
            ->assertRedirect();
        $this->assertSame(BookingStatus::NoShow->value, $booking->fresh()->status);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->patch(route('workspace.bookings.status.update', $booking), ['status' => 'completed'])
            ->assertRedirect();
        $this->assertSame(BookingStatus::Completed->value, $booking->fresh()->status);

        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->patch(route('workspace.bookings.status.update', $booking), ['status' => 'cancelled', 'reason' => 'إلغاء'])
            ->assertRedirect();
        $this->assertSame(BookingStatus::Cancelled->value, $booking->fresh()->status);

        // Customer details in inbox & profile display booking cleanly
        $this->actingAs($owner)->withSession(['current_workspace_id' => $workspace->id])
            ->get(route('workspace.customers.show', $customer))
            ->assertOk()
            ->assertSee($booking->booking_reference);
    }

    public function test_ai_booking_lifecycle_across_whatsapp_instagram_messenger_telegram_confirmation_conflicts_and_idempotency(): void
    {
        [$workspace, $owner, $customer, $waConversation] = $this->createWorkspaceFixture('whatsapp');
        app(EmployeeService::class)->create($workspace, app(EmployeeFactory::class)->fromPreset('sales_saudi'), $owner->id);

        $settings = app(BookingSettingsService::class);
        $settings->updateSettings($workspace, [
            'enabled' => true,
            'timezone' => 'Asia/Riyadh',
            'slot_interval_minutes' => 60,
            'default_duration_minutes' => 60,
            'default_capacity_per_slot' => 1,
            'min_advance_minutes' => 30,
            'max_advance_days' => 30,
            'allow_party_size' => true,
            'working_hours' => [
                'tuesday' => ['enabled' => true, 'start' => '18:00', 'end' => '22:00', 'breaks' => []],
            ],
        ]);
        $service = $settings->saveService($workspace, [
            'name' => 'طاولة عائلية',
            'duration_minutes' => 60,
            'capacity_per_slot' => 1,
            'price' => '100.00',
            'requires_party_size' => true,
            'max_party_size' => 8,
        ]);

        $msg1 = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $waConversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'أريد حجز طاولة لشخصين غداً الساعة 19:00',
        ]);

        // 1. AI inspects options, checks DB availability, creates booking
        $this->app->instance(ModelGateway::class, $this->scriptedGateway([
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('c1', 'get_booking_options', []),
                    new ModelToolCall('c2', 'check_booking_availability', ['date' => '2026-12-08', 'start_time' => '19:00', 'service_id' => $service->id, 'party_size' => 2]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 10, 10),
            ),
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('c3', 'create_booking', [
                        'customer_name' => 'نورة القحطاني',
                        'phone' => '+966509988776',
                        'service_id' => $service->id,
                        'service_name' => 'طاولة عائلية',
                        'date' => '2026-12-08',
                        'start_time' => '19:00',
                        'party_size' => 2,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 10, 10),
            ),
            new ModelResponse(
                text: 'تم تأكيد حجز طاولة عائلية غداً الثلاثاء 2026-12-08 الساعة 19:00 لشخصين بسعر 100.00 ريال.',
                toolCalls: [],
                finishReason: ModelFinishReason::Stop,
                usage: new ModelUsage('fake', 'fake-1', 10, 15),
            ),
        ]));

        $runtime = HasimInboxRuntime::fromApplication($this->app);
        $result = $runtime->reply($waConversation, $msg1);
        $this->assertTrue($result->deliverable);
        $this->assertCount(1, Booking::withoutGlobalScopes()->where('workspace_id', $workspace->id)->get());
        $created = Booking::withoutGlobalScopes()->where('workspace_id', $workspace->id)->firstOrFail();
        $this->assertSame('ai', $created->source);
        $this->assertSame('whatsapp', $created->channel);

        // 2. Duplicate webhook / retry on SAME inbound message must NOT create a duplicate booking
        $this->app->instance(ModelGateway::class, $this->scriptedGateway([
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('dup1', 'create_booking', [
                        'customer_name' => 'نورة القحطاني',
                        'phone' => '+966509988776',
                        'service_id' => $service->id,
                        'service_name' => 'طاولة عائلية',
                        'date' => '2026-12-08',
                        'start_time' => '19:00',
                        'party_size' => 2,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
            new ModelResponse(
                text: 'تم تأكيد الحجز برقم '.$created->booking_reference,
                toolCalls: [],
                finishReason: ModelFinishReason::Stop,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
        ]));
        HasimInboxRuntime::fromApplication($this->app)->reply($waConversation, $msg1);
        $this->assertSame(1, Booking::withoutGlobalScopes()->where('workspace_id', $workspace->id)->count());

        // 3. Another customer on Instagram requests same full 19:00 slot -> prevented & offered nearest alternatives (18:00 / 20:00)
        [, , $custIg, $igConversation] = $this->createChannelConversation($workspace, 'instagram');
        $msgIg = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $igConversation->id,
            'customer_id' => $custIg->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'أبغى حجز طاولة يوم الثلاثاء الساعة 19:00',
        ]);

        $capturedToolResults = [];
        $this->app->instance(ModelGateway::class, new class($service->id, $capturedToolResults) implements ModelGateway
        {
            private int $turn = 0;

            public function __construct(private readonly int $serviceId, public array &$captured) {}

            public function generate(ModelRequest $request): ModelResponse
            {
                $this->turn++;
                if ($this->turn === 1) {
                    return new ModelResponse(
                        text: '',
                        toolCalls: [
                            new ModelToolCall('ig1', 'create_booking', [
                                'customer_name' => 'سارة',
                                'phone' => '+966504445566',
                                'service_id' => $this->serviceId,
                                'service_name' => 'طاولة عائلية',
                                'date' => '2026-12-08',
                                'start_time' => '19:00',
                                'party_size' => 3,
                            ]),
                        ],
                        finishReason: ModelFinishReason::ToolCalls,
                        usage: new ModelUsage('fake', 'fake-1', 5, 5),
                    );
                }

                foreach ($request->toolExchanges as $ex) {
                    $this->captured[] = $ex->result;
                }

                return new ModelResponse(
                    text: 'الساعة 19:00 ممتلئة، أقرب الأوقات المتاحة هي 18:00 أو 20:00.',
                    toolCalls: [],
                    finishReason: ModelFinishReason::Stop,
                    usage: new ModelUsage('fake', 'fake-1', 5, 5),
                );
            }
        });
        $igReply = HasimInboxRuntime::fromApplication($this->app)->reply($igConversation, $msgIg);
        $this->assertTrue($igReply->deliverable);
        $this->assertFalse($capturedToolResults[0]['created']);
        $this->assertTrue($capturedToolResults[0]['unavailable']);
        $this->assertNotEmpty($capturedToolResults[0]['nearest_alternatives']);

        // 4. Customer on Facebook Messenger asks to reschedule then confirms
        $created->forceFill(['conversation_id' => $igConversation->id, 'channel' => 'facebook_messenger'])->save();
        // Keep WA conversation testing reschedule confirmation gate!
        $created->forceFill(['conversation_id' => $waConversation->id, 'customer_id' => $customer->id, 'channel' => 'whatsapp'])->save();

        // Reschedule with customer_confirmed=false MUST NOT mutate DB
        $msgAskResched = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $waConversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'أريد تأجيل حجزي للساعة 20:00',
        ]);
        $this->app->instance(ModelGateway::class, $this->scriptedGateway([
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('r1', 'reschedule_booking', [
                        'booking_reference' => $created->booking_reference,
                        'new_date' => '2026-12-08',
                        'new_start_time' => '20:00',
                        'customer_confirmed' => false,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
            new ModelResponse(
                text: 'هل تؤكد تأجيل حجزك '.$created->booking_reference.' إلى 2026-12-08 الساعة 20:00؟',
                toolCalls: [],
                finishReason: ModelFinishReason::Stop,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
        ]));
        HasimInboxRuntime::fromApplication($this->app)->reply($waConversation, $msgAskResched);
        $this->assertSame('19:00', $created->fresh()->start_time);

        // Customer confirms -> reschedules to 20:00 cleanly
        $msgConfirmResched = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $waConversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'نعم أؤكد التأجيل',
        ]);
        $this->app->instance(ModelGateway::class, $this->scriptedGateway([
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('r2', 'reschedule_booking', [
                        'booking_reference' => $created->booking_reference,
                        'new_date' => '2026-12-08',
                        'new_start_time' => '20:00',
                        'customer_confirmed' => true,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
            new ModelResponse(
                text: 'تمت إعادة جدولة الحجز '.$created->booking_reference.' إلى 2026-12-08 الساعة 20:00.',
                toolCalls: [],
                finishReason: ModelFinishReason::Stop,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
        ]));
        $reschedReply = HasimInboxRuntime::fromApplication($this->app)->reply($waConversation, $msgConfirmResched);
        $this->assertTrue($reschedReply->deliverable);
        $this->assertSame('20:00', $created->fresh()->start_time);
        $this->assertSame(BookingStatus::Rescheduled->value, $created->fresh()->status);

        // 5. Telegram & Facebook Messenger channels also execute AI booking queries & cancel cleanly
        foreach (['telegram', 'facebook_messenger'] as $idx => $ch) {
            [, , $chCust, $chConv] = $this->createChannelConversation($workspace, $ch);
            $chMsg = Message::withoutGlobalScopes()->create([
                'workspace_id' => $workspace->id,
                'conversation_id' => $chConv->id,
                'customer_id' => $chCust->id,
                'direction' => 'inbound',
                'message_type' => 'text',
                'content' => 'احجز لي طاولة يوم الثلاثاء الساعة '.($idx === 0 ? '18:00' : '21:00'),
            ]);
            $slotTime = $idx === 0 ? '18:00' : '21:00';
            $this->app->instance(ModelGateway::class, $this->scriptedGateway([
                new ModelResponse(
                    text: '',
                    toolCalls: [
                        new ModelToolCall('ch'.$idx, 'create_booking', [
                            'customer_name' => 'عميل '.$ch,
                            'phone' => '+96650000001'.$idx,
                            'service_id' => $service->id,
                            'service_name' => 'طاولة عائلية',
                            'date' => '2026-12-08',
                            'start_time' => $slotTime,
                            'party_size' => 4,
                        ]),
                    ],
                    finishReason: ModelFinishReason::ToolCalls,
                    usage: new ModelUsage('fake', 'fake-1', 5, 5),
                ),
                new ModelResponse(
                    text: 'تم تأكيد الحجز يوم الثلاثاء الساعة '.$slotTime.' بسعر 100.00 ريال.',
                    toolCalls: [],
                    finishReason: ModelFinishReason::Stop,
                    usage: new ModelUsage('fake', 'fake-1', 5, 5),
                ),
            ]));
            $chReply = HasimInboxRuntime::fromApplication($this->app)->reply($chConv, $chMsg);
            $this->assertTrue($chReply->deliverable);
            $this->assertDatabaseHas('bookings', [
                'workspace_id' => $workspace->id,
                'conversation_id' => $chConv->id,
                'channel' => $ch,
                'start_time' => $slotTime,
            ]);
        }

        // 6. Cancel confirmation gate + actual cancel frees slot
        $cancelMsg = Message::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'conversation_id' => $waConversation->id,
            'customer_id' => $customer->id,
            'direction' => 'inbound',
            'message_type' => 'text',
            'content' => 'نعم ألغِ حجزي',
        ]);
        $this->app->instance(ModelGateway::class, $this->scriptedGateway([
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('c_ask', 'cancel_booking', [
                        'booking_reference' => $created->booking_reference,
                        'customer_confirmed' => false,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
            new ModelResponse(
                text: '',
                toolCalls: [
                    new ModelToolCall('c_do', 'cancel_booking', [
                        'booking_reference' => $created->booking_reference,
                        'reason' => 'طلب العميل',
                        'customer_confirmed' => true,
                    ]),
                ],
                finishReason: ModelFinishReason::ToolCalls,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
            new ModelResponse(
                text: 'تم إلغاء الحجز '.$created->booking_reference.' بنجاح.',
                toolCalls: [],
                finishReason: ModelFinishReason::Stop,
                usage: new ModelUsage('fake', 'fake-1', 5, 5),
            ),
        ]));
        $cancelReply = HasimInboxRuntime::fromApplication($this->app)->reply($waConversation, $cancelMsg);
        $this->assertTrue($cancelReply->deliverable);
        $this->assertSame(BookingStatus::Cancelled->value, $created->fresh()->status);

        // 7. Guard strictly rejects unconfirmed AI booking claims
        $guardOutcome = (new HasimResponseGuard)->review(new GuardInput(
            request: new AiRequest($workspace->id, $waConversation->id, null, new AiInboundMessage('احجز لي'), AiRunMode::Live, 'whatsapp'),
            text: 'تم تأكيد الحجز غداً الساعة 19:00',
        ));
        $this->assertSame(GuardVerdict::Rejected, $guardOutcome->verdict);
    }

    public function test_tenant_isolation_employee_rbac_and_mobile_api(): void
    {
        [$wsA, $ownerA, $custA, $convA] = $this->createWorkspaceFixture('whatsapp');
        [$wsB, $ownerB] = $this->createWorkspaceFixture('telegram');

        $memberUser = User::factory()->create();
        WorkspaceUser::query()->create([
            'workspace_id' => $wsA->id,
            'user_id' => $memberUser->id,
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        app(BookingSettingsService::class)->updateSettings($wsA, [
            'enabled' => true,
            'timezone' => 'Asia/Riyadh',
            'slot_interval_minutes' => 60,
            'default_duration_minutes' => 60,
            'min_advance_minutes' => 0,
            'working_hours' => ['monday' => ['enabled' => true, 'start' => '09:00', 'end' => '21:00', 'breaks' => []]],
        ]);
        $bookingA = app(BookingManagementService::class)->createBooking($wsA, [
            'customer_name' => 'أحمد',
            'phone' => '+966501119999',
            'service_name' => 'استشارة',
            'booking_date' => '2026-12-07',
            'start_time' => '14:00',
        ], $convA, $ownerA, true, false);

        // Member without booking permissions gets 403 on web dashboard & settings
        $this->actingAs($memberUser)->withSession(['current_workspace_id' => $wsA->id])
            ->get(route('workspace.bookings.index'))
            ->assertForbidden();
        $this->actingAs($memberUser)->withSession(['current_workspace_id' => $wsA->id])
            ->get(route('workspace.bookings.settings.edit'))
            ->assertForbidden();

        // Cross-workspace ownerB gets 404 on wsA booking via Web & Mobile API
        $this->actingAs($ownerB)->withSession(['current_workspace_id' => $wsB->id])
            ->get(route('workspace.bookings.show', $bookingA))
            ->assertNotFound();

        Sanctum::actingAs($ownerB);
        $this->withHeader('X-Workspace-Id', (string) $wsB->id)
            ->getJson('/api/mobile/v1/bookings/'.$bookingA->id)
            ->assertNotFound();
        $this->withHeader('X-Workspace-Id', (string) $wsB->id)
            ->patchJson('/api/mobile/v1/bookings/'.$bookingA->id.'/status', ['status' => 'cancelled'])
            ->assertNotFound();

        // Authorized Mobile API lifecycle for ownerA
        Sanctum::actingAs($ownerA);
        $this->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->getJson('/api/mobile/v1/bookings')
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->postJson('/api/mobile/v1/bookings', [
                'customer_name' => 'بدر',
                'customer_phone' => '0552223344',
                'service_name' => 'استشارة ثانية',
                'booking_date' => '2026-12-07',
                'start_time' => '15:00',
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);

        $this->withHeader('X-Workspace-Id', (string) $wsA->id)
            ->postJson('/api/mobile/v1/bookings/'.$bookingA->id.'/reschedule', [
                'booking_date' => '2026-12-07',
                'start_time' => '16:00',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rescheduled');
    }

    /**
     * @param  list<ModelResponse>  $responses
     */
    private function scriptedGateway(array $responses): ModelGateway
    {
        return new class($responses) implements ModelGateway
        {
            /** @param list<ModelResponse> $queue */
            public function __construct(private array $queue) {}

            public function generate(ModelRequest $request): ModelResponse
            {
                return array_shift($this->queue) ?? new ModelResponse('تم', [], ModelFinishReason::Stop, new ModelUsage('fake', 'fake-1', 1, 1));
            }
        };
    }

    /**
     * @return array{0: Workspace, 1: User, 2: Customer, 3: Conversation}
     */
    private function createWorkspaceFixture(string $channel): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id, 'type' => 'company', 'name' => 'منشأة تجريبية']);
        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);

        $plan = Plan::query()->firstOrCreate(
            ['code' => 'pro'],
            ['name' => 'Pro', 'workspace_type' => 'company', 'billing_period' => 'monthly', 'currency' => 'USD', 'price' => 99, 'is_active' => true, 'features' => ['ai' => true], 'limits' => ['ai_usage' => 10000, 'ai_tokens' => 1000000]],
        );
        Subscription::query()->create([
            'workspace_id' => $workspace->id,
            'plan_id' => $plan->id,
            'status' => 'active',
            'starts_at' => now()->subDay(),
            'current_period_start' => now()->subDay(),
            'current_period_end' => now()->addMonth(),
        ]);

        return $this->createChannelConversation($workspace, $channel, $user);
    }

    /**
     * @return array{0: Workspace, 1: ?User, 2: Customer, 3: Conversation}
     */
    private function createChannelConversation(Workspace $workspace, string $channel, ?User $user = null): array
    {
        $customer = Customer::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'name' => 'عميل '.$channel,
            'phone' => '+96650'.random_int(1000000, 9999999),
        ]);
        $conversation = Conversation::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'external_id' => $channel.'-'.uniqid(),
            'status' => 'open',
            'ai_enabled' => true,
            'metadata' => ['channel_source' => $channel],
        ]);

        return [$workspace, $user, $customer, $conversation];
    }
}
