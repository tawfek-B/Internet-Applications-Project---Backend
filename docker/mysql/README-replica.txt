Read replica (mysql-read) — one-time setup after `docker compose up`:

1. Primary (mysql) must expose binlog: --server-id=1 --log-bin=mysql-bin
2. Create replicator user (done via 15-replication-user.sql or):
   docker compose exec laravel.test php artisan db:bootstrap-read-replica
3. On mysql-read, clone data and start replication (MySQL 8 syntax), e.g.:
   mysqldump -h mysql -uroot laravel | mysql -h 127.0.0.1 -uroot laravel
   CHANGE REPLICATION SOURCE TO SOURCE_HOST='mysql', SOURCE_USER='replicator', SOURCE_PASSWORD='replicator', SOURCE_AUTO_POSITION=1;
   START REPLICA;

Laravel uses DB_WRITE_HOST=mysql, DB_READ_HOST=mysql-read with sticky=true so the creator sees their new rows immediately.
