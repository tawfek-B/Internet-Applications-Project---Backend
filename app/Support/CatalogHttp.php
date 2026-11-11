<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class CatalogHttp
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function jsonWithEtag(
        Request $request,
        string $etag,
        array $payload,
        int $status = 200,
    ): JsonResponse|Response {
        $clientEtag = $request->header('If-None-Match');

        if ($clientEtag !== null && self::etagMatches($clientEtag, $etag)) {
            return response('', Response::HTTP_NOT_MODIFIED)
                ->header('ETag', $etag)
                ->header('Cache-Control', 'private, must-revalidate');
        }

        return response()
            ->json($payload, $status)
            ->header('ETag', $etag)
            ->header('Cache-Control', 'private, must-revalidate');
    }

    private static function etagMatches(string $client, string $server): bool
    {
        $client = trim($client);

        if (str_contains($client, ',')) {
            foreach (explode(',', $client) as $part) {
                if (trim($part) === $server) {
                    return true;
                }
            }

            return false;
        }

        return trim($client) === $server;
    }
}
