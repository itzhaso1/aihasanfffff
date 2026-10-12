<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Generic AI employee system. Additive only: ai_settings stays untouched and remains the
 * source for the current Inbox, Finance, POS and Cashier assistants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_employees', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            $table->string('avatar_key', 64)->nullable();
            $table->string('avatar_path')->nullable();
            $table->string('job_title', 128)->nullable();
            $table->string('gender', 16)->nullable();
            $table->string('role', 64);
            $table->string('status', 16)->default('active');
            // 1 for the workspace default, null otherwise; the unique index allows one default.
            $table->unsignedTinyInteger('default_slot')->nullable();
            $table->text('instructions')->nullable();
            $table->string('training_profile', 64)->nullable();
            $table->text('experience_notes')->nullable();
            $table->string('preset_key', 64)->nullable();
            $table->unsignedInteger('preset_version')->nullable();
            $table->foreignId('legacy_ai_setting_id')->nullable()->constrained('ai_settings')->nullOnDelete();
            $table->unsignedInteger('config_version')->default(1);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'default_slot'], 'ai_employees_workspace_default_unique');
            $table->index(['workspace_id', 'status'], 'ai_employees_workspace_status_index');
        });

        Schema::create('ai_employee_personas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->unique()->constrained('ai_employees')->cascadeOnDelete();
            $table->string('language', 16)->default('ar');
            $table->string('dialect', 32)->nullable();
            $table->string('formality', 32)->default('casual_professional');
            $table->string('tone', 32)->default('friendly');
            $table->string('emoji_policy', 16)->default('minimal');
            $table->string('greeting_policy', 32)->default('introduce_first_contact');
            $table->json('traits')->nullable();
            $table->text('style_notes')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_employee_skills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->constrained('ai_employees')->cascadeOnDelete();
            $table->string('skill_key', 64);
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['ai_employee_id', 'skill_key'], 'ai_employee_skills_unique');
        });

        Schema::create('ai_employee_tool_permissions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->constrained('ai_employees')->cascadeOnDelete();
            $table->string('tool_name', 64);
            $table->boolean('allowed')->default(true);
            $table->unsignedSmallInteger('max_calls_per_run')->nullable();
            $table->timestamps();

            $table->unique(['ai_employee_id', 'tool_name'], 'ai_employee_tools_unique');
        });

        Schema::create('ai_employee_memory_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->unique()->constrained('ai_employees')->cascadeOnDelete();
            $table->boolean('conversation_memory_enabled')->default(true);
            $table->boolean('customer_memory_enabled')->default(true);
            $table->boolean('commerce_context_enabled')->default(true);
            $table->boolean('relevant_history_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_employee_model_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_employee_id')->unique()->constrained('ai_employees')->cascadeOnDelete();
            $table->string('provider', 64);
            $table->string('model', 128);
            $table->decimal('temperature', 3, 2)->default(0.4);
            $table->string('response_length', 16)->default('short');
            $table->unsignedSmallInteger('max_output_tokens')->nullable();
            $table->unsignedInteger('thinking_budget')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_employee_model_settings');
        Schema::dropIfExists('ai_employee_memory_settings');
        Schema::dropIfExists('ai_employee_tool_permissions');
        Schema::dropIfExists('ai_employee_skills');
        Schema::dropIfExists('ai_employee_personas');
        Schema::dropIfExists('ai_employees');
    }
};
