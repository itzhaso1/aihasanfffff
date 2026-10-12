<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('e_invoice_certificates')) {
            return;
        }

        Schema::table('e_invoice_certificates', function (Blueprint $table): void {
            if (! Schema::hasColumn('e_invoice_certificates', 'environment')) {
                $table->string('environment', 16)->default('sandbox')->after('egs_unit_id');
            }
            if (! Schema::hasColumn('e_invoice_certificates', 'kind')) {
                $table->string('kind', 16)->default('compliance')->after('environment');
            }
            if (! Schema::hasColumn('e_invoice_certificates', 'lifecycle_status')) {
                $table->string('lifecycle_status', 32)->default('active')->after('status');
            }
            if (! Schema::hasColumn('e_invoice_certificates', 'egs_credential_id')) {
                $table->foreignId('egs_credential_id')
                    ->nullable()
                    ->after('lifecycle_status')
                    ->constrained('egs_credentials')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('e_invoice_certificates', 'superseded_by_id')) {
                $table->foreignId('superseded_by_id')
                    ->nullable()
                    ->after('egs_credential_id')
                    ->constrained('e_invoice_certificates')
                    ->nullOnDelete();
            }
        });

        if (Schema::hasColumn('e_invoice_certificates', 'lifecycle_status')) {
            DB::table('e_invoice_certificates')->whereNull('lifecycle_status')->update([
                'lifecycle_status' => 'active',
            ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('e_invoice_certificates')) {
            return;
        }

        Schema::table('e_invoice_certificates', function (Blueprint $table): void {
            foreach (['superseded_by_id', 'egs_credential_id', 'lifecycle_status', 'kind', 'environment'] as $column) {
                if (Schema::hasColumn('e_invoice_certificates', $column)) {
                    if (in_array($column, ['superseded_by_id', 'egs_credential_id'], true)) {
                        $table->dropConstrainedForeignId($column);
                    } else {
                        $table->dropColumn($column);
                    }
                }
            }
        });
    }
};
