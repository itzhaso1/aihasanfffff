<?php

namespace Tests\Unit\Chat;

use App\Models\Workspace;
use App\Services\Chat\ChatCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ChatCatalogCategoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_categories_cannot_cross_workspaces_or_form_cycles(): void
    {
        $workspace = Workspace::factory()->create(['type' => 'company']);
        $other = Workspace::factory()->create(['type' => 'company']);

        $this->inWorkspace($workspace, function () use ($workspace, $other): void {
            $catalog = app(ChatCatalogService::class);
            $root = $catalog->createCategory($workspace, ['name' => 'Root']);
            $child = $catalog->createCategory($workspace, [
                'name' => 'Child',
                'parent_id' => $root->id,
            ]);

            try {
                $catalog->updateCategory($root, ['parent_id' => $child->id]);
                $this->fail('Expected recursive category relationship to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }

            $foreign = $this->inWorkspace($other, fn () => $catalog->createCategory($other, ['name' => 'Foreign']));
            try {
                $catalog->updateCategory($root, ['parent_id' => $foreign->id]);
                $this->fail('Expected cross-workspace category parent to be rejected.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        });
    }
}
