<?php

use App\Enums\Finance\TaxPriceMode;
use App\Enums\Finance\TaxProfileType;
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
                if (! Schema::hasColumn('finance_invoices', 'tax_price_mode')) {
                    $table->string('tax_price_mode', 16)
                        ->default(TaxPriceMode::Exclusive->value)
                        ->after('tax_rate');
                }
                if (! Schema::hasColumn('finance_invoices', 'tax_breakdown')) {
                    $table->json('tax_breakdown')->nullable()->after('tax_price_mode');
                }
            });

            $this->backfillBreakdown('finance_invoices', 'finance_invoice_items', 'invoice_id');
        }

        if (Schema::hasTable('finance_credit_notes')) {
            Schema::table('finance_credit_notes', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_credit_notes', 'tax_price_mode')) {
                    $table->string('tax_price_mode', 16)
                        ->default(TaxPriceMode::Exclusive->value)
                        ->after('tax_rate');
                }
                if (! Schema::hasColumn('finance_credit_notes', 'tax_breakdown')) {
                    $table->json('tax_breakdown')->nullable()->after('tax_price_mode');
                }
            });

            $this->backfillBreakdown('finance_credit_notes', 'finance_credit_note_items', 'credit_note_id');
        }

        if (Schema::hasTable('finance_invoice_items')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_invoice_items', 'exemption_reason')) {
                    $table->string('exemption_reason', 255)->nullable()->after('tax_profile_type');
                }
                if (! Schema::hasColumn('finance_invoice_items', 'exemption_code')) {
                    $table->string('exemption_code', 32)->nullable()->after('exemption_reason');
                }
            });
        }

        if (Schema::hasTable('finance_credit_note_items')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                if (! Schema::hasColumn('finance_credit_note_items', 'exemption_reason')) {
                    $table->string('exemption_reason', 255)->nullable()->after('tax_profile_type');
                }
                if (! Schema::hasColumn('finance_credit_note_items', 'exemption_code')) {
                    $table->string('exemption_code', 32)->nullable()->after('exemption_reason');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if (Schema::hasColumn('finance_invoices', 'tax_breakdown')) {
                    $table->dropColumn('tax_breakdown');
                }
                if (Schema::hasColumn('finance_invoices', 'tax_price_mode')) {
                    $table->dropColumn('tax_price_mode');
                }
            });
        }

        if (Schema::hasTable('finance_credit_notes')) {
            Schema::table('finance_credit_notes', function (Blueprint $table): void {
                if (Schema::hasColumn('finance_credit_notes', 'tax_breakdown')) {
                    $table->dropColumn('tax_breakdown');
                }
                if (Schema::hasColumn('finance_credit_notes', 'tax_price_mode')) {
                    $table->dropColumn('tax_price_mode');
                }
            });
        }

        if (Schema::hasTable('finance_invoice_items')) {
            Schema::table('finance_invoice_items', function (Blueprint $table): void {
                if (Schema::hasColumn('finance_invoice_items', 'exemption_code')) {
                    $table->dropColumn('exemption_code');
                }
                if (Schema::hasColumn('finance_invoice_items', 'exemption_reason')) {
                    $table->dropColumn('exemption_reason');
                }
            });
        }

        if (Schema::hasTable('finance_credit_note_items')) {
            Schema::table('finance_credit_note_items', function (Blueprint $table): void {
                if (Schema::hasColumn('finance_credit_note_items', 'exemption_code')) {
                    $table->dropColumn('exemption_code');
                }
                if (Schema::hasColumn('finance_credit_note_items', 'exemption_reason')) {
                    $table->dropColumn('exemption_reason');
                }
            });
        }
    }

    /**
     * Rebuild category totals from persisted line amounts. Do not recalculate VAT
     * from current tax settings, and do not invent exemption codes.
     */
    private function backfillBreakdown(string $documentTable, string $itemTable, string $foreignKey): void
    {
        if (! Schema::hasTable($itemTable) || ! Schema::hasColumn($documentTable, 'tax_breakdown')) {
            return;
        }

        $hasLineType = Schema::hasColumn($itemTable, 'tax_profile_type');

        DB::table($documentTable)
            ->orderBy('id')
            ->chunkById(200, function ($documents) use ($documentTable, $itemTable, $foreignKey, $hasLineType): void {
                foreach ($documents as $document) {
                    $items = DB::table($itemTable)->where($foreignKey, $document->id)->get();
                    $groups = [];
                    foreach ($items as $item) {
                        $type = $hasLineType
                            ? ((string) ($item->tax_profile_type ?: TaxProfileType::Standard->value))
                            : (string) ($document->tax_profile_type ?? TaxProfileType::Standard->value);
                        $rate = number_format((float) $item->tax_rate, 2, '.', '');
                        $key = $type.'|'.$rate;
                        if (! isset($groups[$key])) {
                            $groups[$key] = [
                                'tax_profile_type' => $type,
                                'tax_rate' => (float) $rate,
                                'taxable_amount' => 0.0,
                                'tax_amount' => 0.0,
                                'line_count' => 0,
                            ];
                        }
                        $groups[$key]['taxable_amount'] += (float) $item->taxable_amount;
                        $groups[$key]['tax_amount'] += (float) $item->tax_amount;
                        $groups[$key]['line_count']++;
                    }

                    foreach ($groups as &$group) {
                        $group['taxable_amount'] = round($group['taxable_amount'], 2);
                        $group['tax_amount'] = round($group['tax_amount'], 2);
                    }
                    unset($group);

                    DB::table($documentTable)
                        ->where('id', $document->id)
                        ->update([
                            'tax_breakdown' => json_encode(array_values($groups), JSON_UNESCAPED_UNICODE),
                        ]);
                }
            });
    }
};
