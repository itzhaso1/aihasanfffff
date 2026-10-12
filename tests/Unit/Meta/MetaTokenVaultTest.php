<?php

namespace Tests\Unit\Meta;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMetaConnection;
use App\Services\Meta\MetaTokenVault;
use App\Support\Meta\MetaConnectionKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MetaTokenVaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_encrypt_decrypt_roundtrip(): void
    {
        $vault = app(MetaTokenVault::class);

        $cipher = $vault->encrypt('test-access-token-value');
        $this->assertNotNull($cipher);
        $this->assertNotSame('test-access-token-value', $cipher);
        $this->assertSame('test-access-token-value', $vault->decrypt($cipher));
    }

    public function test_empty_values_return_null(): void
    {
        $vault = app(MetaTokenVault::class);

        $this->assertNull($vault->encrypt(null));
        $this->assertNull($vault->encrypt(''));
        $this->assertNull($vault->encrypt('   '));
        $this->assertNull($vault->decrypt(null));
        $this->assertNull($vault->decrypt(''));
    }

    public function test_connection_model_does_not_expose_ciphertext_in_array(): void
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create(['owner_user_id' => $user->id]);

        $connection = WorkspaceMetaConnection::withoutGlobalScopes()->create([
            'workspace_id' => $workspace->id,
            'kind' => MetaConnectionKind::WHATSAPP_WABA,
            'external_id' => 'waba_123',
            'status' => 'connected',
        ]);
        $connection->storeAccessToken('secret-token-for-test');
        $connection->save();

        $array = $connection->fresh()->toArray();

        $this->assertArrayNotHasKey('access_token_ciphertext', $array);
        $this->assertSame('secret-token-for-test', $connection->accessToken());
    }
}
