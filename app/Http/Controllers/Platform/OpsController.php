<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Services\Observability\OperationalHealthService;
use App\Services\Observability\OpsMetricsRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OpsController extends Controller
{
    public function health(Request $request, OperationalHealthService $health): JsonResponse
    {
        $strict = $request->boolean('strict', app()->environment('production'));
        $snapshot = $health->snapshot(productionStrict: $strict, requireRedis: $strict);

        $statusCode = match ($snapshot['status']) {
            'healthy' => 200,
            'degraded' => 200,
            default => 503,
        };

        return response()->json([
            'status' => $snapshot['status'],
            'checks' => collect($snapshot['checks'])->map(fn (array $check): array => [
                'ok' => $check['ok'],
                'detail' => $check['detail'],
            ]),
            'queues' => $snapshot['queues'],
            'generated_at' => $snapshot['generated_at'],
        ], $statusCode);
    }

    public function metrics(OpsMetricsRecorder $metrics): JsonResponse
    {
        return response()->json([
            'counters_last_hour' => [
                'webhook' => $metrics->countersForLastHour('webhook'),
                'inbound' => $metrics->countersForLastHour('inbound'),
                'outbound' => $metrics->countersForLastHour('outbound'),
                'ai' => $metrics->countersForLastHour('ai'),
                'realtime' => $metrics->countersForLastHour('realtime'),
            ],
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
