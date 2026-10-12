<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Core;

use App\AI\Core\AiInboundMessage;
use App\AI\Core\AiRequest;
use App\AI\Core\AiRunMode;
use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\SafeData;
use App\AI\Core\InvalidAiRequest;
use Error;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AiRequestTest extends TestCase
{
    public function test_valid_live_request_keeps_typed_identifiers(): void
    {
        $request = new AiRequest(
            workspaceId: 5,
            conversationId: 9,
            employeeId: 3,
            message: new AiInboundMessage('كم', 77),
            runMode: AiRunMode::Live,
            channel: 'whatsapp',
            references: [new AiReference('catalog_item', '42')],
            attributes: ['source' => 'inbound_job'],
        );

        $this->assertSame(5, $request->workspaceId);
        $this->assertSame(9, $request->conversationId);
        $this->assertSame(3, $request->employeeId);
        $this->assertSame('كم', $request->message->text);
        $this->assertSame(77, $request->message->messageId);
        $this->assertSame(AiRunMode::Live, $request->runMode);
        $this->assertSame('42', $request->references[0]->id);
        $this->assertSame(['source' => 'inbound_job'], $request->attributes);
    }

    public function test_preview_and_evaluation_may_run_without_a_conversation(): void
    {
        foreach ([AiRunMode::Preview, AiRunMode::Evaluation] as $mode) {
            $request = new AiRequest(5, null, 3, new AiInboundMessage('مرحبا'), $mode);

            $this->assertNull($request->conversationId);
            $this->assertSame($mode, $request->runMode);
        }
    }

    public function test_employee_id_is_optional_and_can_be_resolved_later(): void
    {
        $request = new AiRequest(5, 9, null, new AiInboundMessage('مرحبا'), AiRunMode::Live);

        $resolved = $request->withEmployeeId(12);

        $this->assertNull($request->employeeId);
        $this->assertSame(12, $resolved->employeeId);
        $this->assertSame($request->message, $resolved->message);
    }

    /**
     * @return array<string, array{0: callable(): AiRequest}>
     */
    public static function invalidRequests(): array
    {
        return [
            'workspace id zero' => [fn () => new AiRequest(0, 9, null, new AiInboundMessage('x'), AiRunMode::Live)],
            'negative conversation' => [fn () => new AiRequest(5, -1, null, new AiInboundMessage('x'), AiRunMode::Live)],
            'live without conversation' => [fn () => new AiRequest(5, null, null, new AiInboundMessage('x'), AiRunMode::Live)],
            'employee id zero' => [fn () => new AiRequest(5, 9, 0, new AiInboundMessage('x'), AiRunMode::Live)],
            'blank message' => [fn () => new AiRequest(5, 9, null, new AiInboundMessage('   '), AiRunMode::Live)],
            'message id zero' => [fn () => new AiRequest(5, 9, null, new AiInboundMessage('x', 0), AiRunMode::Live)],
            'untyped reference' => [fn () => new AiRequest(5, 9, null, new AiInboundMessage('x'), AiRunMode::Live, references: ['42'])],
        ];
    }

    #[DataProvider('invalidRequests')]
    public function test_required_fields_are_enforced(callable $factory): void
    {
        $this->expectException(InvalidAiRequest::class);

        $factory();
    }

    public function test_request_is_immutable(): void
    {
        $request = new AiRequest(5, 9, null, new AiInboundMessage('x'), AiRunMode::Live);

        $this->expectException(Error::class);

        /** @phpstan-ignore-next-line */
        $request->workspaceId = 6;
    }

    public function test_attributes_never_keep_credentials(): void
    {
        $request = new AiRequest(5, 9, null, new AiInboundMessage('x'), AiRunMode::Live, attributes: [
            'api_key' => 'AIzaSyD-very-secret-value-000000',
            'note' => 'Bearer abc.def',
        ]);

        $this->assertSame(SafeData::REDACTED, $request->attributes['api_key']);
        $this->assertStringNotContainsString('abc.def', (string) $request->attributes['note']);
    }

    public function test_run_mode_semantics(): void
    {
        $this->assertTrue(AiRunMode::Live->producesDeliverableReply());
        $this->assertFalse(AiRunMode::Preview->producesDeliverableReply());
        $this->assertFalse(AiRunMode::Evaluation->producesDeliverableReply());
        $this->assertFalse(AiRunMode::Live->isSandboxed());
        $this->assertTrue(AiRunMode::Preview->isSandboxed());
        $this->assertTrue(AiRunMode::Evaluation->isSandboxed());
        $this->assertSame(['live', 'preview', 'evaluation'], array_map(static fn (AiRunMode $m): string => $m->value, AiRunMode::cases()));
    }
}
