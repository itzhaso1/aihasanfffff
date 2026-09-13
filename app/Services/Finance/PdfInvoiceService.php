<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use ArPHP\I18N\Arabic;
use Barryvdh\DomPDF\PDF;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class PdfInvoiceService
{
    public function download(FinanceInvoice $invoice): Response|Responsable
    {
        $fileName = 'invoice-'.$invoice->invoice_number.'.pdf';

        return $this->buildPdf($invoice)->download($fileName);
    }

    public function renderBinary(FinanceInvoice $invoice): string
    {
        return $this->buildPdf($invoice)->output();
    }

    /**
     * @return PDF
     */
    private function buildPdf(FinanceInvoice $invoice)
    {
        $invoice->loadMissing(['customer', 'supplier', 'items.product']);

        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('PDF generation is unavailable. Please install barryvdh/laravel-dompdf.');
        }

        $snapshotsAuthoritative = $invoice->snapshotsAreAuthoritative();
        $setting = $snapshotsAuthoritative
            ? null
            : FinanceSetting::forWorkspaceId((int) $invoice->workspace_id);
        $companySnapshot = is_array($invoice->company_snapshot) ? $invoice->company_snapshot : [];
        $recipientSnapshot = is_array($invoice->recipient_snapshot) ? $invoice->recipient_snapshot : [];
        $pdfSnapshot = is_array($invoice->pdf_snapshot) ? $invoice->pdf_snapshot : [];
        $logoPath = $companySnapshot['logo_path'] ?? ($snapshotsAuthoritative ? null : $setting?->logo_path);

        $html = view('workspace.finance.invoices.pdf', [
            'invoice' => $invoice,
            'setting' => $setting,
            'companySnapshot' => $companySnapshot,
            'recipientSnapshot' => $recipientSnapshot,
            'pdfSnapshot' => $pdfSnapshot,
            'snapshotsAuthoritative' => $snapshotsAuthoritative,
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
