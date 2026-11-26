<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\LogManagement\LogConnection;
use Illuminate\Console\Command;

/**
 * The log tables on the connection of the logs (ISS02905, EXMENT_LOG_DB_CONNECTION): makes the
 * tables there, and with --copy copies the rows the default connection has (the ones not copied
 * yet, by id). The rows of the default connection are left: drop them when the copy is checked.
 */
class LogDbCommand extends Command
{
    use CommandTrait;

    protected $signature = 'exment:log-db {--copy : copy the rows of the log tables of the default connection}';

    protected $description = 'Make the log tables on the connection of the logs (EXMENT_LOG_DB_CONNECTION), and copy their rows';

    /** The migrations of the log tables. */
    public const MIGRATIONS = [
        '2026_12_04_000000_create_login_logs.php',
        '2026_12_06_000000_create_permission_change_logs.php',
        '2026_12_07_000000_create_browser_logs.php',
        '2026_12_07_000001_create_data_access_logs.php',
        '2026_12_08_000000_create_mcp_logs.php',
    ];

    public const CHUNK = 1000;

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $connection = LogConnection::name();
        if (is_null($connection)) {
            $this->error('EXMENT_LOG_DB_CONNECTION is not set, or is not a connection of config/database.php.');
            return 1;
        }
        foreach (static::MIGRATIONS as $file) {
            $migration = require __DIR__ . '/../../database/migrations/' . $file;
            $migration->up();
        }
        $this->info("The log tables are on the connection \"{$connection}\".");

        if (!$this->option('copy')) {
            return 0;
        }
        $from = \DB::connection();
        $to = LogConnection::db();
        // the same database under another name: a copy would double the rows
        if ($from->getName() === $to->getName()
            || ($from->getDatabaseName() === $to->getDatabaseName() && strval($from->getConfig('host')) === strval($to->getConfig('host')) && strval($from->getConfig('port')) === strval($to->getConfig('port')) && $from->getTablePrefix() === $to->getTablePrefix())) {
            // an error: a script that copies must see that nothing was copied
            $this->error('The connection of the logs is the database of the data: nothing to copy.');
            return 1;
        }
        foreach (LogConnection::TABLES as $table) {
            if (!\Schema::hasTable($table) || !LogConnection::schema()->hasTable($table)) {
                continue;
            }
            $copied = 0;
            $last = intval(LogConnection::db()->table($table)->max('id'));
            do {
                $rows = $from->table($table)->where('id', '>', $last)->orderBy('id')->limit(static::CHUNK)->get();
                if ($rows->isEmpty()) {
                    break;
                }
                LogConnection::db()->table($table)->insert($rows->map(function ($row) {
                    return (array) $row;
                })->all());
                $last = intval($rows->last()->id);
                $copied += $rows->count();
            } while ($rows->count() === static::CHUNK);
            $this->info("{$table}: {$copied} rows copied");
        }
        return 0;
    }
}
