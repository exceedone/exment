<?php

namespace Exceedone\Exment\Services\Installer;

use Illuminate\Database\Migrations\Migrator;

/**
 * Lets exment:update run on a database that came from an older Laravel skeleton.
 *
 * Laravel 11+ ships its default migrations as 0001_01_01_* (users, cache, jobs...), while a
 * v6 (Laravel 10) database already has those tables under the 2014_* / 2019_* names. After
 * restoring a v6 backup into a fresh v7 install, `migrate` would try to create `users` again
 * and stop before any Exment migration.
 *
 * For each pending 0001_01_01_* migration of the application that finds at least one of its
 * tables already there, the migration is run on its own with the existing tables skipped:
 * the missing tables (sessions, jobs...) are created and the migration is recorded as run.
 * A migration whose tables all are missing is left to `migrate` as usual.
 */
class SkeletonMigrationReconciler
{
    /**
     * File name prefix of the Laravel 11+ skeleton migrations.
     */
    public const PREFIX = '0001_01_01_';

    /**
     * @var Migrator
     */
    protected $migrator;

    /**
     * @var string directory of the application migrations
     */
    protected $path;

    public function __construct(Migrator $migrator, ?string $path = null)
    {
        $this->migrator = $migrator;
        $this->path = $path ?? database_path('migrations');
    }

    /**
     * Run the pending skeleton migrations whose tables partly or fully exist.
     *
     * @return array<string, array<string>> migration name => tables it created
     */
    public function reconcile(): array
    {
        if (!$this->migrator->repositoryExists()) {
            return [];
        }

        $ran = $this->migrator->getRepository()->getRan();
        $schema = \DB::connection($this->migrator->getConnection())->getSchemaBuilder();

        $result = [];
        foreach ($this->migrator->getMigrationFiles([$this->path]) as $name => $file) {
            if (!str_starts_with($name, static::PREFIX) || in_array($name, $ran, true)) {
                continue;
            }

            $tables = $this->getCreatedTables($file);
            $missing = array_values(array_filter($tables, fn ($table) => !$schema->hasTable($table)));
            // nothing known, or nothing there yet: a normal migrate creates it
            if (empty($tables) || count($missing) === count($tables)) {
                continue;
            }

            $this->runSkippingExistingTables($file, $schema);
            $result[$name] = $missing;
        }

        return $result;
    }

    /**
     * Get the table names a migration file creates with Schema::create().
     *
     * @return array<string>
     */
    public function getCreatedTables(string $file): array
    {
        preg_match_all('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', (string) file_get_contents($file), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Run one migration while Schema::create() leaves the existing tables as they are.
     *
     * @param \Illuminate\Database\Schema\Builder $schema
     */
    protected function runSkippingExistingTables(string $file, $schema): void
    {
        $app = app();
        $app->instance('db.schema', new SkipExistingTablesSchema($schema));

        try {
            $this->migrator->run([$file]);
        } finally {
            // back to the binding of DatabaseServiceProvider
            $app->forgetInstance('db.schema');
        }
    }
}
