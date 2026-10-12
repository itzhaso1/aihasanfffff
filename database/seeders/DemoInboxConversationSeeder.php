<?php

namespace Database\Seeders;

use App\Enums\ConversationHandoffStatus;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\Message;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Tenancy\WorkspaceContext;
use Illuminate\Database\Seeder;

class DemoInboxConversationSeeder extends Seeder
{
    public const PREFERRED_WORKSPACE_NAME = 'حسن';

    public const WHATSAPP_EXTERNAL_ID = 'demo-inbox-whatsapp';

    public const INSTAGRAM_EXTERNAL_ID = 'demo-inbox-instagram';

    public const WEB_EXTERNAL_ID = 'demo-inbox-web';

    public function run(): void
    {
        $workspace = $this->resolveWorkspace();
        if (! $workspace instanceof Workspace) {
            $this->command?->warn('No active company workspace found for the demo inbox conversations.');

            return;
        }

        app(WorkspaceContext::class)->runFor($workspace, function (Workspace $workspace): void {
            $owner = User::query()->find($workspace->owner_user_id);

            foreach ($this->conversations() as $definition) {
                $this->seedConversation($workspace, $owner, $definition);
            }
        });

        $this->command?->info('Demo inbox conversations are ready in workspace: '.$workspace->name);
    }

    private function resolveWorkspace(): ?Workspace
    {
        $preferred = Workspace::query()
            ->where('type', 'company')
            ->where('status', 'active')
            ->where('name', self::PREFERRED_WORKSPACE_NAME)
            ->orderBy('id')
            ->first();

        if ($preferred instanceof Workspace) {
            return $preferred;
        }

        return Workspace::query()
            ->where('type', 'company')
            ->where('status', 'active')
            ->where('name', 'not like', 'شركة UAT%')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array{
     *     external_id: string,
     *     channel: string,
     *     channel_source: string,
     *     unread_count: int,
     *     customer: array{name: string, phone: string, whatsapp: ?string, email: ?string, notes: string},
     *     messages: list<array{external_id: string, direction: string, content: string, minutes_ago: int, ai_generated: bool}>
     * }  $definition
     */
    private function seedConversation(Workspace $workspace, ?User $owner, array $definition): void
    {
        $customerData = $definition['customer'];
        $customer = Customer::query()->updateOrCreate(
            ['phone' => $customerData['phone']],
            [
                'workspace_id' => $workspace->id,
                'name' => $customerData['name'],
                'party_type' => Customer::PARTY_TYPE_INDIVIDUAL,
                'whatsapp' => $customerData['whatsapp'],
                'email' => $customerData['email'],
                'country_code' => 'SA',
                'notes' => $customerData['notes'],
                'last_conversation_at' => now(),
                'metadata' => ['demo' => true],
            ],
        );

        $messages = $definition['messages'];
        $lastMessage = $messages[array_key_last($messages)];
        $lastMessageAt = now()->subMinutes($lastMessage['minutes_ago']);

        $conversation = Conversation::query()->updateOrCreate(
            ['external_id' => $definition['external_id']],
            [
                'workspace_id' => $workspace->id,
                'customer_id' => $customer->id,
                'channel' => $definition['channel'],
                'status' => 'open',
                'ai_enabled' => true,
                'handoff_status' => ConversationHandoffStatus::None,
                'last_message_at' => $lastMessageAt,
                'metadata' => [
                    'demo' => true,
                    'channel_source' => $definition['channel_source'],
                    'unread_count' => $definition['unread_count'],
                ],
            ],
        );

        foreach ($messages as $message) {
            $sentAt = now()->subMinutes($message['minutes_ago']);
            $outbound = $message['direction'] === 'outbound';

            Message::query()->updateOrCreate(
                ['external_message_id' => $message['external_id']],
                [
                    'workspace_id' => $workspace->id,
                    'conversation_id' => $conversation->id,
                    'customer_id' => $customer->id,
                    'user_id' => $outbound ? $owner?->id : null,
                    'direction' => $message['direction'],
                    'message_type' => 'text',
                    'content' => $message['content'],
                    'ai_generated' => $message['ai_generated'],
                    'metadata' => ['demo' => true],
                    'created_at' => $sentAt,
                    'updated_at' => $sentAt,
                ],
            );
        }
    }

    /**
     * @return list<array{
     *     external_id: string,
     *     channel: string,
     *     channel_source: string,
     *     unread_count: int,
     *     customer: array{name: string, phone: string, whatsapp: ?string, email: ?string, notes: string},
     *     messages: list<array{external_id: string, direction: string, content: string, minutes_ago: int, ai_generated: bool}>
     * }>
     */
    private function conversations(): array
    {
        return [
            [
                'external_id' => self::WHATSAPP_EXTERNAL_ID,
                'channel' => 'whatsapp',
                'channel_source' => 'whatsapp',
                'unread_count' => 2,
                'customer' => [
                    'name' => 'نورة العتيبي',
                    'phone' => '+966501112233',
                    'whatsapp' => '+966501112233',
                    'email' => 'noura.demo@example.test',
                    'notes' => 'محادثة وهمية لمعاينة صندوق الوارد.',
                ],
                'messages' => [
                    [
                        'external_id' => 'demo-inbox-wa-1',
                        'direction' => 'inbound',
                        'content' => 'السلام عليكم، عندكم عباية سوداء مقاس 52؟',
                        'minutes_ago' => 48,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-wa-2',
                        'direction' => 'outbound',
                        'content' => 'وعليكم السلام نورة، نعم متوفرة بالأسود والبني.',
                        'minutes_ago' => 46,
                        'ai_generated' => true,
                    ],
                    [
                        'external_id' => 'demo-inbox-wa-3',
                        'direction' => 'inbound',
                        'content' => 'كم السعر مع التوصيل للرياض؟',
                        'minutes_ago' => 42,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-wa-4',
                        'direction' => 'outbound',
                        'content' => 'السعر 280 ريال، والتوصيل للرياض 25 ريال ويوصل خلال يومين.',
                        'minutes_ago' => 40,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-wa-5',
                        'direction' => 'inbound',
                        'content' => 'تمام، أبي أطلبها اليوم. الدفع عند الاستلام متاح؟',
                        'minutes_ago' => 8,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-wa-6',
                        'direction' => 'inbound',
                        'content' => 'ووش وقت التوصيل تقريباً؟',
                        'minutes_ago' => 3,
                        'ai_generated' => false,
                    ],
                ],
            ],
            [
                'external_id' => self::INSTAGRAM_EXTERNAL_ID,
                'channel' => 'instagram',
                'channel_source' => 'instagram',
                'unread_count' => 1,
                'customer' => [
                    'name' => 'خالد المطيري',
                    'phone' => '+966554443322',
                    'whatsapp' => null,
                    'email' => 'khalid.demo@example.test',
                    'notes' => 'محادثة وهمية من إنستغرام.',
                ],
                'messages' => [
                    [
                        'external_id' => 'demo-inbox-ig-1',
                        'direction' => 'inbound',
                        'content' => 'شفت القصة، باقي عندكم العطر؟',
                        'minutes_ago' => 120,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-ig-2',
                        'direction' => 'outbound',
                        'content' => 'باقي تشكيلة العود فقط، تبي أحجز لك واحدة؟',
                        'minutes_ago' => 110,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-ig-3',
                        'direction' => 'inbound',
                        'content' => 'إي احجزها، أمرّ عليها العصر.',
                        'minutes_ago' => 25,
                        'ai_generated' => false,
                    ],
                ],
            ],
            [
                'external_id' => self::WEB_EXTERNAL_ID,
                'channel' => 'web',
                'channel_source' => 'web',
                'unread_count' => 0,
                'customer' => [
                    'name' => 'سارة الدوسري',
                    'phone' => '+966532221100',
                    'whatsapp' => null,
                    'email' => 'sara.demo@example.test',
                    'notes' => 'محادثة وهمية من الشات في الموقع.',
                ],
                'messages' => [
                    [
                        'external_id' => 'demo-inbox-web-1',
                        'direction' => 'inbound',
                        'content' => 'مرحبا، أبي عرض أسعار للجملة.',
                        'minutes_ago' => 180,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-web-2',
                        'direction' => 'outbound',
                        'content' => 'أهلاً سارة، نرسل لك العرض على الإيميل خلال ساعة.',
                        'minutes_ago' => 170,
                        'ai_generated' => false,
                    ],
                    [
                        'external_id' => 'demo-inbox-web-3',
                        'direction' => 'inbound',
                        'content' => 'تمام، شكراً لكم.',
                        'minutes_ago' => 165,
                        'ai_generated' => false,
                    ],
                ],
            ],
        ];
    }
}
