<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_contacts', function (Blueprint $table): void {
            $table->string('phone')->nullable()->after('normalized_email');
            $table->string('company')->nullable()->after('phone');
            $table->string('job_title')->nullable()->after('company');
            $table->text('notes')->nullable()->after('job_title');
            $table->boolean('is_favorite')->default(false)->after('notes');
            $table->string('avatar_path')->nullable()->after('is_favorite');
            $table->softDeletes();
        });

        Schema::create('email_contact_groups', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'name'], 'email_contact_groups_workspace_name_unique');
        });

        Schema::create('email_contact_group_contact', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_contact_group_id')->constrained('email_contact_groups')->cascadeOnDelete();
            $table->foreignId('email_contact_id')->constrained('email_contacts')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(
                ['email_contact_group_id', 'email_contact_id'],
                'email_contact_group_contact_unique',
            );
        });

        Schema::create('stories', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // text|image|video
            $table->text('caption')->nullable();
            $table->text('body_text')->nullable();
            $table->string('background_color')->nullable();
            $table->string('media_disk')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_mime')->nullable();
            $table->unsignedInteger('media_size')->nullable();
            $table->string('thumbnail_path')->nullable();
            $table->string('visibility')->default('workspace'); // workspace|selected|hidden
            $table->json('selected_user_ids')->nullable();
            $table->json('hidden_user_ids')->nullable();
            $table->timestamp('expires_at');
            $table->unsignedInteger('views_count')->default(0);
            $table->string('status')->default('active'); // active|expired|deleted
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'expires_at'], 'stories_workspace_expires_index');
            $table->index(['workspace_id', 'status'], 'stories_workspace_status_index');
        });

        Schema::create('story_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->timestamps();

            $table->unique(['story_id', 'user_id'], 'story_views_story_user_unique');
        });

        Schema::create('email_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('email_account_id')->constrained('email_accounts')->cascadeOnDelete();
            $table->string('subject');
            $table->text('body');
            $table->string('status')->default('draft'); // draft|queued|sending|completed|partial|failed|cancelled
            $table->unsignedInteger('recipient_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('email_campaign_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('email_campaign_id')->constrained('email_campaigns')->cascadeOnDelete();
            $table->foreignId('email_contact_id')->nullable()->constrained('email_contacts')->nullOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->string('status')->default('pending'); // pending|sent|failed|skipped
            $table->unsignedBigInteger('email_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['email_campaign_id', 'status'], 'email_campaign_recipients_campaign_status_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_campaign_recipients');
        Schema::dropIfExists('email_campaigns');
        Schema::dropIfExists('story_views');
        Schema::dropIfExists('stories');
        Schema::dropIfExists('email_contact_group_contact');
        Schema::dropIfExists('email_contact_groups');

        Schema::table('email_contacts', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'phone',
                'company',
                'job_title',
                'notes',
                'is_favorite',
                'avatar_path',
            ]);
        });
    }
};
