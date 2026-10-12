<?php

namespace Tests\Feature\Feature\Mobile;

use App\Models\ChatCatalogCategory;
use App\Models\ChatCatalogItem;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\FoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class MobileChatCatalogImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FoundationSeeder::class);
        Storage::fake('public');
    }

    public function test_php_before_84_does_not_see_patch_multipart_files(): void
    {
        if (PHP_VERSION_ID >= 80400) {
            $this->markTestSkipped('PHP 8.4 request_parse_body() can parse PATCH multipart.');
        }

        $server = $_SERVER;
        $post = $_POST;
        $files = $_FILES;
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=test';
        $_POST = [];
        $_FILES = [];

        try {
            $request = SymfonyRequest::createFromGlobals();
            $this->assertNull($request->files->get('image_file'));
        } finally {
            $_SERVER = $server;
            $_POST = $post;
            $_FILES = $files;
        }
    }

    public function test_post_image_create_and_replace_keep_public_urls_and_old_file(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog', [
                'name' => 'فستان',
                'price' => '25.00',
                'currency' => 'SAR',
                'image_file' => UploadedFile::fake()->image('dress.jpg', 40, 40),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'فستان');

        $itemId = (int) $created->json('data.id');
        $firstUrl = (string) $created->json('data.image_url');
        $this->assertNotSame('', $firstUrl);
        $firstPath = (string) ChatCatalogItem::withoutGlobalScopes()->findOrFail($itemId)->image_path;
        Storage::disk('public')->assertExists($firstPath);

        $replaced = $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog/'.$itemId, [
                'name' => 'فستان',
                'price' => '25.00',
                'currency' => 'SAR',
                'image_file' => UploadedFile::fake()->image('dress-new.png', 40, 40),
            ])
            ->assertOk();

        $secondPath = (string) ChatCatalogItem::withoutGlobalScopes()->findOrFail($itemId)->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertNotSame('', (string) $replaced->json('data.image_url'));
        Storage::disk('public')->assertExists($secondPath);
        Storage::disk('public')->assertExists($firstPath);

        $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog/'.$itemId, [
                'name' => 'فستان',
                'price' => '25.00',
                'image_file' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['image_file']]);

        Storage::disk('public')->assertExists($secondPath);
    }

    public function test_category_image_create_and_replace_return_image_url(): void
    {
        [$owner, $workspace] = $this->seedWorkspace();
        $manager = $this->memberWithPermissions($workspace, ['chat.catalog.view', 'chat.catalog.manage']);

        $created = $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog/categories', [
                'name' => 'ملابس',
                'image_file' => UploadedFile::fake()->image('cat.jpg', 20, 20),
            ])
            ->assertCreated();

        $categoryId = (int) $created->json('data.id');
        $firstPath = (string) ChatCatalogCategory::withoutGlobalScopes()->findOrFail($categoryId)->image_path;
        $this->assertNotSame('', (string) $created->json('data.image_url'));
        Storage::disk('public')->assertExists($firstPath);

        $replaced = $this->actingAsMobile($manager, $workspace)
            ->post('/api/mobile/v1/catalog/categories/'.$categoryId, [
                'name' => 'ملابس',
                'image_file' => UploadedFile::fake()->image('cat-new.png', 20, 20),
            ])
            ->assertOk();

        $secondPath = (string) ChatCatalogCategory::withoutGlobalScopes()->findOrFail($categoryId)->image_path;
        $this->assertNotSame($firstPath, $secondPath);
        $this->assertNotSame('', (string) $replaced->json('data.image_url'));
        Storage::disk('public')->assertExists($firstPath);
        Storage::disk('public')->assertExists($secondPath);
    }

    /**
     * @return array{0: User, 1: Workspace}
     */
    private function seedWorkspace(): array
    {
        $user = User::factory()->create();
        $workspace = Workspace::factory()->create([
            'owner_user_id' => $user->id,
            'type' => 'company',
            'name' => 'Catalog Images',
        ]);
        $workspace->users()->attach($user->id, [
            'membership_role' => 'owner',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $this->enableWorkspaceFeature($workspace, 'products', true);
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

        return [$user, $workspace];
    }

    /**
     * @param  list<string>  $permissions
     */
    private function memberWithPermissions(Workspace $workspace, array $permissions): User
    {
        $user = User::factory()->create();
        $workspace->users()->attach($user->id, [
            'membership_role' => 'member',
            'status' => 'active',
            'joined_at' => now(),
        ]);
        $registrar = app(PermissionRegistrar::class);
        $registrar->setPermissionsTeamId($workspace->id);
        $user->givePermissionTo($permissions);
        $registrar->setPermissionsTeamId(null);

        return $user;
    }

    private function actingAsMobile(User $user, Workspace $workspace)
    {
        $this->flushHeaders();
        $token = $user->createToken('catalog-image');
        $token->accessToken->forceFill(['workspace_id' => $workspace->id])->save();

        return $this->actingAs($user, 'sanctum')
            ->withToken($token->plainTextToken)
            ->withHeader('X-Workspace-Id', (string) $workspace->id);
    }
}
