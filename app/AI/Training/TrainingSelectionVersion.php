<?php

declare(strict_types=1);

namespace App\AI\Training;

/**
 * Deterministic version of one selected training context: selection order, ids,
 * declared versions and content hashes. No timestamps, no randomness.
 */
final class TrainingSelectionVersion
{
    private const SCHEMA = 'hasim-training-selection-v1';

    /**
     * @param  list<TrainingDocument>  $documents  In selection order.
     */
    public static function of(array $documents): string
    {
        $lines = [self::SCHEMA];
        foreach ($documents as $document) {
            $lines[] = $document->id.'|'.$document->version.'|'.$document->contentHash;
        }

        return hash('sha256', implode("\n", $lines));
    }
}
