<?php

namespace App\Services\Finance\Concerns;

use ArPHP\I18N\Arabic;
use Illuminate\Support\Facades\Storage;

trait RendersFinancePdf
{
    private function resolveLogoDataUri(?string $logoPath): ?string
    {
        if (! $logoPath || ! Storage::disk('public')->exists($logoPath)) {
            return null;
        }

        $absolutePath = Storage::disk('public')->path($logoPath);
        if (! is_file($absolutePath)) {
            return null;
        }

        $binary = @file_get_contents($absolutePath);
        if ($binary === false) {
            return null;
        }

        $mimeType = function_exists('mime_content_type')
            ? mime_content_type($absolutePath)
            : 'image/png';
        $mimeType = is_string($mimeType) && $mimeType !== '' ? $mimeType : 'image/png';

        return 'data:'.$mimeType.';base64,'.base64_encode($binary);
    }

    private function shapeArabicForDompdf(string $html): string
    {
        if (! class_exists(Arabic::class)) {
            return $html;
        }

        try {
            /** @var object $arabic */
            $arabic = new Arabic;
            if (! method_exists($arabic, 'arIdentify') || ! method_exists($arabic, 'utf8Glyphs')) {
                return $html;
            }

            /** @var array<int,int> $positions */
            $positions = $arabic->arIdentify($html);
            if (! is_array($positions) || $positions === []) {
                return $html;
            }

            for ($i = count($positions) - 1; $i >= 1; $i -= 2) {
                $start = $positions[$i - 1];
                $end = $positions[$i];
                $segment = substr($html, $start, $end - $start);
                if (! is_string($segment) || $segment === '') {
                    continue;
                }

                /** @var string $glyphSegment */
                $glyphSegment = $arabic->utf8Glyphs($segment);
                $html = substr_replace($html, $glyphSegment, $start, $end - $start);
            }
        } catch (\Throwable) {
            return $html;
        }

        return $html;
    }
}
