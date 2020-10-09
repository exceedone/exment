<?php

namespace Exceedone\Exment\Database;

interface ConnectionInterface
{
    /**
     * Get database driver name
     *
     * @return string
     */
    public function getDatabaseDriverName() : string;

    /**
     * Check execute backup database
     *
     * @return bool
     * @throws BackupRestoreCheckException
     */
    public function checkBackup() : bool;

    
    /**
     * Restore database
     *
     * @param string $tempDir dir path
     * @return void
     */
    public function backupDatabase($tempDir);
    

    /**
     * Restore database
     *
     * @param string $dirFullPath contains dir path
     * @return void
     */
    public function restoreDatabase($dirFullPath);
    
    /**
     * insert table data from backup tsv files.
     *
     * @param string $dirFullPath restore file path
     */
    public function importTsv($dirFullPath);



    public function createView($viewName, $query);


    public function dropView($viewName);
}
