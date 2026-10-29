<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    public static function success(mixed $data = null, string $message = 'OK', int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'message' => $message,
        ];

        if (is_array($data)) {
            $payload = array_merge($payload, $data);
        } elseif ($data !== null) {
            $payload['result'] = $data;
        }

        return response()->json($payload, $status);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    public static function error(string $message, int $status = 400, ?array $errors = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }
}
