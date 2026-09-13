<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PAYMENT_TOLERANCE = 0.009;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->enum('invoice_status', ['draft', 'issued', 'cancelled'])
                ->default('draft')
                ->after('status');
            $table->enum('payment_status', ['unpaid', 'partial', 'paid', 'overdue'])
                ->default('unpaid')
                ->after('invoice_status');
            $table->timestamp('issued_at')->nullable()->after('payment_status');
            $table->json('company_snapshot')->nullable()->after('notes');
            $table->json('recipient_snapshot')->nullable()->after('company_snapshot');
            $table->json('pdf_snapshot')->nullable()->after('recipient_snapshot');

            $table->index(['workspace_id', 'invoice_status'], 'fin_inv_ws_invoice_status_idx');
            $table->index(['workspace_id', 'payment_status'], 'fin_inv_ws_payment_status_idx');
        });

        Schema::table('finance_settings', function (Blueprint $table): void {
            $table->string('website')->nullable()->after('email');
            $table->string('invoice_primary_color', 20)->nullable()->after('website');
            $table->text('invoice_footer_text')->nullable()->after('invoice_primary_color');
        });

        DB::table('finance_invoices')
            ->orderBy('id')
            ->chunkById(200, function ($invoices): void {
                foreach ($invoices as $invoice) {
                    $legacyStatus = (string) ($invoice->status ?? 'draft');
                    $invoiceStatus = $this->mapLegacyToInvoiceStatus($legacyStatus);
                    $paymentStatus = $this->derivePaymentStatus(
                        total: (float) ($invoice->total ?? 0),
                        amountPaid: (float) ($invoice->amount_paid ?? 0),
                        dueDate: $invoice->due_date,
                        invoiceStatus: $invoiceStatus,
                    );

                    $issuedAt = $invoiceStatus === 'issued'
                        ? ($invoice->created_at ?: now())
                        : null;

                    DB::table('finance_invoices')
                        ->where('id', $invoice->id)
                        ->update([
                            'invoice_status' => $invoiceStatus,
                            'payment_status' => $paymentStatus,
                            'issued_at' => $issuedAt,
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_invoices', function (Blueprint $table): void {
            $table->dropIndex('fin_inv_ws_invoice_status_idx');
            $table->dropIndex('fin_inv_ws_payment_status_idx');
            $table->dropColumn([
                'invoice_status',
                'payment_status',
                'issued_at',
                'company_snapshot',
                'recipient_snapshot',
                'pdf_snapshot',
            ]);
        });

        Schema::table('finance_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'website',
                'invoice_primary_color',
                'invoice_footer_text',
            ]);
        });
    }

    private function mapLegacyToInvoiceStatus(string $legacyStatus): string
    {
        if ($legacyStatus === 'cancelled') {
            return 'cancelled';
        }

        if ($legacyStatus === 'draft') {
            return 'draft';
        }

        return 'issued';
    }

    private function derivePaymentStatus(float $total, float $amountPaid, mixed $dueDate, string $invoiceStatus): string
    {
        $paid = round(max(0, $amountPaid), 2);
        $due = round(max(0, $total - $paid), 2);

        if ($due <= self::PAYMENT_TOLERANCE) {
            return 'paid';
        }

        if ($paid > self::PAYMENT_TOLERANCE) {
            return 'partial';
        }

        if (
            $invoiceStatus === 'issued'
            && is_string($dueDate)
            && trim($dueDate) !== ''
            && $this->isPastDate($dueDate)
        ) {
            return 'overdue';
        }

        return 'unpaid';
    }

    private function isPastDate(string $value): bool
    {
        try {
            return Carbon::parse($value)->isPast();
        } catch (\Throwable) {
            return false;
        }
    }
};
