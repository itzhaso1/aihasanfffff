<?php

declare(strict_types=1);

namespace App\AI\Guard\Checks;

use App\AI\Core\Data\GuardInput;
use App\AI\Core\Data\ModelFinishReason;
use App\AI\Guard\Contracts\GuardCheck;
use App\AI\Guard\GuardFinding;
use App\AI\Guard\GuardLimits;
use App\Services\AI\AiReplyTextNormalizer;

final class StyleCheck implements GuardCheck
{
    public const NAME = 'style';

    public function __construct(
        private readonly AiReplyTextNormalizer $normalizer = new AiReplyTextNormalizer,
        private readonly GuardLimits $limits = new GuardLimits,
    ) {}

    public function name(): string
    {
        return self::NAME;
    }

    public function inspect(GuardInput $input, string $text): GuardFinding
    {
        $truncated = $input->finishReason === ModelFinishReason::Length;
        $normalized = $this->normalizer->normalize($text, $truncated);
        $limited = $this->normalizer->limit($normalized, $this->limits->maxOutputCharacters);

        if (trim($limited) === '') {
            return GuardFinding::rejected(self::NAME, 'empty_output');
        }
        if ($limited !== $text) {
            $reason = $truncated || mb_strlen($normalized) > $this->limits->maxOutputCharacters
                ? 'output_trimmed'
                : 'style_normalized';

            return GuardFinding::repaired(self::NAME, $limited, $reason);
        }

        return GuardFinding::pass(self::NAME, $limited);
    }
}
