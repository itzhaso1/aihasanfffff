<?php

namespace App\Services\Finance;

use App\Models\Finance\FinanceCreditNote;
use App\Models\Finance\FinanceInvoice;
use App\Models\Finance\FinanceSetting;
use App\Services\Finance\Concerns\RendersFinancePdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Response;
use RuntimeException;

class PdfCreditNoteService
{
    use RendersFinancePdf;

    public function download(FinanceCreditNote $note): Response|Responsable
    {
        $prefix = $note->isCredit() ? 'credit-note' : 'debit-note';
        $fileName = $prefix.'-'.$note->note_number.'.pdf';

        return $this->buildPdf($note)->download($fileName);
    }

    public function renderBinary(FinanceCreditNote $note): string
    {
        return $this->buildPdf($note)->output();
    }

    /**
     * @return PDF
     */
    private function buildPdf(FinanceCreditNote $note)
    {
        $note->loadMissing(['items', 'invoice.customer', 'customer']);

        if (! class_exists(\Barryvdh\DomPDF\Facade\Pdf::class)) {
            throw new RuntimeException('PDF generation is unavailable. Please install barryvdh/laravel-dompdf.');
        }

        $invoice = $note->invoice;
        $snapshotsAuthoritative = $invoice instanceof FinanceInvoice && $invoice->snapshotsAreAuthoritative();
        $setting = $snapshotsAuthoritative
            ? null
            : FinanceSetting::forWorkspaceId((int) $note->workspace_id);
        $companySnapshot = is_array($invoice?->company_snapshot) ? $invoice->company_snapshot : [];
        $recipientSnapshot = is_array($invoice?->recipient_snapshot) ? $invoice->recipient_snapshot : [];
        $pdfSnapshot = is_array($invoice?->pdf_snapshot) ? $invoice->pdf_snapshot : [];
        $logoPath = $companySnapshot['logo_path'] ?? ($snapshotsAuthoritative ? null : $setting?->logo_path);

        $html = view('workspace.finance.credit-notes.pdf', [
            'note' => $note,
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
