<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

/**
 * The one place that decides whether a custom table may enter (or resume) conversion.
 *
 * Blockers (any one forbids):
 *  - storage_not_installed : the storage columns of custom_tables are missing
 *  - system_table          : R6 - only business tables; system tables stay JSON
 *                            (system_flg, or a SystemTableName table name)
 *  - value_table_missing   : the table's value table does not exist
 *  - state_not_json        : conversion already in progress (resume of provisioning is allowed)
 *  - unsupported_column_type : a single-valued column has no physical type
 *  - plus every blocker of the physical schema plan (collisions, limits)
 * Warnings: driver not verified for physical storage; facts for later phases.
 * Dependencies: open product decisions the later phases depend on (recorded, not resolved).
 *
 * Whether conversion is offered at all is separate (ConversionAvailability).
 */
final class ConversionEligibility
{
    /**
     * Open decisions a converted table depends on (02 §9, §15.1, §15.11): none is resolved here.
     */
    public const DEPENDENCIES = [
        // O-5 (pcol_<suuid> kept after cutover) and the value_original format were decided 2026-11-25
        'D-3' => 'empty-filter semantics on typed columns',
        'D-6' => 'string collation of typed columns',
        'D-7' => 'plugins writing value->col directly',
        'D-8' => 'index cap per table and slow-screen threshold (L4)',
        'D-1/D-2' => 'decimal text form / Carbon object in the public value',
    ];

    /**
     * @param PhysicalSchemaPlan|null $plan pass the plan to include its blockers/warnings
     */
    public static function check(CustomTable $table, ?PhysicalSchemaPlan $plan = null): EligibilityResult
    {
        $blockers = [];
        $warnings = [];

        if (!TableStorage::installed()) {
            $blockers[] = ['code' => 'storage_not_installed', 'message' => 'run the migrations: custom_tables has no storage columns'];
            return new EligibilityResult($blockers, $warnings, self::DEPENDENCIES);
        }

        if (static::isSystemTable($table)) {
            $blockers[] = ['code' => 'system_table', 'message' => "R6: system table {$table->table_name} stays JSON"];
        }

        $db_table_name = getDBTableName($table);
        if (!\DB::connection()->getSchemaBuilder()->hasTable($db_table_name)) {
            $blockers[] = ['code' => 'value_table_missing', 'message' => "value table $db_table_name does not exist"];
        }

        $storage = TableStorage::fresh($table);
        if (!in_array($storage->state(), [StorageState::JSON, StorageState::PROVISIONED], true)) {
            $blockers[] = ['code' => 'state_not_json', 'message' => 'storage state is ' . $storage->state()];
        }

        foreach ($table->custom_columns_cache as $column) {
            if (!$column->isMultipleEnabled() && !PhysicalColumnType::isKnownColumnType((string)$column->column_type)) {
                $blockers[] = ['code' => 'unsupported_column_type', 'column' => $column->column_name,
                    'message' => "single-valued column type {$column->column_type} has no physical type"];
            }
        }

        $warnings = array_merge($warnings, static::driverWarnings());

        if ($plan) {
            $blockers = array_merge($blockers, $plan->blockers());
            $warnings = array_merge($warnings, $plan->warnings());
        }

        return new EligibilityResult(static::unique($blockers), static::unique($warnings), self::DEPENDENCIES);
    }

    /**
     * R6: system tables are never converted. system_flg first; the SystemTableName list as a
     * second line for a table whose flag was changed.
     */
    public static function isSystemTable(CustomTable $table): bool
    {
        if (boolval($table->system_flg)) {
            return true;
        }
        return in_array((string)$table->table_name, array_values(SystemTableName::toArray()), true);
    }

    /**
     * Physical storage has been measured on MariaDB 10.4.32 only (05, §15.9); MySQL 8 and SQL
     * Server are in scope (R5) but not verified.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function driverWarnings(): array
    {
        $family = PhysicalSchemaManager::driverFamily();
        if ($family === 'sqlsrv') {
            return [
                ['code' => 'driver_unverified', 'message' => 'SQL Server: implemented, not yet verified on an instance'],
                ['code' => 'sqlsrv_no_exment_backup', 'message' => 'SQL Server: Exment backup/restore is not available; take a database backup before converting'],
            ];
        }
        if ($family === 'mysql') {
            return [['code' => 'driver_unverified', 'message' => 'MySQL: implemented, not yet verified on MySQL 8']];
        }
        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private static function unique(array $items): array
    {
        $seen = [];
        $out = [];
        foreach ($items as $item) {
            $key = json_encode($item);
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $item;
            }
        }
        return $out;
    }
}
