<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            if (! Schema::hasColumn('plans', 'tier')) {
                $table->string('tier', 32)->nullable()->after('code')->index();
            }
            if (! Schema::hasColumn('plans', 'sort_order')) {
                $table->unsignedInteger('sort_order')->default(0)->after('is_active');
            }
            if (! Schema::hasColumn('plans', 'description')) {
                $table->text('description')->nullable()->after('name');
            }
            if (! Schema::hasColumn('plans', 'overage_rules')) {
                $table->json('overage_rules')->nullable()->after('limits');
            }
            if (! Schema::hasColumn('plans', 'is_public')) {
                $table->boolean('is_public')->default(true)->after('is_active');
            }
            if (! Schema::hasColumn('plans', 'trial_days')) {
                $table->unsignedInteger('trial_days')->default(14)->after('billing_period');
            }
        });

        Schema::create('workspace_usage_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('meter_key', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('used', 16, 4)->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'meter_key', 'period_start'], 'ws_usage_meter_period_uniq');
            $table->index(['workspace_id', 'meter_key']);
        });

        Schema::create('plan_addons', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('meter_key', 64)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('price', 12, 2)->default(0);
            $table->string('currency', 3)->default('SAR');
            $table->string('billing_period', 16)->default('monthly');
            $table->json('grants')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('workspace_addons', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_addon_id')->constrained('plan_addons')->cascadeOnDelete();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('quantity')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status']);
        });

        Schema::create('api_keys', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('key_prefix', 16);
            $table->string('key_hash', 64);
            $table->json('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'key_hash']);
            $table->index(['workspace_id', 'revoked_at']);
        });

        Schema::create('customer_tags', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('color', 32)->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('customer_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name']);
        });

        Schema::create('customer_tag_customer', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_tag_id')->constrained('customer_tags')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'customer_tag_id'], 'customer_tag_pivot_uniq');
        });

        Schema::create('customer_group_customer', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_group_id')->constrained('customer_groups')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['customer_id', 'customer_group_id'], 'customer_group_pivot_uniq');
        });

        Schema::create('customer_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['workspace_id', 'customer_id']);
        });

        Schema::create('whatsapp_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_account_id')->nullable()->constrained('whats_app_accounts')->nullOnDelete();
            $table->string('name');
            $table->string('language', 16)->default('ar');
            $table->string('category', 64)->nullable();
            $table->string('status', 32)->default('draft');
            $table->string('provider_template_id')->nullable();
            $table->json('components')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->unique(['workspace_id', 'name', 'language'], 'wa_template_ws_name_lang_uniq');
        });

        Schema::create('whatsapp_outbound_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('whats_app_phone_number_id')->nullable()->constrained('whats_app_phone_numbers')->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->string('to');
            $table->string('type', 32)->default('text');
            $table->text('body')->nullable();
            $table->string('template_name')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->string('status', 32)->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->json('provider_response')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
            $table->index(['provider_message_id']);
        });

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                if (! Schema::hasColumn('subscriptions', 'paused_at')) {
                    $table->timestamp('paused_at')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('subscriptions', 'grace_ends_at')) {
                    $table->timestamp('grace_ends_at')->nullable()->after('paused_at');
                }
                if (! Schema::hasColumn('subscriptions', 'failed_payment_count')) {
                    $table->unsignedInteger('failed_payment_count')->default(0)->after('grace_ends_at');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_outbound_messages');
        Schema::dropIfExists('whatsapp_templates');
        Schema::dropIfExists('customer_notes');
        Schema::dropIfExists('customer_group_customer');
        Schema::dropIfExists('customer_tag_customer');
        Schema::dropIfExists('customer_groups');
        Schema::dropIfExists('customer_tags');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('workspace_addons');
        Schema::dropIfExists('plan_addons');
        Schema::dropIfExists('workspace_usage_meters');

        Schema::table('plans', function (Blueprint $table): void {
            foreach (['tier', 'sort_order', 'description', 'overage_rules', 'is_public', 'trial_days'] as $column) {
                if (Schema::hasColumn('plans', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        if (Schema::hasTable('subscriptions')) {
            Schema::table('subscriptions', function (Blueprint $table): void {
                foreach (['paused_at', 'grace_ends_at', 'failed_payment_count'] as $column) {
                    if (Schema::hasColumn('subscriptions', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
