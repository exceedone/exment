<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

/**
 * The hasTable() helper answers from the connection's own schema builder, so
 * it works on whichever driver is configured. It used to open a separate
 * Doctrine pdo_mysql connection, which fails on SQL Server (and on MySQL
 * reached through a socket or required SSL) and then reported every table as
 * missing.
 */
class HasTableHelperTest extends UnitTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testFindsATableThatExists()
    {
        $this->assertTrue(hasTable(SystemTableName::CUSTOM_TABLE));
        $this->assertTrue(hasTable(SystemTableName::SYSTEM));
    }

    /**
     * @return void
     */
    public function testDoesNotFindATableThatDoesNotExist()
    {
        $this->assertFalse(hasTable('no_such_table_for_has_table_helper'));
    }

    /**
     * hasTable() compares the names as the helper receives them, so the list it
     * reads must hold plain table names. Laravel's own getTableListing() would
     * return schema qualified ones ("dbo.users" on SQL Server); it is Exment's
     * builder that returns plain names. Pinned here because nothing else would
     * notice that override going away on a driver this suite does not run on.
     *
     * @return void
     */
    public function testTheSchemaBuilderIsExmentsAndListsPlainNames()
    {
        $this->assertContains(
            \Exceedone\Exment\Database\Schema\BuilderTrait::class,
            class_uses_recursive(\Schema::getFacadeRoot()),
            'The default connection must use Exment\'s schema builder.'
        );

        $listed = \Schema::getTableListing();
        $this->assertContains(SystemTableName::CUSTOM_TABLE, $listed);
        foreach ($listed as $name) {
            $this->assertIsString($name);
            $this->assertStringNotContainsString('.', $name, 'Table names must not be schema qualified.');
        }
    }

    /**
     * The three drivers Exment supports must all reach that same listing:
     * MariaDB through MySQL's builder and grammar, SQL Server through its own.
     *
     * @return void
     */
    public function testEveryDriverGetsAnExmentBuilderWithATableListing()
    {
        $this->assertTrue(is_subclass_of(
            \Exceedone\Exment\Database\Schema\MariaDBBuilder::class,
            \Exceedone\Exment\Database\Schema\MySqlBuilder::class
        ));
        $this->assertTrue(is_subclass_of(
            \Exceedone\Exment\Database\Schema\Grammars\MariaDBGrammar::class,
            \Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar::class
        ));

        foreach ([
            \Exceedone\Exment\Database\Schema\MySqlBuilder::class,
            \Exceedone\Exment\Database\Schema\SqlServerBuilder::class,
        ] as $builder) {
            $this->assertContains(\Exceedone\Exment\Database\Schema\BuilderTrait::class, class_uses_recursive($builder), $builder);
        }

        foreach ([
            \Exceedone\Exment\Database\Schema\Grammars\MySqlGrammar::class,
            \Exceedone\Exment\Database\Schema\Grammars\SqlServerGrammar::class,
        ] as $grammar) {
            $this->assertTrue(method_exists($grammar, 'compileGetTableListing'), $grammar);
        }
    }

    /**
     * Same answer as the schema builder gives, so a driver the helper used to
     * fail on cannot make the two disagree.
     *
     * @return void
     */
    public function testAgreesWithTheSchemaBuilder()
    {
        $this->assertSame(\Schema::hasTable(SystemTableName::CUSTOM_TABLE), hasTable(SystemTableName::CUSTOM_TABLE));
        $this->assertSame(
            \Schema::hasTable('no_such_table_for_has_table_helper'),
            hasTable('no_such_table_for_has_table_helper')
        );

        $listed = System::requestSession(Define::SYSTEM_KEY_SESSION_ALL_DATABASE_TABLE_NAMES);
        $this->assertNotEmpty($listed, 'The cached table list must not be the empty fallback.');
    }
}
