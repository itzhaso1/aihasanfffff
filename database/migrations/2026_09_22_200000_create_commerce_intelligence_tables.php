<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('commerce_intelligence_profiles')) {
            Schema::create('commerce_intelligence_profiles', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
                $table->string('primary_domain', 64)->default('custom');
                $table->json('secondary_domains')->nullable();
                $table->json('enabled_capabilities')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index('workspace_id', 'commerce_intel_prof_ws_idx');
            });
        }

        if (! Schema::hasTable('commerce_attribute_definitions')) {
            Schema::create('commerce_attribute_definitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('key', 64);
                $table->string('display_name', 128);
                $table->text('description')->nullable();
                $table->string('data_type', 32)->default('string');
                $table->string('unit', 32)->nullable();
                $table->boolean('is_required')->default(false);
                $table->boolean('is_searchable')->default(true);
                $table->boolean('is_filterable')->default(true);
                $table->boolean('is_customer_visible')->default(true);
                $table->boolean('is_ai_readable')->default(true);
                $table->json('validation_constraints')->nullable();
                $table->json('enum_options')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->unique(['workspace_id', 'key'], 'commerce_attr_def_ws_key_uniq');
                $table->index(['workspace_id', 'is_active', 'sort_order'], 'commerce_attr_def_ws_act_sort_idx');
            });
        }

        if (Schema::hasTable('commerce_entity_attribute_values')
            && ! $this->foreignKeyExists('commerce_entity_attribute_values', 'cc_entity_attr_def_fk')) {
            Schema::drop('commerce_entity_attribute_values');
        }

        if (! Schema::hasTable('commerce_entity_attribute_values')) {
            Schema::create('commerce_entity_attribute_values', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('commerce_attribute_definition_id');
                $table->string('entity_type', 64);
                $table->unsignedBigInteger('entity_id');
                $table->text('value_string')->nullable();
                $table->decimal('value_number', 16, 4)->nullable();
                $table->boolean('value_boolean')->nullable();
                $table->json('value_json')->nullable();
                $table->string('unit', 32)->nullable();
                $table->string('source', 32)->default('merchant_provided');
                $table->decimal('confidence', 5, 4)->nullable();
                $table->timestamps();

                $table->foreign('commerce_attribute_definition_id', 'cc_entity_attr_def_fk')
                    ->references('id')->on('commerce_attribute_definitions')->cascadeOnDelete();

                $table->unique(
                    ['workspace_id', 'entity_type', 'entity_id', 'commerce_attribute_definition_id', 'source'],
                    'commerce_entity_attr_ws_ent_def_src_uniq',
                );
                $table->index(['workspace_id', 'entity_type', 'entity_id'], 'commerce_entity_attr_ws_ent_idx');
                $table->index(['workspace_id', 'commerce_attribute_definition_id'], 'commerce_entity_attr_ws_def_idx');
            });
        }

        if (! Schema::hasTable('commerce_requirement_definitions')) {
            Schema::create('commerce_requirement_definitions', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('key', 64);
                $table->string('label', 128);
                $table->text('description')->nullable();
                $table->string('data_type', 32)->default('string');
                $table->string('unit', 32)->nullable();
                $table->boolean('is_required')->default(true);
                $table->json('validation_rules')->nullable();
                $table->json('applicability')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['workspace_id', 'key'], 'commerce_req_def_ws_key_uniq');
                $table->index(['workspace_id', 'is_active'], 'commerce_req_def_ws_act_idx');
            });
        }

        if (! Schema::hasTable('commerce_compatibilities')) {
            Schema::create('commerce_compatibilities', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('source_entity_type', 64);
                $table->unsignedBigInteger('source_entity_id');
                $table->string('target_type', 32)->default('entity');
                $table->string('target_entity_type', 64)->nullable();
                $table->unsignedBigInteger('target_entity_id')->nullable();
                $table->json('target_reference')->nullable();
                $table->string('compatibility_type', 32)->default('compatible');
                $table->json('constraints')->nullable();
                $table->string('status', 32)->default('active');
                $table->string('provenance', 32)->default('merchant_provided');
                $table->timestamps();

                $table->index(['workspace_id', 'source_entity_type', 'source_entity_id'], 'commerce_compat_src_idx');
                $table->index(['workspace_id', 'target_entity_type', 'target_entity_id'], 'commerce_compat_tgt_idx');
            });
        }

        if (! Schema::hasTable('commerce_intelligence_rules')) {
            Schema::create('commerce_intelligence_rules', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->string('name', 128);
                $table->string('rule_type', 32);
                $table->string('target_entity_type', 64)->nullable();
                $table->unsignedBigInteger('target_entity_id')->nullable();
                $table->foreignId('category_id')->nullable();
                $table->json('conditions');
                $table->json('actions');
                $table->integer('priority')->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('category_id', 'cc_intel_rules_category_fk')
                    ->references('id')->on('chat_catalog_categories')->nullOnDelete();

                $table->index(['workspace_id', 'rule_type', 'is_active'], 'commerce_intel_rules_ws_type_idx');
            });
        }

        if (! Schema::hasTable('commerce_customer_contexts')) {
            Schema::create('commerce_customer_contexts', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();
                $table->string('session_id', 64)->nullable();
                $table->string('context_key', 64);
                $table->text('context_value')->nullable();
                $table->string('data_type', 32)->default('string');
                $table->string('unit', 32)->nullable();
                $table->string('source', 32)->default('customer_explicit');
                $table->decimal('confidence', 5, 4)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(
                    ['workspace_id', 'customer_id', 'session_id', 'context_key'],
                    'commerce_cust_ctx_ws_cust_sess_key_uniq',
                );
                $table->index(['workspace_id', 'customer_id'], 'commerce_cust_ctx_ws_cust_idx');
                $table->index(['workspace_id', 'session_id'], 'commerce_cust_ctx_ws_sess_idx');
            });
        }
    }

    private function foreignKeyExists(string $table, string $constraintName): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return true;
        }

        $database = Schema::getConnection()->getDatabaseName();

        $row = DB::selectOne(
            'select constraint_name from information_schema.table_constraints
             where table_schema = ? and table_name = ? and constraint_name = ? and constraint_type = ? limit 1',
            [$database, $table, $constraintName, 'FOREIGN KEY'],
        );

        return $row !== null;
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_customer_contexts');
        Schema::dropIfExists('commerce_intelligence_rules');
        Schema::dropIfExists('commerce_compatibilities');
        Schema::dropIfExists('commerce_requirement_definitions');
        Schema::dropIfExists('commerce_entity_attribute_values');
        Schema::dropIfExists('commerce_attribute_definitions');
        Schema::dropIfExists('commerce_intelligence_profiles');
    }
};
