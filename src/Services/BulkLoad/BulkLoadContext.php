<?php

namespace Exceedone\Exment\Services\BulkLoad;

/**
 * What a bulk load is about to write, as its caller knows it before the first write.
 *
 * The target (what will be written in this installation) is kept apart from the metadata found
 * in a backup (what the backup holds): a restore targets the whole database, files and .env of
 * this installation, whatever tables the backup lists. Data only: no storage flags or modes.
 */
final class BulkLoadContext
{
    public const OPERATION_BULK_INSERT = 'bulkinsert';
    public const OPERATION_RESTORE = 'restore';

    /** The target of a restore: the whole database of the connection (plus files and .env). */
    public const TARGET_DATABASE = 'database';

    /** @var string */
    private $operation;
    /** @var array<string, mixed> */
    private $target;
    /** @var array<string, mixed> */
    private $source;
    /** @var array<string, mixed> */
    private $backup;

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     * @param array<string, mixed> $backup
     */
    private function __construct(string $operation, array $target, array $source, array $backup)
    {
        $this->operation = $operation;
        $this->target = $target;
        $this->source = $source;
        $this->backup = $backup;
    }

    /**
     * One file of exment:bulkinsert: the custom table named by the file and its database table.
     */
    public static function bulkInsert(string $table_name, string $db_table_name, string $csv_path, string $tsv_path): self
    {
        return new self(self::OPERATION_BULK_INSERT, [
            'table_name' => $table_name,
            'db_table_name' => $db_table_name,
        ], [
            'csv' => $csv_path,
            'tsv' => $tsv_path,
        ], []);
    }

    /**
     * A restore of an unzipped backup into the connection's database.
     *
     * @param array<int, string> $backup_tables tables the backup holds data files for
     */
    public static function restore(string $connection, ?string $backup_file, string $directory, array $backup_tables, bool $has_definition): self
    {
        return new self(self::OPERATION_RESTORE, [
            'scope' => self::TARGET_DATABASE,
            'connection' => $connection,
        ], [
            'file' => $backup_file,
            'directory' => $directory,
        ], [
            'tables' => $backup_tables,
            'has_definition' => $has_definition,
        ]);
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * What will be written: ['table_name', 'db_table_name'] or ['scope' => 'database', 'connection'].
     *
     * @return array<string, mixed>
     */
    public function target(): array
    {
        return $this->target;
    }

    /**
     * Where the data comes from: ['csv', 'tsv'] or ['file', 'directory'].
     *
     * @return array<string, mixed>
     */
    public function source(): array
    {
        return $this->source;
    }

    /**
     * Restore only: what the backup holds ['tables', 'has_definition']; empty for bulkinsert.
     *
     * @return array<string, mixed>
     */
    public function backup(): array
    {
        return $this->backup;
    }
}
