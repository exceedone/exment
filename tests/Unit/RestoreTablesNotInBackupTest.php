<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Database\MySqlConnection;

/**
 * Restore drops the tables the backup does not have (MySqlConnection::restoreDatabase()).
 *
 * Without it an older backup left the newer tables with the replaced data, and their
 * migrations no longer recorded: exment:update failed on create_line_account_links, and
 * line_account_links kept linking LINE accounts to user ids of other users.
 * Only which tables to drop is checked here: a restore would replace the test database.
 */
class RestoreTablesNotInBackupTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (!(\DB::connection() instanceof MySqlConnection)) {
            $this->markTestSkipped('restore of the database runs on MySQL / MariaDB only');
        }
    }

    /**
     * @return void
     */
    public function testDefinitionTablesAreTheCreatedTables()
    {
        $def = tempnam(sys_get_temp_dir(), 'exment_def');
        file_put_contents($def, implode("\n", [
            '-- Table structure for table `custom_tables`',
            'DROP TABLE IF EXISTS `custom_tables`;',
            'CREATE TABLE `custom_tables` (',
            '  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,',
            '  PRIMARY KEY (`id`)',
            ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;',
            'CREATE TABLE `exm__0123456789abcdef0123` (',
            '  `id` int(10) unsigned NOT NULL AUTO_INCREMENT',
            ');',
            'CREATE TABLE `odd``name` (',
            '  `id` int(10) unsigned NOT NULL',
            ');',
            '-- Temporary table structure for view `view_workflow_start`',
            'DROP TABLE IF EXISTS `view_workflow_start`;',
            '/*!50001 CREATE VIEW `view_workflow_start` AS SELECT',
            ' 1 AS `workflow_id` */;',
        ]));

        try {
            $tables = $this->callConnection('getDefinitionTables', [$def]);
        } finally {
            unlink($def);
        }

        $this->assertSame(['custom_tables', 'exm__0123456789abcdef0123', 'odd`name'], $tables);
    }

    /**
     * @return void
     */
    public function testTablesNotInTheBackupAreDropped()
    {
        $tables = $this->callConnection('getTablesNotInBackup', [
            ['migrations', 'custom_tables', 'login_users', 'exm__aaaaaaaaaaaaaaaaaaaa'],
            ['migrations', 'Custom_Tables', 'login_users', 'exm__aaaaaaaaaaaaaaaaaaaa', 'exm__bbbbbbbbbbbbbbbbbbbb', 'line_account_links', 'jobs'],
        ]);

        $this->assertSame(['exm__bbbbbbbbbbbbbbbbbbbb', 'line_account_links', 'jobs'], $tables);
    }

    /**
     * An empty or cut short dump cannot tell which tables to keep: nothing is dropped.
     *
     * @return void
     */
    public function testNothingIsDroppedForADumpWithoutTheExmentTables()
    {
        $tables = ['migrations', 'custom_tables', 'login_users', 'line_account_links'];

        $this->assertSame([], $this->callConnection('getTablesNotInBackup', [[], $tables]));
        $this->assertSame([], $this->callConnection('getTablesNotInBackup', [['migrations', 'login_users'], $tables]));
        $this->assertSame([], $this->callConnection('getTablesNotInBackup', [['custom_tables', 'login_users'], $tables]));
    }

    /**
     * @param string $method
     * @param array<mixed> $args
     * @return mixed
     */
    protected function callConnection(string $method, array $args)
    {
        $reflection = new \ReflectionMethod(MySqlConnection::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs(\DB::connection(), $args);
    }
}
