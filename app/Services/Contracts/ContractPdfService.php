<?php

namespace App\Services\Contracts;

use App\Models\Contract\Contract;
use App\Models\Finance\FinanceSetting;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ContractPdfService
{
    public function download(Contract $contract): Response|Responsable
    {
        $fileName = 'contract-'.$contract->contract_number.'.pdf';

        /** @var \Barryvdh\DomPDF\PDF $pdf */
        $pdf = $this->buildPdf($contract);

        return $pdf->download($fileName);
    }

    public function renderBinary(Contract $contract): string
    {
        /** @var \Barryvdh\DomPDF\PDF $pdf */
        $pdf = $this->buildPdf($contract);

        return $pdf->output();
    }

    /**
     * @return \Barryvdh\DomPDF\PDF
     */
    private function buildPdf(Contract $contract)
    {
        $contract->loadMissing(['customer', 'items']);

        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('PDF generation is unavailable. Please install barryvdh/laravel-dompdf.');
        }

        $setting = FinanceSetting::query()->first();
        $companySnapshot = is_array($contract->company_snapshot) ? $contract->company_snapshot : [];
        $customerSnapshot = is_array($contract->customer_snapshot) ? $contract->customer_snapshot : [];
        $pdfSnapshot = is_array($contract->pdf_snapshot) ? $contract->pdf_snapshot : [];
        $logoPath = $companySnapshot['logo_path'] ?? $setting?->logo_path;

        $html = view('workspace.contracts.pdf', [
            'contract' => $contract,
            'setting' => $setting,
            'companySnapshot' => $companySnapshot,
            'customerSnapshot' => $customerSnapshot,
            'pdfSnapshot' => $pdfSnapshot,
            'logoDataUri' => $this->resolveLogoDataUri(is_string($logoPath) ? $logoPath : null),
        ])->render();

        $html = $this->shapeArabicForDompdf($html);

        return \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)->setPaper('a4');
    }

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
        if (! class_exists(\ArPHP\I18N\Arabic::class)) {
            return $html;
        }

        try {
            /** @var object $arabic */
            $arabic = new \ArPHP\I18N\Arabic();
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
