<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $rows = DB::table('appointment_bookings as booking')
            ->join('appointment_services as service', 'service.id', '=', 'booking.service_id')
            ->select([
                'booking.id',
                'booking.starts_at',
                'booking.ends_at',
                'service.duration_minutes',
            ])
            ->whereNotNull('booking.starts_at')
            ->whereNotNull('booking.ends_at')
            ->get();

        foreach ($rows as $row) {
            $start = Carbon::parse((string) $row->starts_at, 'UTC');
            $end = Carbon::parse((string) $row->ends_at, 'UTC');
            $serviceDuration = max(5, (int) $row->duration_minutes);
            $duration = $start->diffInMinutes($end, false);

            $isClearlyInvalid = $duration <= 0 || $duration > max($serviceDuration * 3, 720);
            if (! $isClearlyInvalid) {
                continue;
            }

            DB::table('appointment_bookings')
                ->where('id', $row->id)
                ->update([
                    'ends_at' => $start->copy()->addMinutes($serviceDuration),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This data-correction migration intentionally has no rollback.
    }
};
