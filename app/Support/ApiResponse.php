<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response as HttpStatus;

/**
 * The single JSON envelope used by every AJAX endpoint in this application.
 *
 *   {
 *     "success": bool,
 *     "message": string|null,
 *     "data":    mixed|null,
 *     "errors":  object|null
 *   }
 *
 * The shape never varies, so the front-end helper in resources/js/app.js can
 * handle every response — including validation and server failures — without
 * per-endpoint special cases. See docs/04_UI_UX_SPECIFICATION.md.
 */
final class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = HttpStatus::HTTP_OK): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'errors' => null,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>|null  $errors
     */
    public static function error(string $message, ?array $errors = null, int $status = HttpStatus::HTTP_BAD_REQUEST): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => null,
            'errors' => $errors,
        ], $status);
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    public static function validationError(array $errors, string $message = 'The given data was invalid.'): JsonResponse
    {
        return self::error($message, $errors, HttpStatus::HTTP_UNPROCESSABLE_ENTITY);
    }

    public static function unauthorized(string $message = 'Authentication required.'): JsonResponse
    {
        return self::error($message, null, HttpStatus::HTTP_UNAUTHORIZED);
    }

    public static function forbidden(string $message = 'You are not allowed to perform this action.'): JsonResponse
    {
        return self::error($message, null, HttpStatus::HTTP_FORBIDDEN);
    }

    public static function notFound(string $message = 'The requested record was not found.'): JsonResponse
    {
        return self::error($message, null, HttpStatus::HTTP_NOT_FOUND);
    }

    public static function serverError(string $message = 'An unexpected error occurred.'): JsonResponse
    {
        return self::error($message, null, HttpStatus::HTTP_INTERNAL_SERVER_ERROR);
    }
}
