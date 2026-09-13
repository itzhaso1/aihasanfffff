<?php

namespace App\Http\Controllers\Api\Finance;

use App\Exceptions\Api\ApiErrorCode;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

abstract class FinanceApiController extends Controller
{
    /**
     * @param  array<string, mixed>|mixed  $data
     * @param  array<string, mixed>  $meta
     */
    protected function ok(mixed $data = null, array $meta = [], string $message = '', int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($message !== '') {
            $payload['message'] = $message;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>  $errors
     * @param  array<string, mixed>  $meta
     */
    protected function fail(
        string $message,
        ApiErrorCode $code,
        int $status = 400,
        array $errors = [],
        array $meta = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code->value,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }
}
