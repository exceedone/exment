<?php

namespace Exceedone\Exment\Database;

interface ConnectionInterface
{
    /**
     * Get database driver name
     *
     * @return string
     */
    public function getDatabaseDriverName(): string;

    /**
     * Check execute backup database
     *
     * @return bool
     */
    public function checkBackup(): bool;

    /**
     * Whether use unicode if search multiple column
     *
     * @return boolean
     */
    public function isUseUnicodeMultipleColumn(): bool;

    /**
     * Backup database
     *
     * @param string $tempDir dir path
     * @param callable|null $progress fn (array $info): void, after each table (ISS02943): total, table, exit_code, rows
     * @return void
     */
    public function backupDatabase($tempDir, ?callable $progress = null);


    /**
     * Restore database
     *
     * @param string $dirFullPath contains dir path
     * @param callable|null $progress fn (array $info): void, after each file (ISS02943): total, table, exit_code
     * @return void
     */
    public function restoreDatabase($dirFullPath, ?callable $progress = null);

    /**
     * Check a restore before the database is changed (the files to import, the client).
     *
     * @param string $dirFullPath contains dir path
     * @return void
     * @throws \Exceedone\Exment\Exceptions\RestoreFailedException nothing was restored
     */
    public function checkRestore($dirFullPath);

    /**
     * insert table data from backup tsv files.
     *
     * @param string $dirFullPath restore file path
     * @param callable|null $progress fn (array $info): void, after each file (ISS02943): total, table, rows
     */
    // @phpstan-ignore-next-line
    public function importTsv($dirFullPath, ?callable $progress = null);



    // @phpstan-ignore-next-line
    public function createView($viewName, $query);


    // @phpstan-ignore-next-line
    public function dropView($viewName);
}
