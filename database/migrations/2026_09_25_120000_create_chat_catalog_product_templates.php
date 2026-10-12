<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chat_catalog_product_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('slug');
            $table->string('name');
            $table->string('classification_hint', 32)->nullable();
            $table->string('status', 32)->default('active');
            $table->unsignedInteger('schema_version')->default(1);
            $table->boolean('is_system')->default(false);
            $table->foreignId('source_template_id')->nullable()
                ->constrained('chat_catalog_product_templates')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['workspace_id', 'slug'], 'chat_catalog_product_templates_ws_slug_unique');
            $table->index(['is_system', 'status'], 'chat_catalog_product_templates_system_idx');
        });

        Schema::create('chat_catalog_product_template_fields', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_template_id')
                ->constrained('chat_catalog_product_templates')->cascadeOnDelete();
            $table->string('field_key');
            $table->json('label');
            $table->string('field_type', 32);
            $table->string('level', 16)->default('product');
            $table->boolean('required')->default(false);
            $table->boolean('searchable')->default(true);
            $table->boolean('filterable')->default(false);
            $table->boolean('customer_visible')->default(true);
            $table->json('options')->nullable();
            $table->json('validation')->nullable();
            $table->json('visibility_rules')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['product_template_id', 'field_key'], 'chat_catalog_product_template_fields_key_unique');
            $table->index(['product_template_id', 'sort_order'], 'chat_catalog_product_template_fields_sort_idx');
        });

        Schema::table('chat_catalog_items', function (Blueprint $table): void {
            $table->foreignId('product_template_id')->nullable()->after('workspace_id')
                ->constrained('chat_catalog_product_templates')->nullOnDelete();
            $table->unsignedInteger('product_template_schema_version')->nullable()->after('product_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('chat_catalog_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('product_template_id');
            $table->dropColumn('product_template_schema_version');
        });

        Schema::dropIfExists('chat_catalog_product_template_fields');
        Schema::dropIfExists('chat_catalog_product_templates');
    }
};
