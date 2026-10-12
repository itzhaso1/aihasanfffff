<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Models;

use App\AI\Core\Data\ToolSpec;
use App\AI\Models\Mapping\ToolSchemaMapper;
use PHPUnit\Framework\TestCase;

class ToolSchemaMapperTest extends TestCase
{
    public function test_gemini_and_openai_schemas_share_the_same_tool_contract(): void
    {
        $tool = new ToolSpec('search_products', 'Search catalog products.', [
            'query' => ['type' => 'string', 'required' => true, 'description' => 'Customer query.'],
            'limit' => ['type' => 'integer', 'required' => false],
        ]);
        $mapper = new ToolSchemaMapper;

        $gemini = $mapper->geminiDeclarations([$tool]);
        $openai = $mapper->openaiTools([$tool]);

        $this->assertSame('search_products', $gemini[0]['name']);
        $this->assertSame('OBJECT', $gemini[0]['parameters']['type']);
        $this->assertSame('INTEGER', $gemini[0]['parameters']['properties']['limit']['type']);
        $this->assertSame(['query'], $gemini[0]['parameters']['required']);

        $this->assertSame('function', $openai[0]['type']);
        $this->assertSame('search_products', $openai[0]['function']['name']);
        $this->assertSame('object', $openai[0]['function']['parameters']['type']);
        $this->assertSame('integer', $openai[0]['function']['parameters']['properties']['limit']['type']);
        $this->assertSame(['query'], $openai[0]['function']['parameters']['required']);
    }
}
