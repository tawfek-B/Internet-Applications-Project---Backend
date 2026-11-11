<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BootstrapMysqlReadReplicaCommand extends Command
{
    protected $signature = 'db:bootstrap-read-replica';

    protected $description = 'Create replication user on primary (run once after compose up).';

    public function handle(): int
    {
        DB::connection('mysql')->statement("
            CREATE USER IF NOT EXISTS 'replicator'@'%' IDENTIFIED BY 'replicator'
        ");

        DB::connection('mysql')->statement('
            GRANT REPLICATION SLAVE ON *.* TO \'replicator\'@\'%\'
        ');

        DB::connection('mysql')->statement('FLUSH PRIVILEGES');

        $this->info('Replication user ready on primary (mysql).');
        $this->line('On mysql-read container, configure replica (see docker/mysql/README-replica.txt).');

        return self::SUCCESS;
    }
}
