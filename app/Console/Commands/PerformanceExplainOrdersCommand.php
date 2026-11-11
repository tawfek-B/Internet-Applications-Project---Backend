<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PerformanceExplainOrdersCommand extends Command
{
    protected $signature = 'performance:explain-orders {user_id=1}';

    protected $description = 'Run EXPLAIN on the user orders listing query (read connection).';

    public function handle(): int
    {
        $userId = (int) $this->argument('user_id');

        $connection = DB::connection();
        $configuredHost = $connection->getConfig('read.host') ?? $connection->getConfig('host');

        while (is_array($configuredHost)) {
            $configuredHost = $configuredHost['host'] ?? reset($configuredHost);
        }

        $this->line('Using read host: '.(string) $configuredHost);

        $plan = $connection->select(
            'EXPLAIN SELECT orders.* FROM orders
                INNER JOIN order_items ON order_items.order_id = orders.id
                WHERE orders.user_id = ?
                ORDER BY orders.created_at DESC
                LIMIT 15',
            [$userId],
        );

        $columns = ['id', 'select_type', 'table', 'type', 'possible_keys', 'key', 'rows', 'Extra'];

        $this->table(
            $columns,
            collect($plan)->map(function (object $row) use ($columns): array {
                $values = (array) $row;

                return array_map(
                    fn (string $column): string => (string) ($values[$column] ?? ''),
                    $columns,
                );
            })->all(),
        );

        return self::SUCCESS;
    }
}
