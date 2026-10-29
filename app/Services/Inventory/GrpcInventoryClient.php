<?php

namespace App\Services\Inventory;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class GrpcInventoryClient implements InventoryClientInterface
{
    public function __construct(
        private readonly string $nodeBinary,
        private readonly string $clientScript,
        private readonly string $grpcHost,
        private readonly int $timeoutSeconds,
    ) {}

    public function getQuantity(int $productId): int
    {
        $result = $this->run(['get', (string) $productId]);

        return (int) ($result['available'] ?? 0);
    }

    public function reserve(string $reservationId, array $items): bool
    {
        $result = $this->run([
            'reserve',
            $reservationId,
            json_encode($items, JSON_THROW_ON_ERROR),
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
            $this->run(['release', $reservationId]);
        } catch (InventoryException $exception) {
            Log::warning('inventory release failed', [
                'reservation_id' => $reservationId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function setQuantity(int $productId, int $quantity): void
    {
        $this->run(['set', (string) $productId, (string) $quantity]);
    }

    /**
     * @param  list<string>  $arguments
     * @return array<string, mixed>
     */
    private function run(array $arguments): array
    {
        $process = new Process(
            array_merge([$this->nodeBinary, $this->clientScript], $arguments),
            base_path('inventory-service'),
            [
                'INVENTORY_GRPC_HOST' => $this->grpcHost,
                'INVENTORY_TIMEOUT_MS' => (string) ($this->timeoutSeconds * 1000),
                'PROTO_PATH' => base_path('proto/inventory.proto'),
            ],
        );

        $process->setTimeout($this->timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw InventoryException::unavailable('Inventory service timed out.');
        }

        $output = trim($process->getOutput());

        if ($output === '') {
            throw InventoryException::unavailable('Inventory service returned an empty response.');
        }

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

        if ($decoded['error'] ?? false) {
            $message = (string) ($decoded['message'] ?? 'Inventory service error');
            $code = strtoupper((string) ($decoded['code'] ?? ''));

            if (in_array($code, ['UNAVAILABLE', 'DEADLINE_EXCEEDED', 'UNKNOWN'], true)) {
                throw InventoryException::unavailable($message);
            }

            if ($code === 'INVALID_ARGUMENT') {
                throw InventoryException::rejected($message);
            }

            throw InventoryException::unavailable($message);
        }

        if (! $process->isSuccessful()) {
            throw InventoryException::unavailable(
                trim($process->getErrorOutput()) ?: 'Inventory service is unavailable.',
            );
        }

        return $decoded;
    }
}
