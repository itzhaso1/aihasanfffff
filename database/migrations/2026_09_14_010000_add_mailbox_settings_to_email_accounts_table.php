<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            if (! Schema::hasColumn('email_accounts', 'smtp_encryption')) {
                $table->string('smtp_encryption', 16)->nullable()->after('smtp_port');
            }
            if (! Schema::hasColumn('email_accounts', 'imap_encryption')) {
                $table->string('imap_encryption', 16)->nullable()->after('imap_port');
            }
            if (! Schema::hasColumn('email_accounts', 'smtp_username')) {
                $table->string('smtp_username')->nullable()->after('smtp_encryption');
            }
            if (! Schema::hasColumn('email_accounts', 'imap_username')) {
                $table->string('imap_username')->nullable()->after('imap_encryption');
            }
            if (! Schema::hasColumn('email_accounts', 'smtp_status')) {
                $table->string('smtp_status', 32)->nullable()->after('smtp_username');
            }
            if (! Schema::hasColumn('email_accounts', 'smtp_last_tested_at')) {
                $table->timestamp('smtp_last_tested_at')->nullable()->after('smtp_status');
            }
        });

        if (Schema::hasColumn('email_accounts', 'smtp_encryption')) {
            DB::table('email_accounts')->orderBy('id')->each(function (object $row): void {
                $smtpEncryption = match ((int) $row->smtp_port) {
                    465 => 'ssl',
                    587 => 'tls',
                    default => $row->smtp_encryption,
                };
                $imapEncryption = match ((int) $row->imap_port) {
                    993 => 'ssl',
                    143 => 'tls',
                    default => $row->imap_encryption,
                };

                DB::table('email_accounts')->where('id', $row->id)->update([
                    'smtp_encryption' => $smtpEncryption ?: null,
                    'imap_encryption' => $imapEncryption ?: null,
                ]);
            });
        }
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table): void {
            foreach ([
                'smtp_last_tested_at',
                'smtp_status',
                'imap_username',
                'smtp_username',
                'imap_encryption',
                'smtp_encryption',
            ] as $column) {
                if (Schema::hasColumn('email_accounts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
