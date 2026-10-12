<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('company_name')->nullable();
            $table->string('company_description', 500)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('primary_color', 7)->default('#0F172A');
            $table->string('secondary_color', 7)->default('#2563EB');
            $table->string('background_color', 7)->default('#F4F6FB');
            $table->string('text_color', 7)->default('#111827');
            $table->string('header_style', 32)->default('solid');
            $table->boolean('show_header_logo')->default(true);
            $table->boolean('show_header_company_name')->default(true);
            $table->json('header_links')->nullable();
            $table->string('footer_email')->nullable();
            $table->string('footer_phone')->nullable();
            $table->string('footer_website')->nullable();
            $table->string('footer_address')->nullable();
            $table->string('footer_copyright')->nullable();
            $table->boolean('show_footer_company_name')->default(true);
            $table->boolean('show_footer_email')->default(true);
            $table->boolean('show_footer_phone')->default(true);
            $table->boolean('show_footer_website')->default(true);
            $table->boolean('show_footer_address')->default(true);
            $table->boolean('show_footer_social')->default(true);
            $table->boolean('show_footer_copyright')->default(true);
            $table->string('social_linkedin')->nullable();
            $table->string('social_x')->nullable();
            $table->string('social_facebook')->nullable();
            $table->string('social_instagram')->nullable();
            $table->string('social_youtube')->nullable();
            $table->string('cta_label')->nullable();
            $table->string('cta_url')->nullable();
            $table->boolean('show_cta')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_brandings');
    }
};
