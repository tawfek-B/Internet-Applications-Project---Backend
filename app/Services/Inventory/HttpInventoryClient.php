<?php

namespace App\Services\Inventory;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HttpInventoryClient implements InventoryClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
    ) {}

    public function getQuantity(int $productId): int
    {
        $result = $this->request('GET', "/products/{$productId}/quantity");

        return (int) ($result['available'] ?? 0);
    }

    public function reserve(string $reservationId, array $items): bool
    {
        $result = $this->request('POST', '/reservations', [
            'reservation_id' => $reservationId,
            'items' => $items,
        ]);

        if (! ($result['success'] ?? false)) {
            $message = (string) ($result['message'] ?? 'Reservation rejected');

            if (str_contains(strtolower($message), 'insufficient')) {
                throw InventoryException::insufficientStock($message);
            }

            throw InventoryException::rejected($message);
        }

        return true;
    }

    public function release(string $reservationId): void
    {
        try {
            $this->request('POST', '/reservations/'.rawurlencode($reservationId).'/release');
        } catch (InventoryException $exception) {
            Log::warning('inventory release failed', [
                'reservation_id' => $reservationId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->request('PUT', "/products/{$productId}/quantity", [
            'quantity' => $quantity,
        ]);
    }

    /**
     * @param  array<string, mixed>|null  $json
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $json = null): array
    {
        $url = rtrim($this->baseUrl, '/').$path;

        try {
            $pending = Http::timeout($this->timeoutSeconds)->acceptJson();

            $response = match ($method) {
                'GET' => $pending->get($url),
                'POST' => $pending->post($url, $json ?? []),
                'PUT' => $pending->put($url, $json ?? []),
                default => throw InventoryException::unavailable('Unsupported inventory HTTP method.'),
            };
        } catch (ConnectionException $exception) {
            throw InventoryException::unavailable($exception->getMessage());
        }

        if ($response->failed()) {
            $message = (string) ($response->json('message') ?? $response->body());

            throw InventoryException::unavailable($message !== '' ? $message : 'Inventory service error.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $response->json();

        if ($decoded['error'] ?? false) {
            throw InventoryException::unavailable((string) ($decoded['message'] ?? 'Inventory service error'));
        }

        return $decoded;
    }
}
