<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('booking_settings')) {
            Schema::create('booking_settings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->string('timezone', 64)->default('Asia/Riyadh');
                $table->unsignedInteger('slot_interval_minutes')->default(30);
                $table->unsignedInteger('default_duration_minutes')->default(30);
                $table->unsignedInteger('default_capacity_per_slot')->default(1);
                $table->unsignedInteger('buffer_minutes')->default(0);
                $table->unsignedInteger('min_advance_minutes')->default(60);
                $table->unsignedInteger('max_advance_days')->default(60);
                $table->boolean('allow_party_size')->default(false);
                $table->boolean('require_staff')->default(false);
                $table->boolean('require_branch')->default(false);
                $table->boolean('auto_confirm')->default(true);
                $table->json('working_hours')->nullable();
                $table->json('holidays')->nullable();
                $table->json('custom_fields_schema')->nullable();
                $table->boolean('notifications_enabled')->default(true);
                $table->boolean('send_confirmation_message')->default(true);
                $table->boolean('send_update_message')->default(true);
                $table->boolean('send_reschedule_message')->default(true);
                $table->boolean('send_cancellation_message')->default(true);
                $table->boolean('reminder_enabled')->default(true);
                $table->unsignedInteger('reminder_lead_minutes')->default(120);
                $table->json('reminder_offsets_minutes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique('workspace_id', 'booking_settings_workspace_unique');
            });
        }

        if (! Schema::hasTable('booking_services')) {
            Schema::create('booking_services', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug')->nullable();
                $table->string('category', 100)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('duration_minutes')->nullable();
                $table->unsignedInteger('capacity_per_slot')->nullable();
                $table->unsignedBigInteger('price_minor')->nullable();
                $table->string('currency', 3)->default('SAR');
                $table->boolean('requires_party_size')->nullable();
                $table->unsignedInteger('max_party_size')->nullable();
                $table->json('custom_fields')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'is_active', 'sort_order'], 'booking_services_ws_active_idx');
            });
        }

        if (! Schema::hasTable('booking_branches')) {
            Schema::create('booking_branches', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('city', 120)->nullable();
                $table->string('address')->nullable();
                $table->string('phone', 40)->nullable();
                $table->decimal('latitude', 10, 7)->nullable();
                $table->decimal('longitude', 10, 7)->nullable();
                $table->json('working_hours_override')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'is_active'], 'booking_branches_ws_active_idx');
            });
        }

        if (! Schema::hasTable('booking_providers')) {
            Schema::create('booking_providers', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('booking_branches')->nullOnDelete();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('name');
                $table->string('role_label', 100)->nullable();
                $table->unsignedInteger('capacity_per_slot')->default(1);
                $table->json('service_ids')->nullable();
                $table->json('working_hours_override')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'is_active', 'branch_id'], 'booking_providers_ws_active_branch_idx');
            });
        }

        if (! Schema::hasTable('booking_blocked_slots')) {
            Schema::create('booking_blocked_slots', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('booking_branches')->nullOnDelete();
                $table->foreignId('provider_id')->nullable()->constrained('booking_providers')->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('booking_services')->nullOnDelete();
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->string('reason')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['workspace_id', 'starts_at', 'ends_at'], 'booking_blocked_slots_ws_window_idx');
            });
        }

        if (! Schema::hasTable('bookings')) {
            Schema::create('bookings', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('booking_reference', 40);
                $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('service_id')->nullable()->constrained('booking_services')->nullOnDelete();
                $table->foreignId('branch_id')->nullable()->constrained('booking_branches')->nullOnDelete();
                $table->foreignId('provider_id')->nullable()->constrained('booking_providers')->nullOnDelete();
                $table->foreignId('delivery_location_id')->nullable()->constrained('customer_locations')->nullOnDelete();
                $table->string('service_name');
                $table->string('customer_name');
                $table->string('customer_phone', 40)->nullable();
                $table->date('booking_date');
                $table->string('start_time', 8);
                $table->string('end_time', 8);
                $table->dateTime('starts_at');
                $table->dateTime('ends_at');
                $table->unsignedInteger('duration_minutes');
                $table->unsignedInteger('party_size')->nullable();
                $table->string('status', 24)->default('pending');
                $table->string('source', 32)->default('manual');
                $table->string('channel', 32)->nullable();
                $table->text('customer_notes')->nullable();
                $table->text('internal_notes')->nullable();
                $table->json('custom_fields')->nullable();
                $table->unsignedBigInteger('source_message_id')->nullable();
                $table->string('idempotency_key', 128)->nullable();
                $table->unsignedBigInteger('rescheduled_from_booking_id')->nullable();
                $table->unsignedInteger('reschedule_count')->default(0);
                $table->dateTime('previous_starts_at')->nullable();
                $table->timestamp('confirmed_at')->nullable();
                $table->timestamp('rescheduled_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamp('no_show_at')->nullable();
                $table->string('cancellation_reason')->nullable();
                $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->unique(['workspace_id', 'booking_reference'], 'bookings_ws_ref_unique');
                $table->unique(['workspace_id', 'idempotency_key'], 'bookings_ws_idempotency_unique');
                $table->index(['workspace_id', 'starts_at', 'ends_at', 'status'], 'bookings_ws_window_status_idx');
                $table->index(['workspace_id', 'booking_date', 'status'], 'bookings_ws_date_status_idx');
                $table->index(['workspace_id', 'customer_id', 'starts_at'], 'bookings_ws_customer_starts_idx');
                $table->index(['workspace_id', 'conversation_id'], 'bookings_ws_conversation_idx');
                $table->index(['workspace_id', 'customer_phone'], 'bookings_ws_phone_idx');
            });
        }

        if (! Schema::hasTable('booking_revisions')) {
            Schema::create('booking_revisions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->string('event_type', 40);
                $table->string('actor_type', 32)->default('system');
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('previous_status', 24)->nullable();
                $table->string('new_status', 24)->nullable();
                $table->dateTime('previous_starts_at')->nullable();
                $table->dateTime('new_starts_at')->nullable();
                $table->json('payload')->nullable();
                $table->timestamp('created_at')->useCurrent();

                $table->index(['workspace_id', 'booking_id', 'id'], 'booking_revisions_ws_booking_idx');
            });
        }

        if (! Schema::hasTable('booking_reminders')) {
            Schema::create('booking_reminders', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('booking_id')->constrained('bookings')->cascadeOnDelete();
                $table->string('reminder_type', 32);
                $table->string('channel', 32)->nullable();
                $table->dateTime('scheduled_for');
                $table->timestamp('sent_at')->nullable();
                $table->string('status', 24)->default('pending');
                $table->unsignedBigInteger('message_id')->nullable();
                $table->string('error_message')->nullable();
                $table->timestamps();

                $table->unique(['booking_id', 'reminder_type', 'scheduled_for'], 'booking_reminders_unique_dispatch');
                $table->index(['status', 'scheduled_for'], 'booking_reminders_due_idx');
                $table->index(['workspace_id', 'booking_id'], 'booking_reminders_ws_booking_idx');
            });
        }

        if (Schema::hasTable('permissions')) {
            foreach (['bookings.view', 'bookings.manage', 'bookings.cancel', 'bookings.settings.manage'] as $permissionName) {
                Permission::query()->firstOrCreate([
                    'name' => $permissionName,
                    'guard_name' => 'web',
                ]);
            }

            if (Schema::hasTable('roles')) {
                foreach (['owner', 'admin', 'manager'] as $roleName) {
                    $role = Role::query()->where('name', $roleName)->where('guard_name', 'web')->first();
                    if ($role instanceof Role) {
                        $role->givePermissionTo(['bookings.view', 'bookings.manage', 'bookings.cancel', 'bookings.settings.manage']);
                    }
                }

                $receptionist = Role::query()->where('name', 'receptionist')->where('guard_name', 'web')->first();
                if ($receptionist instanceof Role) {
                    $receptionist->givePermissionTo(['bookings.view', 'bookings.manage']);
                }
            }

            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_reminders');
        Schema::dropIfExists('booking_revisions');
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('booking_blocked_slots');
        Schema::dropIfExists('booking_providers');
        Schema::dropIfExists('booking_branches');
        Schema::dropIfExists('booking_services');
        Schema::dropIfExists('booking_settings');
    }
};
