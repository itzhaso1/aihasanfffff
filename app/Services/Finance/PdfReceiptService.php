<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceReceipt;
use App\Models\Finance\FinanceSetting;
use App\Services\Finance\Concerns\RendersFinancePdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use RuntimeException;

class PdfReceiptService
{
    use RendersFinancePdf;

    public function download(FinanceReceipt $receipt): Response|Responsable
    {
        $fileName = 'receipt-'.$receipt->receipt_number.'.pdf';

        return $this->buildPdf($receipt)->download($fileName);
    }

    public function renderBinary(FinanceReceipt $receipt): string
    {
        return $this->buildPdf($receipt)->output();
    }

    /**
     * @return PDF
     */
    private function buildPdf(FinanceReceipt $receipt)
    {
        $receipt->loadMissing(['payment', 'invoice.customer', 'customer']);

        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('PDF generation is unavailable. Please install barryvdh/laravel-dompdf.');
        }

        $invoice = $receipt->invoice;
        if (! $invoice instanceof FinanceInvoice) {
            throw new RuntimeException('Receipt invoice is missing.');
        }

        $snapshotsAuthoritative = $invoice->snapshotsAreAuthoritative();
        $setting = $snapshotsAuthoritative
            ? null
            : FinanceSetting::forWorkspaceId((int) $receipt->workspace_id);
        $companySnapshot = is_array($invoice->company_snapshot) ? $invoice->company_snapshot : [];
        $recipientSnapshot = is_array($invoice->recipient_snapshot) ? $invoice->recipient_snapshot : [];
        $pdfSnapshot = is_array($invoice->pdf_snapshot) ? $invoice->pdf_snapshot : [];
        $logoPath = $companySnapshot['logo_path'] ?? ($snapshotsAuthoritative ? null : $setting?->logo_path);

        $html = view('workspace.finance.receipts.pdf', [
            'receipt' => $receipt,
            'payment' => $receipt->payment,
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
}
