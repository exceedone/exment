<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Database\MySqlConnection;

/**
 * Restore drops the Exment tables the backup does not have (MySqlConnection::restoreDatabase()).
 *
 * Without it an older backup left the newer tables with the replaced data, and their
 * migrations no longer recorded: exment:update failed on create_line_account_links, and
 * line_account_links kept linking LINE accounts to user ids of other users.
 *
 * Only Exment-owned tables are dropped: a backup predating a newer Laravel skeleton must keep
 * the framework tables it does not have (sessions, cache, jobs, ...), or restoring takes the
 * whole site down. Only which tables to drop is checked here: a restore would replace the
 * test database.
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
     * Only Exment-owned tables the backup does not have are dropped (exm__, pivot__, and the
     * tables Exment's migrations create). The restored tables and non-Exment tables are kept.
     *
     * @return void
     */
    public function testOnlyExmentTablesNotInTheBackupAreDropped()
    {
        $tables = $this->callConnection('getTablesNotInBackup', [
            // the backup (an older Exment, no line_account_links yet)
            ['migrations', 'custom_tables', 'login_users', 'exm__aaaaaaaaaaaaaaaaaaaa'],
            // the database being replaced
            [
                'migrations', 'Custom_Tables', 'login_users',
                'exm__aaaaaaaaaaaaaaaaaaaa',                       // restored  -> keep
                'exm__bbbbbbbbbbbbbbbbbbbb',                       // newer custom table -> drop
                'pivot__aaaaaaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbb', // newer relation pivot -> drop
                'line_account_links',                             // newer Exment table -> drop
                'meili_saved_searches',                           // newer Exment table ($schema->create) -> drop
                'cell_style_presets',                             // newer Exment table ($schema->create) -> drop
            ],
        ]);

        $this->assertSame([
            'exm__bbbbbbbbbbbbbbbbbbbb',
            'pivot__aaaaaaaaaaaaaaaaaaaa_bbbbbbbbbbbbbbbbbbbb',
            'line_account_links',
            'meili_saved_searches',
            'cell_style_presets',
        ], $tables);
    }

    /**
     * The Exment tables are read from the real migrations, whichever way a migration names the
     * table: a table the list misses keeps the replaced data after a restore (meili_saved_searches
     * would show the saved searches of other users).
     *
     * @return void
     */
    public function testExmentTablesAreReadFromEveryKindOfMigration()
    {
        $tables = $this->callConnection('getExmentTableNames', []);

        foreach ([
            'line_account_links',   // Schema::create('...')
            'email_code_verifies',  // Schema::create(SystemTableName::...)
            'custom_tables',        // $schema->create('...')
            'role_groups',          // $schema->create(SystemTableName::...)
            // the tables v7 added
            'meili_filter_settings',
            'meili_saved_searches',
            'meili_dictionaries',
            'cell_style_presets',
        ] as $table) {
            $this->assertContains($table, $tables);
        }

        // framework tables Exment shares (or creates only when the host application did not)
        foreach (['migrations', 'password_reset_tokens', 'sessions', 'jobs', 'users'] as $table) {
            $this->assertNotContains($table, $tables);
        }
    }

    /**
     * A backup predating a newer Laravel skeleton does not have its framework tables; those,
     * and any table Exment does not own, are kept so the site keeps working after restore.
     *
     * @return void
     */
    public function testFrameworkAndNonExmentTablesAreKept()
    {
        $tables = $this->callConnection('getTablesNotInBackup', [
            // a v6 backup: no sessions / cache / jobs (they arrived with Laravel 12)
            ['migrations', 'custom_tables', 'login_users'],
            [
                'migrations', 'custom_tables', 'login_users',
                'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', // Laravel 12 skeleton
                'users', 'failed_jobs', 'password_reset_tokens',           // other framework tables
                'my_own_table',                                            // table added outside Exment
            ],
        ]);

        $this->assertSame([], $tables);
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
