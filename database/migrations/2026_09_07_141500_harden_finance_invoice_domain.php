<?php

use App\Enums\Finance\TaxDocumentSubtype;
use App\Enums\Finance\TaxProfileType;
use App\Enums\Finance\ZatcaRequirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_invoices', 'tax_document_subtype')) {
                    $table->string('tax_document_subtype', 32)
                        ->default(TaxDocumentSubtype::Standard->value)
                        ->after('type');
                }
                if (! Schema::hasColumn('finance_invoices', 'zatca_requirement')) {
                    $table->string('zatca_requirement', 32)
                        ->default(ZatcaRequirement::NotRequired->value)
                        ->after('tax_document_subtype');
                }
            });

            if (Schema::hasColumn('finance_invoices', 'zatca_requirement')) {
                DB::table('finance_invoices')
                    ->where('type', 'purchase')
                    ->update(['zatca_requirement' => ZatcaRequirement::NotRequired->value]);
            }

            if (Schema::hasColumn('finance_invoices', 'issued_at')) {
                $issuedAtQuery = DB::table('finance_invoices')->whereNull('issued_at');
                if (Schema::hasColumn('finance_invoices', 'invoice_status')) {
                    $issuedAtQuery->where(function ($query): void {
                        $query->whereIn('invoice_status', ['issued', 'cancelled'])
                            ->orWhere(function ($legacy): void {
                                $legacy->whereNull('invoice_status')
                                    ->whereNotIn('status', ['draft']);
                            });
                    });
                } else {
                    $issuedAtQuery->whereNotIn('status', ['draft']);
                }

                $issuedAtQuery->orderBy('id')
                    ->chunkById(200, function ($invoices): void {
                        foreach ($invoices as $invoice) {
                            DB::table('finance_invoices')
                                ->where('id', $invoice->id)
                                ->update([
                                    'issued_at' => $invoice->created_at ?: now(),
                                ]);
                        }
                    });
            }
        }

        if (Schema::hasTable('finance_invoice_items') && ! Schema::hasColumn('finance_invoice_items', 'tax_profile_type')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $table->string('tax_profile_type', 32)
                    ->default(TaxProfileType::Standard->value)
                    ->after('discount');
            });

            $this->backfillLineTaxProfile('finance_invoice_items', 'invoice_id');
        }

        if (Schema::hasTable('finance_credit_note_items') && ! Schema::hasColumn('finance_credit_note_items', 'tax_profile_type')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                $table->string('tax_profile_type', 32)
                    ->default(TaxProfileType::Standard->value)
                    ->after('discount');
            });

            $this->backfillCreditNoteLineTaxProfile();
        }

        if (Schema::hasTable('finance_settings') && ! Schema::hasColumn('finance_settings', 'allow_manual_invoice_numbers')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                $table->boolean('allow_manual_invoice_numbers')->default(false)->after('next_invoice_sequence');
            });

            // Preserve existing workspace behavior: they could already type a number in the form.
            DB::table('finance_settings')->update(['allow_manual_invoice_numbers' => true]);
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('finance_invoices', 'zatca_requirement')) {
                    $table->dropColumn('zatca_requirement');
                }
                if (Schema::hasColumn('finance_invoices', 'tax_document_subtype')) {
                    $table->dropColumn('tax_document_subtype');
                }
            });
        }

        if (Schema::hasTable('finance_invoice_items') && Schema::hasColumn('finance_invoice_items', 'tax_profile_type')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                $table->dropColumn('tax_profile_type');
            });
        }

        if (Schema::hasTable('finance_credit_note_items') && Schema::hasColumn('finance_credit_note_items', 'tax_profile_type')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                $table->dropColumn('tax_profile_type');
            });
        }

        if (Schema::hasTable('finance_settings') && Schema::hasColumn('finance_settings', 'allow_manual_invoice_numbers')) {
            Schema::table('finance_settings', function (Blueprint $table): void {
                $table->dropColumn('allow_manual_invoice_numbers');
            });
        }
    }

    private function backfillLineTaxProfile(string $itemTable, string $invoiceForeignKey): void
    {
        if (! Schema::hasTable('finance_invoices') || ! Schema::hasColumn('finance_invoices', 'tax_profile_type')) {
            return;
        }

        $valid = [
            TaxProfileType::Standard->value,
            TaxProfileType::ZeroRated->value,
            TaxProfileType::Exempt->value,
            TaxProfileType::OutOfScope->value,
        ];

        DB::table($itemTable)
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($itemTable, $invoiceForeignKey, $valid): void {
                $invoiceIds = $items->pluck($invoiceForeignKey)->unique()->filter()->all();
                $headers = DB::table('finance_invoices')
                    ->whereIn('id', $invoiceIds)
                    ->get(['id', 'tax_profile_type'])
                    ->keyBy('id');

                foreach ($items as $item) {
                    $headerType = (string) ($headers[$item->{$invoiceForeignKey}]->tax_profile_type ?? TaxProfileType::Standard->value);
                    $type = in_array($headerType, $valid, true) ? $headerType : TaxProfileType::Standard->value;
                    DB::table($itemTable)->where('id', $item->id)->update(['tax_profile_type' => $type]);
                }
            });
    }

    private function backfillCreditNoteLineTaxProfile(): void
    {
        if (! Schema::hasTable('finance_credit_notes') || ! Schema::hasColumn('finance_credit_notes', 'tax_profile_type')) {
            return;
        }

        $valid = [
            TaxProfileType::Standard->value,
            TaxProfileType::ZeroRated->value,
            TaxProfileType::Exempt->value,
            TaxProfileType::OutOfScope->value,
        ];

        DB::table('finance_credit_note_items')
            ->orderBy('id')
            ->chunkById(200, function ($items) use ($valid): void {
                $noteIds = $items->pluck('credit_note_id')->unique()->filter()->all();
                $headers = DB::table('finance_credit_notes')
                    ->whereIn('id', $noteIds)
                    ->get(['id', 'tax_profile_type'])
                    ->keyBy('id');

                foreach ($items as $item) {
                    $headerType = (string) ($headers[$item->credit_note_id]->tax_profile_type ?? TaxProfileType::Standard->value);
                    $type = in_array($headerType, $valid, true) ? $headerType : TaxProfileType::Standard->value;
                    DB::table('finance_credit_note_items')->where('id', $item->id)->update(['tax_profile_type' => $type]);
                }
            });
    }
};
