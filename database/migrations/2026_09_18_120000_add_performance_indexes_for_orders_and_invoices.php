<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if (! $this->hasIndex('orders', 'orders_workspace_placed_at_idx')) {
                    $table->index(['workspace_id', 'placed_at'], 'orders_workspace_placed_at_idx');
                }
            });
        }

        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if (! $this->hasIndex('finance_invoices', 'finance_invoices_workspace_updated_idx')) {
                    $table->index(['workspace_id', 'updated_at'], 'finance_invoices_workspace_updated_idx');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('orders')) {
            Schema::table('orders', function (Blueprint $table): void {
                if ($this->hasIndex('orders', 'orders_workspace_placed_at_idx')) {
                    $table->dropIndex('orders_workspace_placed_at_idx');
                }
            });
        }

        if (Schema::hasTable('finance_invoices')) {
            Schema::table('finance_invoices', function (Blueprint $table): void {
                if ($this->hasIndex('finance_invoices', 'finance_invoices_workspace_updated_idx')) {
                    $table->dropIndex('finance_invoices_workspace_updated_idx');
                }
            });
        }
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
