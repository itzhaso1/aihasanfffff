<?php

namespace Database\Factories;

use App\Models\DiningTable;
use App\Models\PosCustomerSession;
use App\Models\TableSession;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PosCustomerSession>
 */
class PosCustomerSessionFactory extends Factory
{
    protected $model = PosCustomerSession::class;

    public function definition(): array
    {
        return [
            'workspace_id' => Workspace::factory(),
            'dining_table_id' => DiningTable::factory(),
            'table_session_id' => TableSession::factory(),
            'token' => Str::random(64),
            'status' => PosCustomerSession::STATUS_ACTIVE,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }
}
