<?php

namespace App\Http\Api;

use App\EInvoicing\QR\QrEncodingException;
use App\EInvoicing\Security\Exceptions\ProductionCryptographicProfileException;
use App\EInvoicing\Xml\EInvoiceXmlMappingException;
use App\Exceptions\Api\ApiApplicationException;
use App\Exceptions\Api\ApiErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinanceApiErrorRenderer
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/finance/*') || $request->is('api/finance/v1/*');
    }

    public static function jsonApi(Request $request): bool
    {
        return $request->is('api/mobile/*')
            || $request->is('api/mobile/v1/*')
            || $request->is('api/cashier/*')
            || self::matches($request);
    }

    public static function unauthenticated(): JsonResponse
    {
        return self::payload('Unauthenticated.', ApiErrorCode::Unauthorized, 401);
    }

    public static function forbidden(): JsonResponse
    {
        return self::payload('You are not allowed to access this resource.', ApiErrorCode::Forbidden, 403);
    }

    public static function notFound(): JsonResponse
    {
        return self::payload('The requested invoice was not found.', ApiErrorCode::NotFound, 404);
    }

    public static function validation(ValidationException $exception): JsonResponse
    {
        return self::payload(
            'Validation failed.',
            ApiErrorCode::ValidationFailed,
            422,
            $exception->errors(),
        );
    }

    public static function application(ApiApplicationException $exception): JsonResponse
    {
        return self::payload($exception->getMessage(), $exception->errorCode, $exception->status);
    }

    public static function fromThrowable(\Throwable $exception): ?JsonResponse
    {
        if ($exception instanceof AuthenticationException) {
            return self::unauthenticated();
        }
        if ($exception instanceof AuthorizationException) {
            return self::forbidden();
        }
        if ($exception instanceof ModelNotFoundException || $exception instanceof NotFoundHttpException) {
            return self::notFound();
        }
        if ($exception instanceof HttpExceptionInterface) {
            $status = $exception->getStatusCode();
            $code = match ($status) {
                401 => ApiErrorCode::Unauthorized,
                403 => ApiErrorCode::Forbidden,
                404 => ApiErrorCode::NotFound,
                409 => ApiErrorCode::IdempotencyConflict,
                422 => ApiErrorCode::ValidationFailed,
                default => ApiErrorCode::Forbidden,
            };
            $message = match ($status) {
                401 => 'Unauthenticated.',
                403 => 'You are not allowed to access this resource.',
                404 => 'The requested invoice was not found.',
                422 => 'Validation failed.',
                default => 'The request could not be completed.',
            };

            return self::payload($message, $code, $status >= 400 && $status < 600 ? $status : 400);
        }
        if ($exception instanceof ValidationException) {
            return self::validation($exception);
        }
        if ($exception instanceof ApiApplicationException) {
            return self::application($exception);
        }
        if ($exception instanceof UniqueConstraintViolationException) {
            return self::payload(
                'The request conflicted with an existing invoice artifact.',
                ApiErrorCode::IdempotencyConflict,
                409,
            );
        }
        if ($exception instanceof ProductionCryptographicProfileException) {
            return self::payload(
                'Production ZATCA cryptographic profile is unresolved and unavailable.',
                ApiErrorCode::ProductionCryptoUnavailable,
                409,
            );
        }
        if ($exception instanceof QrEncodingException || $exception instanceof EInvoiceXmlMappingException) {
            return self::payload(
                'Electronic-invoice compliance artifacts are not available for this document.',
                ApiErrorCode::ComplianceUnavailable,
                409,
            );
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $errors
     */
    private static function payload(
        string $message,
        ApiErrorCode $code,
        int $status,
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'success' => false,
            'message' => $message,
            'code' => $code->value,
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
