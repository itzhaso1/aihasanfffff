<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('website_domains', function (Blueprint $table): void {
            $table->dateTime('ssl_expires_at')->nullable()->after('expires_at');
            $table->string('provider_order_id')->nullable()->after('provider_domain_id');
            $table->string('provider_transaction_id')->nullable()->after('provider_order_id');
            $table->json('expiration_reminders_sent')->nullable()->after('metadata');
        });

        Schema::create('website_domain_reminder_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_domain_id')->constrained('website_domains')->cascadeOnDelete();
            $table->unsignedSmallInteger('days_before');
            $table->string('channel', 40)->default('in_app');
            $table->string('idempotency_key');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['website_domain_id', 'days_before']);
        });

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE website_domains MODIFY COLUMN status ENUM(
                'pending','registering','registered','dns_pending','dns_configured','verifying','verified','ssl_pending','active','failed','expired','cancelled','recovery_required'
            ) NOT NULL DEFAULT 'pending'");

            DB::statement("ALTER TABLE website_domain_operations MODIFY COLUMN status ENUM(
                'pending','processing','succeeded','failed','recovery_required'
            ) NOT NULL DEFAULT 'pending'");

            DB::statement("ALTER TABLE website_domain_operations MODIFY COLUMN operation_type ENUM(
                'search','purchase','configure_dns','verify','provision_ssl','renew','set_primary','remove','sync_status','recover_purchase','auto_renew','expiration_reminder','sync_ssl'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('website_domain_reminder_logs');

        Schema::table('website_domains', function (Blueprint $table): void {
            $table->dropColumn([
                'ssl_expires_at',
                'provider_order_id',
                'provider_transaction_id',
                'expiration_reminders_sent',
            ]);
        });
    }
};
