<?php

declare(strict_types=1);

namespace App\AI\Guard;

final readonly class GuardLimits
{
    /** @var list<string> */
    public array $failureMarkers;

    /**
     * @param  list<string>  $failureMarkers
     */
    public function __construct(
        public int $maxOutputCharacters = 4000,
        array $failureMarkers = [
            'تعذر إنشاء الرد الآن',
            'تعذر إنشاء رد مناسب',
            'provider failed',
            'No allowed AI provider',
        ],
    ) {
        if ($maxOutputCharacters < 1) {
            throw new \InvalidArgumentException('Guard output limit must be at least 1.');
        }
        $this->failureMarkers = array_values(array_filter(
            $failureMarkers,
            static fn (mixed $marker): bool => is_string($marker) && $marker !== '',
        ));
    }
}
