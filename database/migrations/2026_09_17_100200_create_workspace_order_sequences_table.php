<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_order_sequences', function (Blueprint $table): void {
            $table->foreignId('workspace_id')->primary()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('next_commerce_sequence')->default(1);
            $table->unsignedBigInteger('next_pos_sequence')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_order_sequences');
    }
};
