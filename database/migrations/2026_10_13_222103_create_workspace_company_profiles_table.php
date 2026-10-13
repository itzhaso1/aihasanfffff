<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_company_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('company_name', 120)->nullable();
            $table->text('about')->nullable();
            $table->text('offerings')->nullable();
            $table->text('services')->nullable();
            $table->text('audience')->nullable();
            $table->text('reply_guidelines')->nullable();
            $table->timestamps();

            $table->unique('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_company_profiles');
    }
};
