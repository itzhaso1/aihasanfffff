<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('appointment_settings', function (Blueprint $table): void {
            $table->enum('automation_mode', ['AUTO', 'APPROVAL', 'MANUAL'])->default('APPROVAL')->after('allow_walk_in');
            $table->boolean('auto_confirm_after_payment')->default(true)->after('automation_mode');
            $table->json('reminder_offsets')->nullable()->after('auto_confirm_after_payment');
        });

        Schema::table('appointment_services', function (Blueprint $table): void {
            $table->boolean('requires_payment')->default(false)->after('requires_confirmation');
            $table->enum('payment_mode', ['full', 'deposit', 'postpaid'])->default('postpaid')->after('requires_payment');
            $table->decimal('deposit_amount', 14, 2)->nullable()->after('payment_mode');
            $table->boolean('approval_required')->default(false)->after('deposit_amount');
        });

        Schema::table('appointment_staff', function (Blueprint $table): void {
            $table->json('working_days')->nullable()->after('is_active');
            $table->json('working_hours')->nullable()->after('working_days');
            $table->json('vacation_periods')->nullable()->after('working_hours');
            $table->json('staff_permissions')->nullable()->after('vacation_periods');
        });

        Schema::create('appointment_requests', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->enum('request_type', ['new', 'reschedule', 'cancellation', 'information'])->default('new');
            $table->foreignId('target_booking_id')->nullable()->constrained('appointment_bookings')->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->foreignId('requested_staff_id')->nullable()->constrained('appointment_staff')->nullOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone', 50)->nullable();
            $table->string('customer_email')->nullable();
            $table->unsignedSmallInteger('customer_age')->nullable();
            $table->date('requested_date')->nullable();
            $table->time('requested_time')->nullable();
            $table->time('requested_time_end')->nullable();
            $table->enum('status', ['new', 'reviewing', 'awaiting_customer', 'approved', 'rejected', 'expired', 'cancelled'])->default('new');
            $table->enum('appointment_status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'])->nullable();
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_paid'])->default('unpaid');
            $table->enum('source', ['ai_chat', 'whatsapp', 'website', 'phone', 'dashboard', 'walk_in', 'email', 'api'])->default('dashboard');
            $table->enum('automation_mode', ['AUTO', 'APPROVAL', 'MANUAL'])->default('APPROVAL');
            $table->text('notes')->nullable();
            $table->boolean('ai_generated')->default(false);
            $table->json('ai_payload')->nullable();
            $table->timestamp('last_customer_response_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'status', 'requested_date'], 'appt_req_ws_status_date_idx');
            $table->index(['workspace_id', 'source', 'created_at'], 'appt_req_ws_source_created_idx');
            $table->index(['workspace_id', 'customer_phone'], 'appt_req_ws_cust_phone_idx');
        });

        Schema::create('appointment_request_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('request_id')->constrained('appointment_requests')->cascadeOnDelete();
            $table->foreignId('service_id')->nullable()->constrained('appointment_services')->nullOnDelete();
            $table->foreignId('staff_id')->nullable()->constrained('appointment_staff')->nullOnDelete();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->enum('status', ['proposed', 'selected', 'rejected', 'expired'])->default('proposed');
            $table->foreignId('proposed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'request_id', 'status'], 'appt_req_slot_ws_req_status_idx');
            $table->index(['workspace_id', 'staff_id', 'starts_at'], 'appt_req_slot_ws_staff_start_idx');
        });

        Schema::create('appointment_service_staff', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('appointment_services')->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('appointment_staff')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['workspace_id', 'service_id', 'staff_id'], 'appt_srv_staff_ws_srv_staff_uniq');
            $table->index(['workspace_id', 'staff_id'], 'appt_srv_staff_ws_staff_idx');
        });

        Schema::create('appointment_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->enum('resource_type', ['room', 'chair', 'equipment', 'meeting_room', 'other'])->default('other');
            $table->boolean('is_active')->default(true);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'resource_type', 'is_active'], 'appt_res_ws_type_active_idx');
            $table->unique(['workspace_id', 'name'], 'appt_res_ws_name_uniq');
        });

        Schema::create('appointment_booking_resources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('appointment_bookings')->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained('appointment_resources')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['workspace_id', 'booking_id', 'resource_id'], 'appt_book_res_ws_book_res_uniq');
            $table->index(['workspace_id', 'resource_id'], 'appt_book_res_ws_res_idx');
        });

        Schema::create('appointment_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->constrained('appointment_bookings')->cascadeOnDelete();
            $table->enum('channel', ['email', 'whatsapp', 'sms', 'in_app'])->default('in_app');
            $table->enum('status', ['queued', 'sent', 'failed', 'cancelled'])->default('queued');
            $table->timestamp('send_at');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'status', 'send_at'], 'appt_rem_ws_status_send_idx');
            $table->index(['workspace_id', 'booking_id'], 'appt_rem_ws_book_idx');
        });

        Schema::table('appointment_bookings', function (Blueprint $table): void {
            $table->foreignId('request_id')->nullable()->after('booking_number')->constrained('appointment_requests')->nullOnDelete();
            $table->string('source_channel', 32)->default('dashboard')->after('source');
            $table->enum('appointment_status', ['scheduled', 'confirmed', 'checked_in', 'in_progress', 'completed', 'cancelled', 'no_show'])->default('scheduled')->after('source_channel');
            $table->enum('payment_status', ['unpaid', 'pending', 'paid', 'failed', 'refunded', 'partially_paid'])->default('unpaid')->after('appointment_status');
            $table->foreignId('finance_invoice_id')->nullable()->after('payment_status')->constrained('finance_invoices')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->after('finance_invoice_id')->constrained()->nullOnDelete();
            $table->foreignId('latest_payment_id')->nullable()->after('order_id')->constrained('payments')->nullOnDelete();
            $table->string('customer_email')->nullable()->after('customer_phone');
            $table->unsignedSmallInteger('customer_age')->nullable()->after('customer_email');
            $table->string('public_token', 80)->nullable()->after('cancel_reason');
            $table->string('payment_link')->nullable()->after('public_token');
            $table->timestamp('confirmed_at')->nullable()->after('payment_link');
            $table->timestamp('checked_in_at')->nullable()->after('confirmed_at');
            $table->timestamp('in_progress_at')->nullable()->after('checked_in_at');
            $table->timestamp('completed_at')->nullable()->after('in_progress_at');
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');

            $table->index(['workspace_id', 'appointment_status', 'starts_at'], 'appt_book_ws_appt_status_idx');
            $table->index(['workspace_id', 'payment_status', 'starts_at'], 'appt_book_ws_pay_status_idx');
            $table->index(['workspace_id', 'request_id'], 'appt_book_ws_req_idx');
            $table->unique(['workspace_id', 'public_token'], 'appt_book_ws_pub_tok_uniq');
        });

        DB::statement("update appointment_bookings set appointment_status = status, source_channel = source");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointment_bookings', function (Blueprint $table): void {
            $table->dropUnique('appt_book_ws_pub_tok_uniq');
            $table->dropIndex('appt_book_ws_appt_status_idx');
            $table->dropIndex('appt_book_ws_pay_status_idx');
            $table->dropIndex('appt_book_ws_req_idx');

            $table->dropForeign(['request_id']);
            $table->dropForeign(['finance_invoice_id']);
            $table->dropForeign(['order_id']);
            $table->dropForeign(['latest_payment_id']);

            $table->dropColumn([
                'request_id',
                'source_channel',
                'appointment_status',
                'payment_status',
                'finance_invoice_id',
                'order_id',
                'latest_payment_id',
                'customer_email',
                'customer_age',
                'public_token',
                'payment_link',
                'confirmed_at',
                'checked_in_at',
                'in_progress_at',
                'completed_at',
                'cancelled_at',
            ]);
        });

        Schema::dropIfExists('appointment_reminders');
        Schema::dropIfExists('appointment_booking_resources');
        Schema::dropIfExists('appointment_resources');
        Schema::dropIfExists('appointment_service_staff');
        Schema::dropIfExists('appointment_request_slots');
        Schema::dropIfExists('appointment_requests');

        Schema::table('appointment_staff', function (Blueprint $table): void {
            $table->dropColumn([
                'working_days',
                'working_hours',
                'vacation_periods',
                'staff_permissions',
            ]);
        });

        Schema::table('appointment_services', function (Blueprint $table): void {
            $table->dropColumn([
                'requires_payment',
                'payment_mode',
                'deposit_amount',
                'approval_required',
            ]);
        });

        Schema::table('appointment_settings', function (Blueprint $table): void {
            $table->dropColumn([
                'automation_mode',
                'auto_confirm_after_payment',
                'reminder_offsets',
            ]);
        });
    }
};
