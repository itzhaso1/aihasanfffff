<?php

namespace Tests\Unit\Chat;

use App\Enums\ChatCatalog\ChatCatalogDigitalUnitStatus;
use App\Enums\ChatCatalog\ChatCatalogDigitalUnitType;
use App\Http\Resources\Mobile\ChatCatalogDigitalUnitResource;
use App\Models\AuditLog;
use App\Models\ChatCatalogDigitalInventoryUnit;
use App\Models\ChatCatalogItem;
use App\Models\Workspace;
use App\Services\Chat\ChatCatalogDigitalInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

class ChatCatalogDigitalInventoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_secrets_are_encrypted_duplicate_safe_and_never_serialized(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $item = $this->legacyDigitalItem($workspace, 'ترخيص آمن', 'license');
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            $pool = $inventory->createPool($workspace, $item, 'تراخيص');
            $unit = $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::License, 'TOP-SECRET-LICENSE');

            $this->assertNotSame('TOP-SECRET-LICENSE', $unit->getRawOriginal('encrypted_secret'));
            $this->assertSame('TOP-SECRET-LICENSE', $unit->encrypted_secret);
            $this->assertArrayNotHasKey('encrypted_secret', $unit->toArray());
            $this->assertArrayNotHasKey('secret_fingerprint', $unit->toArray());

            try {
                $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::License, 'TOP-SECRET-LICENSE');
                $this->fail('Expected duplicate fingerprint rejection.');
            } catch (InvalidArgumentException) {
                $this->assertSame(1, ChatCatalogDigitalInventoryUnit::query()->count());
            }
        });
    }

    public function test_reservation_is_idempotent_and_state_transitions_are_validated(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $item = $this->legacyDigitalItem($workspace, 'كود لعبة');
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            $pool = $inventory->createPool($workspace, $item, 'أكواد');
            $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::Code, 'GAME-KEY-1');

            $first = $inventory->reserveUnit($pool, 'order-line-1');
            $same = $inventory->reserveUnit($pool, 'order-line-1');
            $this->assertSame($first->id, $same->id);
            $this->assertSame(ChatCatalogDigitalUnitStatus::Reserved, $same->status);

            $consumed = $inventory->consumeReserved($same, 'order-line-1');
            $this->assertSame(ChatCatalogDigitalUnitStatus::Consumed, $consumed->status);

            $this->expectException(InvalidArgumentException::class);
            $inventory->transition($consumed, ChatCatalogDigitalUnitStatus::Available);
        });
    }

    public function test_cross_workspace_pool_write_is_rejected_and_sensitive_audit_is_redacted(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $other = Workspace::factory()->create(['type' => 'company']);

        $item = $this->inWorkspace($workspace, fn () => $this->legacyDigitalItem($workspace, 'كود'));

        $this->inWorkspace($workspace, function () use ($workspace, $other, $item): void {
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            try {
                $inventory->createPool($other, $item, 'مرفوض');
                $this->fail('Expected workspace mismatch.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }

            $pool = $inventory->createPool($workspace, $item, 'المسموح');
            $unit = $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::Code, 'AUDIT-SECRET');
            $inventory->reserveUnit($pool, 'audit-order');

            $audit = AuditLog::query()
                ->where('entity_id', $unit->id)
                ->orderByDesc('id')
                ->firstOrFail();
            $encoded = json_encode($audit->toArray(), JSON_THROW_ON_ERROR);
            $this->assertStringNotContainsString('AUDIT-SECRET', $encoded);
            $this->assertStringNotContainsString('encrypted_secret', $encoded);
        });
    }

    public function test_pool_reservation_timeout_is_persisted_and_used_by_default(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $item = $this->legacyDigitalItem($workspace, 'مخزون مؤقت');
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            $pool = $inventory->createPool($workspace, $item, 'مؤقت', reservationTimeoutSeconds: 60);
            $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::Code, 'TIMEOUT-KEY');

            $reserved = $inventory->reserveUnit($pool, 'timeout-reservation');

            $this->assertSame(60, $pool->reservation_timeout_seconds);
            $this->assertNotNull($reserved->reserved_until);
            $this->assertTrue($reserved->reserved_until->between(now()->addSeconds(59), now()->addSeconds(61)));
        });
    }

    public function test_private_file_assets_stay_workspace_scoped_and_paths_are_not_serialized(): void
    {
        Storage::fake('local');
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $item = $this->legacyDigitalItem($workspace, 'ملف خاص', 'file');
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            $pool = $inventory->createPool($workspace, $item, 'ملفات');
            $path = 'workspaces/'.$workspace->id.'/chat-catalog/digital/guide.pdf';
            Storage::disk('local')->put($path, 'private file');

            $unit = $inventory->importPrivateFileUnit($pool, [
                'disk' => 'local',
                'path' => $path,
                'filename' => 'guide.pdf',
            ]);

            $payload = (new ChatCatalogDigitalUnitResource($unit))->toArray(new Request);
            $this->assertSame('guide.pdf', $payload['metadata']['filename']);
            $this->assertArrayNotHasKey('path', $payload['metadata']);
            $this->assertArrayNotHasKey('disk', $payload['metadata']);

            $this->expectException(InvalidArgumentException::class);
            $inventory->importPrivateFileUnit($pool, [
                'disk' => 'local',
                'path' => 'unrelated/private.pdf',
            ]);
        });
    }

    public function test_available_unit_and_idle_pool_can_be_deleted_but_reserved_cannot(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace): void {
            $item = $this->legacyDigitalItem($workspace, 'كود للحذف');
            $inventory = app(ChatCatalogDigitalInventoryService::class);
            $pool = $inventory->createPool($workspace, $item, 'حوض الحذف');
            $reserved = $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::Code, 'KEEP-RESERVED');
            $available = $inventory->importSecretUnit($pool, ChatCatalogDigitalUnitType::Code, 'DELETE-ME');
            $inventory->reserveUnit($pool, 'order-reserved');

            $inventory->deleteUnit($available);
            $this->assertNull(ChatCatalogDigitalInventoryUnit::query()->find($available->id));

            try {
                $inventory->deleteUnit($reserved->fresh());
                $this->fail('Expected reserved unit deletion to fail.');
            } catch (InvalidArgumentException) {
                $this->assertNotNull(ChatCatalogDigitalInventoryUnit::query()->find($reserved->id));
            }

            try {
                $inventory->deletePool($pool);
                $this->fail('Expected reserved pool deletion to fail.');
            } catch (InvalidArgumentException) {
                $this->assertNotNull($pool->fresh());
            }

            $inventory->releaseReservation($reserved->fresh(), 'order-reserved');
            $inventory->deletePool($pool->fresh());
            $this->assertNull($pool->fresh());
        });
    }

    private function legacyDigitalItem(Workspace $workspace, string $name, string $subtype = 'code'): ChatCatalogItem
    {
        return ChatCatalogItem::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $name,
            'slug' => str()->slug($name).'-'.str()->random(6),
            'price' => '10.00',
            'currency' => 'SAR',
            'classification' => 'digital',
            'digital_subtype' => $subtype,
            'inventory_policy' => 'digital_pool',
            'is_active' => true,
            'is_visible' => true,
        ]);
    }
}
