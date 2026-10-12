<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateBusinessAccounts = DB::table('whats_app_accounts')
            ->select('workspace_id', 'business_account_id', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->groupBy('workspace_id', 'business_account_id')
            ->having('total', '>', 1)
            ->get();

        if ($duplicateBusinessAccounts->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot scope WhatsApp business_account_id: duplicate rows exist within the same workspace. '
                .'Resolve duplicates before migrating.'
            );
        }

        $duplicatePhoneNumbers = DB::table('whats_app_phone_numbers')
            ->select('workspace_id', 'phone_number_id', DB::raw('COUNT(*) as total'))
            ->whereNull('deleted_at')
            ->groupBy('workspace_id', 'phone_number_id')
            ->having('total', '>', 1)
            ->get();

        if ($duplicatePhoneNumbers->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot scope WhatsApp phone_number_id: duplicate rows exist within the same workspace. '
                .'Resolve duplicates before migrating.'
            );
        }

        Schema::table('whats_app_accounts', function (Blueprint $table): void {
            $table->dropUnique(['business_account_id']);
            $table->unique(['workspace_id', 'business_account_id'], 'whatsapp_accounts_ws_business_unique');
        });

        Schema::table('whats_app_phone_numbers', function (Blueprint $table): void {
            $table->dropUnique(['phone_number_id']);
            $table->unique(['workspace_id', 'phone_number_id'], 'whatsapp_phones_ws_phone_id_unique');
        });
    }

    public function down(): void
    {
        $crossTenantBusiness = DB::table('whats_app_accounts')
            ->select('business_account_id', DB::raw('COUNT(DISTINCT workspace_id) as workspaces'))
            ->whereNull('deleted_at')
            ->groupBy('business_account_id')
            ->having('workspaces', '>', 1)
            ->get();

        if ($crossTenantBusiness->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore global WhatsApp business_account_id unique: the same Meta business account is connected to multiple workspaces.'
            );
        }

        $crossTenantPhones = DB::table('whats_app_phone_numbers')
            ->select('phone_number_id', DB::raw('COUNT(DISTINCT workspace_id) as workspaces'))
            ->whereNull('deleted_at')
            ->groupBy('phone_number_id')
            ->having('workspaces', '>', 1)
            ->get();

        if ($crossTenantPhones->isNotEmpty()) {
            throw new RuntimeException(
                'Cannot restore global WhatsApp phone_number_id unique: the same Meta phone number is connected to multiple workspaces.'
            );
        }

        Schema::table('whats_app_phone_numbers', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_phones_ws_phone_id_unique');
            $table->unique('phone_number_id');
        });

        Schema::table('whats_app_accounts', function (Blueprint $table): void {
            $table->dropUnique('whatsapp_accounts_ws_business_unique');
            $table->unique('business_account_id');
        });
    }
};
