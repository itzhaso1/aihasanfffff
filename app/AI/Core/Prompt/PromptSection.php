<?php

declare(strict_types=1);

namespace App\AI\Core\Prompt;

/**
 * One piece of prompt content with its layer and provenance. Content comes from the
 * layer that owns it (constitution files, training files, employee records, context);
 * the core never writes content of its own.
 */
final readonly class PromptSection
{
    public const USER_MESSAGE_KEY = 'user.message';

    public function __construct(
        public PromptLayer $layer,
        public string $key,
        public string $content,
        public ?string $source = null,
        public ?string $version = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9_.\-]*$/', $key) !== 1) {
            throw PromptCompositionException::invalidKey();
        }
    }

    public static function userMessage(string $text): self
    {
        return new self(PromptLayer::UserMessage, self::USER_MESSAGE_KEY, $text);
    }

    public function isEmpty(): bool
    {
        return trim($this->content) === '';
    }

    /**
     * @return array{layer: string, key: string, content: string, source: ?string, version: ?string}
     */
    public function toArray(): array
    {
        return [
            'layer' => $this->layer->key(),
            'key' => $this->key,
            'content' => $this->content,
            'source' => $this->source,
            'version' => $this->version,
        ];
    }
}
