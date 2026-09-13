<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'is_official')) {
                $table->boolean('is_official')->default(false)->after('is_public')->index();
            }
            if (! Schema::hasColumn('plans', 'display_name_ar')) {
                $table->string('display_name_ar')->nullable()->after('name');
            }
        });

        Schema::create('merchant_document_types', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->boolean('requires_number')->default(false);
            $table->boolean('requires_expiry')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('merchant_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('verification_status', 32)->default('not_requested')->index();
            $table->string('provider_onboarding_status', 32)->default('not_started')->index();
            $table->string('provider', 64)->nullable();
            $table->string('provider_merchant_id')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('merchant_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_profile_id')->constrained('merchant_profiles')->cascadeOnDelete();
            $table->foreignId('document_type_id')->nullable()->constrained('merchant_document_types')->nullOnDelete();
            $table->string('document_type_code', 64);
            $table->string('document_number')->nullable();
            $table->string('disk', 32)->default('local');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 128)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('status', 32)->default('submitted')->index();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('platform_admins')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                if (! Schema::hasColumn('payments', 'payment_context')) {
                    $table->string('payment_context', 64)->nullable()->after('provider')->index();
                }
                if (! Schema::hasColumn('payments', 'money_bucket')) {
                    $table->string('money_bucket', 64)->nullable()->after('payment_context')->index();
                }
            });
        }

        if (Schema::hasTable('subscription_checkout_sessions')) {
            Schema::table('subscription_checkout_sessions', function (Blueprint $table): void {
                if (! Schema::hasColumn('subscription_checkout_sessions', 'payment_context')) {
                    $table->string('payment_context', 64)->default('platform_subscription')->after('payment_provider');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('payments')) {
            Schema::table('payments', function (Blueprint $table): void {
                foreach (['payment_context', 'money_bucket'] as $column) {
                    if (Schema::hasColumn('payments', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('subscription_checkout_sessions') && Schema::hasColumn('subscription_checkout_sessions', 'payment_context')) {
            Schema::table('subscription_checkout_sessions', function (Blueprint $table): void {
                $table->dropColumn('payment_context');
            });
        }

        Schema::dropIfExists('merchant_documents');
        Schema::dropIfExists('merchant_profiles');
        Schema::dropIfExists('merchant_document_types');

        Schema::table('plans', function (Blueprint $table): void {
            foreach (['is_official', 'display_name_ar'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
