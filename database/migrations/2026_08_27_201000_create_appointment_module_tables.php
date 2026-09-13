<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('appointment_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('business_type', ['pharmacy', 'clinic', 'hospital', 'salon', 'general', 'other'])->default('general');
            $table->string('business_label')->nullable();
            $table->string('timezone', 64)->default('Asia/Riyadh');
            $table->unsignedSmallInteger('slot_interval_minutes')->default(30);
            $table->time('start_hour')->default('08:00:00');
            $table->time('end_hour')->default('22:00:00');
            $table->boolean('allow_walk_in')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('workspace_id', 'appt_set_ws_uniq');
        });

        Schema::create('appointment_services', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->decimal('price', 14, 2)->default(0);
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_confirmation')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name'], 'appt_srv_ws_name_uniq');
            $table->index(['workspace_id', 'is_active'], 'appt_srv_ws_active_idx');
        });

        Schema::create('appointment_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('role', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('color', 20)->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'user_id'], 'appt_staff_ws_user_idx');
            $table->index(['workspace_id', 'is_active'], 'appt_staff_ws_active_idx');
        });

        Schema::create('appointment_bookings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('booking_number');
            $table->foreignId('service_id')->constrained('appointment_services')->cascadeOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('appointment_staff')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 50)->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'])->default('scheduled');
            $table->enum('source', ['dashboard', 'phone', 'walk_in', 'website', 'whatsapp'])->default('dashboard');
            $table->text('notes')->nullable();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('booked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'booking_number'], 'appt_book_ws_num_uniq');
            $table->index(['workspace_id', 'status', 'starts_at'], 'appt_book_ws_status_start_idx');
            $table->index(['workspace_id', 'staff_id', 'starts_at'], 'appt_book_ws_staff_start_idx');
            $table->index(['workspace_id', 'customer_id', 'starts_at'], 'appt_book_ws_cust_start_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('appointment_bookings');
        Schema::dropIfExists('appointment_staff');
        Schema::dropIfExists('appointment_services');
        Schema::dropIfExists('appointment_settings');
    }
};
