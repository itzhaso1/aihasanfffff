<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if (! $this->hasIndex('messages', 'messages_conversation_direction_id_idx')) {
                $table->index(['conversation_id', 'direction', 'id'], 'messages_conversation_direction_id_idx');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('messages')) {
            return;
        }

        Schema::table('messages', function (Blueprint $table): void {
            if ($this->hasIndex('messages', 'messages_conversation_direction_id_idx')) {
                $table->dropIndex('messages_conversation_direction_id_idx');
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = $connection->select("PRAGMA index_list('{$table}')");

            return collect($rows)->contains(fn ($row) => ($row->name ?? null) === $indexName);
        }

        $database = $connection->getDatabaseName();
        $result = $connection->select(
            'SELECT 1 FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ? LIMIT 1',
            [$database, $table, $indexName]
        );

        return $result !== [];
    }
};
