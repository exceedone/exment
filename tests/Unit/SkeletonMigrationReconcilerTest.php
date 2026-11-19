<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Console\UpdateCommand;
use Exceedone\Exment\Services\Installer\SkeletonMigrationReconciler;
use Exceedone\Exment\Services\Installer\SkipExistingTablesSchema;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * exment:update on a database from an older Laravel skeleton (UP-05).
 *
 * A v6 backup restored into a fresh v7 install has `users` etc. under the 2014_* names, so
 * `migrate` failed on 0001_01_01_000000_create_users_table ("Table 'users' already exists")
 * before any Exment migration. SkeletonMigrationReconciler runs such a pending 0001_01_01_*
 * migration with the existing tables skipped.
 *
 * The tests use their own migration files and zz_rcn_* tables (DDL, so no transaction):
 * everything they make is removed in tearDown().
 */
class SkeletonMigrationReconcilerTest extends UnitTestBase
{
    /**
     * @var string temporary migration directory
     */
    protected $dir;

    /**
     * @var string unique part of the test table / migration names
     */
    protected $key;

    protected function setUp(): void
    {
        parent::setUp();

        $this->key = 'zz_rcn_' . substr(md5(uniqid('', true)), 0, 8);
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $this->key;
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*.php') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir);

        foreach (Schema::getTableListing() as $table) {
            if (str_starts_with($table, $this->key)) {
                Schema::drop($table);
            }
        }
        DB::table('migrations')->where('migration', 'like', '%' . $this->key . '%')->delete();

        parent::tearDown();
    }

    /**
     * All tables of the skeleton migration exist: nothing is recreated, the data stays,
     * the migration is recorded so that migrate does not run it.
     */
    public function testAllTablesExistRecordsMigrationAndKeepsData(): void
    {
        $users = $this->createTable('users', 3);
        $name = $this->makeMigration('0001_01_01_000000', [$users]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([$name => []], $result);
        $this->assertTrue($this->hasRun($name));
        $this->assertSame(3, DB::table($users)->count());
    }

    /**
     * Some tables exist: only the missing ones are created (like sessions next to a v6 users table).
     */
    public function testPartlyExistingCreatesOnlyMissingTables(): void
    {
        $users = $this->createTable('users', 2);
        $sessions = $this->key . '_sessions';
        $name = $this->makeMigration('0001_01_01_000000', [$users, $sessions]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([$name => [$sessions]], $result);
        $this->assertTrue(Schema::hasTable($sessions));
        $this->assertSame(2, DB::table($users)->count());
        $this->assertTrue($this->hasRun($name));
    }

    /**
     * No table exists (fresh database): left to migrate as before.
     */
    public function testNoExistingTableIsLeftToMigrate(): void
    {
        $cache = $this->key . '_cache';
        $name = $this->makeMigration('0001_01_01_000001', [$cache]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([], $result);
        $this->assertFalse(Schema::hasTable($cache));
        $this->assertFalse($this->hasRun($name));
    }

    /**
     * A skeleton migration already recorded (every normal v7 site) is not touched.
     */
    public function testAlreadyRunMigrationIsSkipped(): void
    {
        $users = $this->createTable('users', 1);
        $jobs = $this->key . '_jobs';
        $name = $this->makeMigration('0001_01_01_000002', [$users, $jobs]);
        DB::table('migrations')->insert(['migration' => $name, 'batch' => 1]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([], $result);
        $this->assertFalse(Schema::hasTable($jobs));
        $this->assertSame(1, DB::table('migrations')->where('migration', $name)->count());
    }

    /**
     * Only the 0001_01_01_* skeleton migrations are handled: any other pending migration
     * keeps failing on an existing table exactly as before.
     */
    public function testOtherMigrationsAreNotTouched(): void
    {
        $table = $this->createTable('other', 1);
        $missing = $this->key . '_other_missing';
        $name = $this->makeMigration('2026_10_22_000000', [$table, $missing]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([], $result);
        $this->assertFalse(Schema::hasTable($missing));
        $this->assertFalse($this->hasRun($name));
    }

    /**
     * Several pending skeleton migrations are handled one by one, in name order.
     */
    public function testSeveralSkeletonMigrations(): void
    {
        $users = $this->createTable('users', 1);
        $failed = $this->createTable('failed_jobs', 1);
        $sessions = $this->key . '_sessions';
        $cache = $this->key . '_cache';
        $jobs = $this->key . '_jobs';
        $n0 = $this->makeMigration('0001_01_01_000000', [$users, $sessions]);
        $n1 = $this->makeMigration('0001_01_01_000001', [$cache]);
        $n2 = $this->makeMigration('0001_01_01_000002', [$jobs, $failed]);

        $result = $this->reconciler()->reconcile();

        $this->assertSame([$n0 => [$sessions], $n2 => [$jobs]], $result);
        $this->assertTrue($this->hasRun($n0));
        $this->assertFalse($this->hasRun($n1), 'cache: nothing there, migrate creates it');
        $this->assertTrue($this->hasRun($n2));
        $this->assertSame(1, DB::table($failed)->count());
    }

    /**
     * The "db.schema" binding goes back to the normal builder, also when the migration fails.
     */
    public function testSchemaBindingIsRestoredWhenMigrationFails(): void
    {
        $users = $this->createTable('users', 1);
        $name = $this->makeMigration('0001_01_01_000000', [$users, $this->key . '_sessions'], 'throw new \RuntimeException("boom");');

        try {
            $this->reconciler()->reconcile();
            $this->fail('the migration error must not be hidden');
        } catch (\RuntimeException $ex) {
            $this->assertSame('boom', $ex->getMessage());
        }

        $this->assertInstanceOf(Builder::class, app('db.schema'));
        $this->assertNotInstanceOf(SkipExistingTablesSchema::class, Schema::getFacadeRoot());
        $this->assertFalse($this->hasRun($name));
    }

    /**
     * Without a migrations table (empty database) nothing is done.
     */
    public function testNothingWithoutMigrationRepository(): void
    {
        /** @var Migrator&\Mockery\MockInterface $migrator */
        $migrator = \Mockery::mock(Migrator::class);
        $migrator->shouldReceive('repositoryExists')->once()->andReturn(false);
        $migrator->shouldNotReceive('run');

        $this->assertSame([], (new SkeletonMigrationReconciler($migrator, $this->dir))->reconcile());
    }

    /**
     * The tables are read from the real Laravel 12 skeleton migrations.
     */
    public function testCreatedTablesOfTheLaravelSkeleton(): void
    {
        $reconciler = $this->reconciler();
        $expected = [
            '0001_01_01_000000_create_users_table.php' => ['users', 'password_reset_tokens', 'sessions'],
            '0001_01_01_000001_create_cache_table.php' => ['cache', 'cache_locks'],
            '0001_01_01_000002_create_jobs_table.php' => ['jobs', 'job_batches', 'failed_jobs'],
        ];
        $stub = base_path('vendor/laravel/laravel/database/migrations');
        $dir = is_dir($stub) ? $stub : database_path('migrations');
        foreach ($expected as $file => $tables) {
            if (!is_file($dir . '/' . $file)) {
                $this->markTestSkipped("$file not found in $dir");
            }
            $this->assertSame($tables, $reconciler->getCreatedTables($dir . '/' . $file), $file);
        }
    }

    /**
     * The wrapper skips an existing table and forwards every other call.
     */
    public function testSkipExistingTablesSchema(): void
    {
        $existing = $this->createTable('existing', 1);
        $new = $this->key . '_new';
        $schema = new SkipExistingTablesSchema(DB::connection()->getSchemaBuilder());

        $schema->create($existing, function ($table) {
            $table->id();
        });
        $schema->create($new, function ($table) {
            $table->id();
        });

        $this->assertSame(1, DB::table($existing)->count());
        $this->assertTrue($schema->hasTable($new));
        $this->assertTrue($schema->hasColumn($new, 'id'));
    }

    /**
     * exment:update runs the skeleton step right before migrate.
     */
    public function testUpdateCommandPreparesSkeletonMigrationsBeforeMigrate(): void
    {
        $command = new class () extends UpdateCommand {
            /** @var array<string> */
            public $calls = [];

            /**
             * @param \Symfony\Component\Console\Command\Command|string $command
             * @param array<mixed> $arguments
             */
            public function call($command, array $arguments = [])
            {
                $this->calls[] = is_string($command) ? $command : get_class($command);
                return 0;
            }

            protected function prepareSkeletonMigrations()
            {
                $this->calls[] = 'prepareSkeletonMigrations';
            }
        };
        $command->setLaravel(app());

        $command->initDatabase();

        $this->assertSame(['cache:clear', 'config:clear', 'route:clear', 'view:clear', 'prepareSkeletonMigrations', 'migrate'], $command->calls);
    }

    protected function reconciler(): SkeletonMigrationReconciler
    {
        return new SkeletonMigrationReconciler(app('migrator'), $this->dir);
    }

    /**
     * Create a test table with some rows.
     */
    protected function createTable(string $suffix, int $rows): string
    {
        $table = $this->key . '_' . $suffix;
        Schema::create($table, function ($t) {
            $t->id();
            $t->string('name')->nullable();
        });
        for ($i = 0; $i < $rows; $i++) {
            DB::table($table)->insert(['name' => "row$i"]);
        }

        return $table;
    }

    /**
     * Write a migration file that creates the given tables like the Laravel skeleton does.
     *
     * @param array<string> $tables
     * @return string migration name
     */
    protected function makeMigration(string $prefix, array $tables, string $after = ''): string
    {
        $name = $prefix . '_' . $this->key . '_' . count(glob($this->dir . '/*.php') ?: []);
        $creates = '';
        foreach ($tables as $table) {
            $creates .= "        Schema::create('$table', function (Blueprint \$table) {\n            \$table->id();\n        });\n";
        }
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . $name . '.php', <<<PHP
<?php

use Illuminate\\Database\\Migrations\\Migration;
use Illuminate\\Database\\Schema\\Blueprint;
use Illuminate\\Support\\Facades\\Schema;

return new class extends Migration
{
    public function up(): void
    {
$creates        $after
    }
};
PHP);

        return $name;
    }

    protected function hasRun(string $name): bool
    {
        return DB::table('migrations')->where('migration', $name)->exists();
    }
}
