<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            if (! Schema::hasColumn('message_attachments', 'provider')) {
                $table->string('provider', 32)->nullable()->after('message_id');
            }
            if (! Schema::hasColumn('message_attachments', 'provider_media_id')) {
                $table->string('provider_media_id', 191)->nullable()->after('provider');
            }
            if (! Schema::hasColumn('message_attachments', 'status')) {
                $table->string('status', 32)->default('stored')->after('kind');
            }
            if (! Schema::hasColumn('message_attachments', 'checksum')) {
                $table->string('checksum', 64)->nullable()->after('thumbnail_path');
            }
            if (! Schema::hasColumn('message_attachments', 'width')) {
                $table->unsignedInteger('width')->nullable()->after('checksum');
            }
            if (! Schema::hasColumn('message_attachments', 'height')) {
                $table->unsignedInteger('height')->nullable()->after('width');
            }
            if (! Schema::hasColumn('message_attachments', 'duration_ms')) {
                $table->unsignedInteger('duration_ms')->nullable()->after('height');
            }
            if (! Schema::hasColumn('message_attachments', 'provider_metadata')) {
                $table->json('provider_metadata')->nullable()->after('duration_ms');
            }
        });

        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->index(['workspace_id', 'status']);
            $table->unique(
                ['workspace_id', 'provider', 'provider_media_id'],
                'message_attachments_provider_media_unique',
            );
        });

        foreach (DB::table('message_attachments')->whereNull('provider')->get(['id']) as $row) {
            DB::table('message_attachments')
                ->where('id', $row->id)
                ->update([
                    'provider' => 'legacy',
                    'provider_media_id' => 'legacy-'.$row->id,
                    'status' => 'stored',
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('message_attachments', function (Blueprint $table): void {
            $table->dropUnique('message_attachments_provider_media_unique');
            $table->dropIndex(['workspace_id', 'status']);
            $table->dropColumn([
                'provider',
                'provider_media_id',
                'status',
                'checksum',
                'width',
                'height',
                'duration_ms',
                'provider_metadata',
            ]);
        });
    }
};
