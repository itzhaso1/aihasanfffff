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
        Schema::create('website_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->string('name');
            $table->string('category', 50)->nullable();
            $table->text('description')->nullable();
            $table->string('preview_image')->nullable();
            $table->json('layout')->nullable();
            $table->json('default_sections')->nullable();
            $table->json('theme_preset')->nullable();
            $table->json('metadata')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
            $table->index(['category', 'is_active']);
        });

        Schema::create('websites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('website_templates')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('status', ['draft', 'published', 'unpublished', 'suspended'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->string('preview_token', 80)->unique();
            $table->json('settings')->nullable();
            $table->json('theme')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'name']);
            $table->index(['workspace_id', 'status']);
            $table->index(['workspace_id', 'template_id']);
        });

        Schema::create('website_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('slug');
            $table->string('title');
            $table->boolean('is_homepage')->default(false);
            $table->boolean('is_published')->default(false);
            $table->json('settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['website_id', 'slug']);
            $table->index(['workspace_id', 'website_id', 'is_published']);
        });

        Schema::create('website_sections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->foreignId('website_page_id')->nullable()->constrained('website_pages')->cascadeOnDelete();
            $table->string('section_key');
            $table->string('component_key');
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_enabled')->default(true);
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'website_id']);
            $table->index(['website_id', 'website_page_id', 'position']);
            $table->index(['website_id', 'is_enabled', 'position']);
        });

        Schema::create('website_domains', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('domain');
            $table->string('normalized_domain');
            $table->enum('type', ['platform_subdomain', 'custom_domain'])->default('custom_domain');
            $table->string('provider', 40)->default('namecheap');
            $table->string('provider_domain_id')->nullable();
            $table->enum('status', [
                'pending',
                'registering',
                'registered',
                'dns_pending',
                'dns_configured',
                'verifying',
                'verified',
                'ssl_pending',
                'active',
                'failed',
                'expired',
                'cancelled',
            ])->default('pending');
            $table->enum('verification_status', ['unverified', 'verifying', 'verified', 'failed'])->default('unverified');
            $table->enum('ssl_status', ['not_requested', 'pending', 'active', 'failed', 'expired'])->default('not_requested');
            $table->dateTime('expires_at')->nullable();
            $table->boolean('auto_renew')->default(false);
            $table->enum('dns_status', ['unknown', 'pending', 'configured', 'failed'])->default('unknown');
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('normalized_domain');
            $table->index(['workspace_id', 'website_id']);
            $table->index(['workspace_id', 'status']);
            $table->index(['website_id', 'is_primary']);
            $table->index(['workspace_id', 'type']);
        });

        Schema::table('websites', function (Blueprint $table): void {
            $table->foreignId('primary_domain_id')
                ->nullable()
                ->after('template_id')
                ->constrained('website_domains')
                ->nullOnDelete();
        });

        Schema::create('website_assets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->constrained('websites')->cascadeOnDelete();
            $table->string('asset_type', 50);
            $table->string('disk', 50)->default('public');
            $table->string('path');
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['workspace_id', 'website_id', 'asset_type']);
        });

        Schema::create('website_domain_operations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained('websites')->nullOnDelete();
            $table->foreignId('website_domain_id')->nullable()->constrained('website_domains')->nullOnDelete();
            $table->enum('operation_type', ['search', 'purchase', 'configure_dns', 'verify', 'provision_ssl', 'renew', 'set_primary', 'remove', 'sync_status']);
            $table->string('provider', 40)->default('namecheap');
            $table->enum('status', ['pending', 'processing', 'succeeded', 'failed'])->default('pending');
            $table->string('idempotency_key')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'idempotency_key']);
            $table->index(['workspace_id', 'operation_type', 'status'], 'ws_dom_ops_ws_op_status_idx');
        });

        Schema::create('website_domain_contacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_domain_id')->constrained('website_domains')->cascadeOnDelete();
            $table->enum('contact_type', ['registrant', 'admin', 'tech', 'aux_billing']);
            $table->string('organization_name')->nullable();
            $table->string('job_title')->nullable();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('address1');
            $table->string('address2')->nullable();
            $table->string('city', 80);
            $table->string('state_province', 80);
            $table->string('postal_code', 40);
            $table->string('country', 4);
            $table->string('phone', 50);
            $table->string('email', 255);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['website_domain_id', 'contact_type'], 'ws_dom_contacts_unique');
            $table->index(['workspace_id', 'website_domain_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_domain_contacts');
        Schema::dropIfExists('website_domain_operations');
        Schema::dropIfExists('website_assets');

        Schema::table('websites', function (Blueprint $table): void {
            $table->dropForeign(['primary_domain_id']);
            $table->dropColumn('primary_domain_id');
        });

        Schema::dropIfExists('website_domains');
        Schema::dropIfExists('website_sections');
        Schema::dropIfExists('website_pages');
        Schema::dropIfExists('websites');
        Schema::dropIfExists('website_templates');
    }
};
