<?php

declare(strict_types=1);

namespace App\AI\Core;

use App\AI\Core\Decision\AiReference;
use App\AI\Core\Decision\SafeData;

/**
 * Immutable input to one runtime run. Carries identifiers only, never Eloquent models,
 * provider settings or credentials.
 */
final readonly class AiRequest
{
    /** @var list<AiReference> */
    public array $references;

    /** @var array<string, mixed> */
    public array $attributes;

    /**
     * @param  ?int  $employeeId  Null lets the employee resolver pick the default employee.
     * @param  ?string  $channel  Opaque channel key for future employee assignment; never interpreted here.
     * @param  list<AiReference>  $references  Entities the caller already knows the message refers to.
     * @param  array<string, mixed>  $attributes  Safe, non-sensitive caller metadata.
     */
    public function __construct(
        public int $workspaceId,
        public ?int $conversationId,
        public ?int $employeeId,
        public AiInboundMessage $message,
        public AiRunMode $runMode,
        public ?string $channel = null,
        array $references = [],
        array $attributes = [],
    ) {
        if ($workspaceId <= 0) {
            throw InvalidAiRequest::because('workspace id must be positive');
        }
        if ($conversationId !== null && $conversationId <= 0) {
            throw InvalidAiRequest::because('conversation id must be positive');
        }
        if ($conversationId === null && $runMode->requiresConversation()) {
            throw InvalidAiRequest::because('a live run requires a conversation');
        }
        if ($employeeId !== null && $employeeId <= 0) {
            throw InvalidAiRequest::because('employee id must be positive');
        }
        foreach ($references as $reference) {
            if (! $reference instanceof AiReference) {
                throw InvalidAiRequest::because('references must be AiReference instances');
            }
        }

        $this->references = array_values($references);
        $this->attributes = SafeData::sanitize($attributes);
    }

    public function withEmployeeId(int $employeeId): self
    {
        return new self(
            workspaceId: $this->workspaceId,
            conversationId: $this->conversationId,
            employeeId: $employeeId,
            message: $this->message,
            runMode: $this->runMode,
            channel: $this->channel,
            references: $this->references,
            attributes: $this->attributes,
        );
    }
}
