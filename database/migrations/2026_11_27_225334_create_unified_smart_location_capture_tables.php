<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('customer_locations')) {
            Schema::create('customer_locations', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->unsignedInteger('accuracy_meters')->nullable();
                $table->string('source', 32);
                $table->string('platform', 32);
                $table->string('verification_status', 32)->default('pending_confirmation');
                $table->string('country_code', 8)->nullable();
                $table->string('country', 100)->nullable();
                $table->string('city', 120)->nullable();
                $table->string('district', 150)->nullable();
                $table->string('street', 200)->nullable();
                $table->string('postal_code', 32)->nullable();
                $table->string('place_name', 200)->nullable();
                $table->text('formatted_address')->nullable();
                $table->text('customer_notes')->nullable();
                $table->string('geocoding_provider', 40)->nullable();
                $table->string('geocoding_status', 32)->default('pending');
                $table->string('external_message_id', 191)->nullable();
                $table->json('raw_payload')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->foreignId('superseded_by_id')->nullable()->constrained('customer_locations')->nullOnDelete();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'customer_id', 'verification_status'], 'cust_loc_ws_cust_status_idx');
                $table->index(['workspace_id', 'conversation_id', 'created_at'], 'cust_loc_ws_conv_created_idx');
                $table->index(['workspace_id', 'order_id'], 'cust_loc_ws_order_idx');
                $table->unique(['workspace_id', 'external_message_id'], 'cust_loc_ws_ext_msg_unique');
            });
        }

        if (! Schema::hasTable('location_capture_sessions')) {
            Schema::create('location_capture_sessions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_location_id')->nullable()->constrained('customer_locations')->nullOnDelete();
                $table->foreignId('requested_by_message_id')->nullable()->constrained('messages')->nullOnDelete();
                $table->string('token_hash', 64)->unique('loc_capture_sessions_token_hash_unique');
                $table->string('token_hint', 16)->nullable();
                $table->string('platform', 32)->default('web');
                $table->string('purpose', 32)->default('delivery');
                $table->string('status', 32)->default('pending');
                $table->unsignedInteger('attempt_count')->default(0);
                $table->timestamp('expires_at');
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('locked_at')->nullable();
                $table->string('submitted_ip_hash', 64)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['workspace_id', 'conversation_id', 'status'], 'loc_cap_sess_ws_conv_status_idx');
                $table->index(['status', 'expires_at'], 'loc_cap_sess_status_expires_idx');
            });
        }

        if (! Schema::hasTable('location_capture_revisions')) {
            Schema::create('location_capture_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_location_id')->constrained('customer_locations')->cascadeOnDelete();
                $table->foreignId('capture_session_id')->nullable()->constrained('location_capture_sessions')->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
                $table->string('event_type', 40);
                $table->decimal('latitude', 10, 7);
                $table->decimal('longitude', 10, 7);
                $table->unsignedInteger('accuracy_meters')->nullable();
                $table->string('source', 32);
                $table->string('platform', 32);
                $table->text('formatted_address')->nullable();
                $table->string('actor_type', 32)->default('customer');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['workspace_id', 'customer_location_id', 'id'], 'loc_cap_rev_ws_loc_id_idx');
                $table->index(['workspace_id', 'order_id'], 'loc_cap_rev_ws_order_idx');
            });
        }

        if (Schema::hasTable('orders') && ! Schema::hasColumn('orders', 'delivery_location_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->foreignId('delivery_location_id')
                    ->nullable()
                    ->after('conversation_id')
                    ->constrained('customer_locations')
                    ->nullOnDelete();
                $table->index(['workspace_id', 'delivery_location_id'], 'orders_ws_delivery_loc_idx');
            });
        }

        if (Schema::hasTable('customers') && ! Schema::hasColumn('customers', 'primary_location_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->foreignId('primary_location_id')
                    ->nullable()
                    ->after('country_code')
                    ->constrained('customer_locations')
                    ->nullOnDelete();
                $table->index(['workspace_id', 'primary_location_id'], 'customers_ws_primary_loc_idx');
            });
        }

        if (Schema::hasTable('messages')) {
            $driver = Schema::getConnection()->getDriverName();
            if ($driver === 'mysql') {
                DB::statement("ALTER TABLE messages MODIFY message_type ENUM('text', 'image', 'file', 'system', 'location') NOT NULL DEFAULT 'text'");
            } else {
                Schema::table('messages', function (Blueprint $table): void {
                    $table->string('message_type', 32)->default('text')->change();
                });
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('messages') && Schema::getConnection()->getDriverName() === 'mysql') {
            DB::table('messages')->where('message_type', 'location')->update(['message_type' => 'text']);
            DB::statement("ALTER TABLE messages MODIFY message_type ENUM('text', 'image', 'file', 'system') NOT NULL DEFAULT 'text'");
        }

        if (Schema::hasTable('customers') && Schema::hasColumn('customers', 'primary_location_id')) {
            Schema::table('customers', function (Blueprint $table): void {
                $table->dropForeign(['primary_location_id']);
                $table->dropIndex('customers_ws_primary_loc_idx');
                $table->dropColumn('primary_location_id');
            });
        }

        if (Schema::hasTable('orders') && Schema::hasColumn('orders', 'delivery_location_id')) {
            Schema::table('orders', function (Blueprint $table): void {
                $table->dropForeign(['delivery_location_id']);
                $table->dropIndex('orders_ws_delivery_loc_idx');
                $table->dropColumn('delivery_location_id');
            });
        }

        Schema::dropIfExists('location_capture_revisions');
        Schema::dropIfExists('location_capture_sessions');
        Schema::dropIfExists('customer_locations');
    }
};
