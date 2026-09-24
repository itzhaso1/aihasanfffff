<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('e_invoice_documents')) {
            return;
        }

        Schema::table('e_invoice_documents', function (Blueprint $table): void {
            if (! Schema::hasColumn('e_invoice_documents', 'xml_well_formed')) {
                $table->boolean('xml_well_formed')->nullable()->after('compliance_status');
            }
            if (! Schema::hasColumn('e_invoice_documents', 'xsd_validation_status')) {
                $table->string('xsd_validation_status', 32)->nullable()->after('xml_well_formed');
            }
            if (! Schema::hasColumn('e_invoice_documents', 'schematron_validation_status')) {
                $table->string('schematron_validation_status', 32)->nullable()->after('xsd_validation_status');
            }
            if (! Schema::hasColumn('e_invoice_documents', 'validated_at')) {
                $table->timestamp('validated_at')->nullable()->after('schematron_validation_status');
            }
            if (! Schema::hasColumn('e_invoice_documents', 'validation_data_source')) {
                $table->string('validation_data_source', 32)->nullable()->after('validated_at');
            }
            if (! Schema::hasColumn('e_invoice_documents', 'validation_findings')) {
                $table->json('validation_findings')->nullable()->after('validation_data_source');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('e_invoice_documents')) {
            return;
        }

        Schema::table('e_invoice_documents', function (Blueprint $table): void {
            foreach ([
                'validation_findings',
                'validation_data_source',
                'validated_at',
                'schematron_validation_status',
                'xsd_validation_status',
                'xml_well_formed',
            ] as $column) {
                if (Schema::hasColumn('e_invoice_documents', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
