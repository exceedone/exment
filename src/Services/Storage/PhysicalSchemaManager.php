<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\StorageMode;
use Exceedone\Exment\Enums\StorageState;
use Exceedone\Exment\Exceptions\StorageConversionException;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

/**
 * Physical schema of a converted table (Stage B block 1): plan, provision, deprovision of the
 * typed staging columns of single-valued columns (02 §3, §6, §13.4 "provisioned", §15.7).
 *
 * Scope and rules
 *  - Only `pcol_<suuid>` staging columns (PhysicalNaming) are ever created or dropped. Existing
 *    columns - in particular the generated `column_<suuid>` index columns of JSON mode - are
 *    never dropped, replaced or renamed; a name collision is a blocker, reported in the plan.
 *  - Multi-valued columns get nothing (they stay in JSON, R2).
 *  - Columns are added nullable, without default and without index (indexes come with the
 *    physical-read phase, §15.9); no value_original column yet (its format is open).
 *  - Never called from a read/query path. No command or screen calls it yet: provisioning
 *    requires ConversionAvailability, which is unavailable in production.
 *  - DDL auto-commits on MySQL/MariaDB: every step is idempotent and re-checks the live schema;
 *    a failed run leaves the table 'provisioned' and is resumed by calling provision() again.
 */
class PhysicalSchemaManager
{
    /** MySQL/MariaDB InnoDB: max row size of non-LOB data; max columns; max secondary indexes; max key bytes (DYNAMIC). */
    public const MYSQL_MAX_ROW_BYTES = 65535;
    public const MYSQL_MAX_COLUMNS = 1017;
    public const MYSQL_MAX_INDEXES = 64;
    public const MYSQL_MAX_KEY_BYTES = 3072;
    /** SQL Server: max fixed-length in-row bytes; max columns; max nonclustered indexes; max nonclustered key bytes. */
    public const SQLSRV_MAX_FIXED_ROW_BYTES = 8060;
    public const SQLSRV_MAX_COLUMNS = 1024;
    public const SQLSRV_MAX_INDEXES = 999;
    public const SQLSRV_MAX_KEY_BYTES = 1700;

    /** @var ConversionAvailability */
    private $availability;

    public function __construct(ConversionAvailability $availability)
    {
        $this->availability = $availability;
    }

    /** 'mysql' (MySQL and MariaDB) or 'sqlsrv'. */
    public static function driverFamily(): string
    {
        $driver = DB::connection()->getDriverName();
        if ($driver === 'sqlsrv') {
            return 'sqlsrv';
        }
        if ($driver === 'mariadb') {
            return 'mariadb';
        }
        // Exment may run MariaDB through the mysql driver: ask the server once per process
        if (!isset(static::$familyOfMysqlDriver)) {
            try {
                static::$familyOfMysqlDriver = \Schema::isMariaDB() ? 'mariadb' : 'mysql';
            } catch (\Throwable $ex) {
                return 'mysql';
            }
        }
        return static::$familyOfMysqlDriver;
    }

    /** @var string|null */
    private static $familyOfMysqlDriver;

    // ------------------------------------------------------------------ plan

    /**
     * Plan the staging columns of a table against the live schema. Reads only; no DDL.
     */
    public function plan(CustomTable $table): PhysicalSchemaPlan
    {
        $db_table_name = getDBTableName($table);
        $schema = DB::connection()->getSchemaBuilder();
        if (!$schema->hasTable($db_table_name)) {
            return new PhysicalSchemaPlan($db_table_name, [], [['code' => 'value_table_missing', 'message' => "value table $db_table_name does not exist"]], [], []);
        }
        $family = static::driverFamily();
        $isSqlServer = $family === 'sqlsrv';

        $schemaColumns = [];
        foreach ($schema->getColumns($db_table_name) as $c) {
            $schemaColumns[(string)$c['name']] = $c;
        }

        $columns = [];
        $blockers = [];
        $warnings = [];
        $ownStaging = [];
        $newRowBytes = 0;
        $newSqlsrvFixed = 0;
        $newInRowBytes = 0;

        foreach ($table->custom_columns_cache as $column) {
            $type = PhysicalColumnType::of($column);
            $entry = [
                'column_id' => (int)$column->id,
                'column_name' => (string)$column->column_name,
                'column_type' => (string)$column->column_type,
                'physical_type' => $type,
                'staging_column' => null,
                'action' => null,
                'virtual_column' => null,
                'indexable' => false,
                'index_name' => null,
                'reasons' => [],
            ];

            if ($column->isMultipleEnabled()) {
                $entry['action'] = 'json';
                $entry['reasons'][] = 'multi-valued: stays in JSON (R2)';
                $columns[] = $entry;
                continue;
            }
            if ($type === null) {
                $entry['action'] = 'unsupported';
                $blockers[] = ['code' => 'unsupported_column_type', 'column' => $column->column_name,
                    'message' => "single-valued column type {$column->column_type} has no physical type"];
                $columns[] = $entry;
                continue;
            }

            try {
                $staging = PhysicalNaming::stagingColumn($column);
                $virtual = PhysicalNaming::virtualColumn($column);
                $entry['index_name'] = PhysicalNaming::stagingIndex($column);
            } catch (\InvalidArgumentException $ex) {
                $entry['action'] = 'blocked';
                $blockers[] = ['code' => 'unsafe_identifier', 'column' => $column->column_name, 'message' => $ex->getMessage()];
                $columns[] = $entry;
                continue;
            }
            $entry['staging_column'] = $staging;
            $ownStaging[$staging] = true;

            // the JSON-mode generated index column: reported, never touched
            $virtualColumn = $schemaColumns[$virtual] ?? null;
            $entry['virtual_column'] = [
                'name' => $virtual,
                'exists' => $virtualColumn !== null,
                'generated' => $virtualColumn !== null && !empty($virtualColumn['generation']),
            ];
            if ($virtualColumn !== null && empty($virtualColumn['generation'])) {
                $warnings[] = ['code' => 'virtual_name_is_physical_column', 'column' => $column->column_name,
                    'message' => "$virtual exists and is not a generated column; it is not touched (the typed column is $staging)"];
            }

            $existing = $schemaColumns[$staging] ?? null;
            if ($existing === null) {
                $entry['action'] = 'add';
                $newRowBytes += PhysicalColumnType::rowBytes($type);
                $newInRowBytes += PhysicalColumnType::inRowBytes($type);
                $newSqlsrvFixed += PhysicalColumnType::sqlServerFixedBytes($type);
            } elseif (!empty($existing['generation'])) {
                $entry['action'] = 'blocked';
                $blockers[] = ['code' => 'staging_name_is_generated_column', 'column' => $column->column_name,
                    'message' => "$staging exists as a generated column; it is not dropped or replaced"];
            } elseif (!PhysicalColumnType::matches($type, $existing, $family)) {
                $entry['action'] = 'blocked';
                $blockers[] = ['code' => 'staging_name_taken', 'column' => $column->column_name,
                    'message' => "$staging exists with type " . ($existing['type'] ?? '?') . ", expected $type; it is not altered"];
            } elseif (!($existing['nullable'] ?? true)) {
                $entry['action'] = 'blocked';
                $blockers[] = ['code' => 'staging_column_not_nullable', 'column' => $column->column_name,
                    'message' => "$staging exists NOT NULL; staging columns are nullable"];
            } else {
                $entry['action'] = 'exists';
            }

            $keyBytes = PhysicalColumnType::keyBytes($type);
            $entry['indexable'] = $keyBytes !== null && $keyBytes <= ($isSqlServer ? self::SQLSRV_MAX_KEY_BYTES : self::MYSQL_MAX_KEY_BYTES);
            $columns[] = $entry;
        }

        // value_original: one JSON column (project convention: Blueprint json(), MariaDB adds
        // CHECK JSON_VALID); an incompatible existing column is a blocker, never altered
        $valueOriginal = $this->planValueOriginal($schemaColumns[PhysicalNaming::VALUE_ORIGINAL] ?? null, $family, $blockers);
        if ($valueOriginal['action'] === 'add') {
            $newRowBytes += 12;
            $newInRowBytes += 40;
        }

        // staging columns that no current column owns (e.g. left by an earlier run): reported, kept
        foreach (array_keys($schemaColumns) as $name) {
            if (PhysicalNaming::isStagingName($name) && !isset($ownStaging[$name])) {
                $warnings[] = ['code' => 'orphan_staging_column', 'message' => "$name has no current column; it is not dropped"];
            }
        }
        // O-5 decided (2026-11-25): the typed column keeps pcol_<suuid> after cutover, so the
        // generated column_<suuid> index columns stay as they are - no naming warning. They still
        // count toward the row-size limit [ĐO MariaDB 10.4.32: error 1118 when exceeded].

        $limits = $this->checkLimits($db_table_name, $schemaColumns, count(array_filter($columns, function ($c) {
            return $c['action'] === 'add';
        })) + ($valueOriginal['action'] === 'add' ? 1 : 0), $newRowBytes, $newSqlsrvFixed, $family, $blockers, $warnings, $newInRowBytes);

        return new PhysicalSchemaPlan($db_table_name, $columns, $blockers, $warnings, $limits, $valueOriginal);
    }

    /**
     * @param array<string, mixed>|null $existing Schema::getColumns() entry of value_original
     * @param array<int, array<string, mixed>> $blockers
     * @return array{name: string, action: string}
     */
    private function planValueOriginal(?array $existing, string $family, array &$blockers): array
    {
        $name = PhysicalNaming::VALUE_ORIGINAL;
        if ($existing === null) {
            return ['name' => $name, 'action' => 'add'];
        }
        if (!empty($existing['generation'])) {
            $blockers[] = ['code' => 'value_original_incompatible', 'message' => "$name exists as a generated column; it is not dropped or replaced"];
            return ['name' => $name, 'action' => 'blocked'];
        }
        if (!static::isJsonColumn($existing, $family)) {
            $blockers[] = ['code' => 'value_original_incompatible', 'message' => "$name exists with type " . ($existing['type'] ?? '?') . ', expected JSON; it is not altered'];
            return ['name' => $name, 'action' => 'blocked'];
        }
        if (!($existing['nullable'] ?? true)) {
            $blockers[] = ['code' => 'value_original_incompatible', 'message' => "$name exists NOT NULL; it must be nullable"];
            return ['name' => $name, 'action' => 'blocked'];
        }
        return ['name' => $name, 'action' => 'exists'];
    }

    /**
     * Whether a schema column is what Blueprint json() creates: MySQL `json`; MariaDB `longtext`
     * (JSON is an alias, with CHECK JSON_VALID); SQL Server `nvarchar(max)` [CHƯA KIỂM CHỨNG for
     * the reported names on MySQL 8 and SQL Server].
     *
     * @param array<string, mixed> $c
     */
    public static function isJsonColumn(array $c, string $family): bool
    {
        $typeName = strtolower((string)($c['type_name'] ?? ''));
        $type = strtolower(str_replace(' ', '', (string)($c['type'] ?? '')));
        if ($family === 'sqlsrv') {
            return $typeName === 'nvarchar' && strpos($type, '(max)') !== false;
        }
        return $typeName === 'json' || $typeName === 'longtext';
    }

    /**
     * Column count, row size and index capacity after adding the planned columns.
     *
     * @param array<string, array<string, mixed>> $schemaColumns
     * @param array<int, array<string, mixed>> $blockers
     * @param array<int, array<string, mixed>> $warnings
     * @return array<string, mixed>
     */
    private function checkLimits(string $db_table_name, array $schemaColumns, int $added, int $newRowBytes, int $newSqlsrvFixed, string $family, array &$blockers, array &$warnings, int $newInRowBytes = 0): array
    {
        $isSqlServer = $family === 'sqlsrv';
        $columnCount = count($schemaColumns) + $added;
        $maxColumns = $isSqlServer ? self::SQLSRV_MAX_COLUMNS : self::MYSQL_MAX_COLUMNS;
        if ($columnCount > $maxColumns) {
            $blockers[] = ['code' => 'too_many_columns', 'message' => "$columnCount columns after provisioning, limit $maxColumns"];
        }

        $limits = ['driver' => $family, 'columns_after' => $columnCount, 'columns_max' => $maxColumns];

        if ($isSqlServer) {
            $fixed = $newSqlsrvFixed;
            foreach ($schemaColumns as $c) {
                $fixed += static::sqlServerFixedBytesOf($c);
            }
            $limits['fixed_row_bytes_after'] = $fixed;
            $limits['fixed_row_bytes_max'] = self::SQLSRV_MAX_FIXED_ROW_BYTES;
            if ($fixed > self::SQLSRV_MAX_FIXED_ROW_BYTES) {
                $blockers[] = ['code' => 'row_size_exceeded', 'message' => "fixed in-row size $fixed bytes after provisioning, limit " . self::SQLSRV_MAX_FIXED_ROW_BYTES];
            }
        } else {
            $bytes = $newRowBytes;
            $unknown = [];
            foreach ($schemaColumns as $name => $c) {
                $b = static::mysqlRowBytesOf($c);
                if ($b === null) {
                    $unknown[] = $name;
                    $b = 8;
                }
                $bytes += $b;
            }
            $limits['row_bytes_after'] = $bytes;
            $limits['row_bytes_max'] = self::MYSQL_MAX_ROW_BYTES;
            // generated (virtual) columns are counted too: whether InnoDB counts them toward the
            // row-size limit is not verified, so the estimate is conservative [CHƯA KIỂM CHỨNG]
            $limits['row_bytes_counts_generated'] = true;
            if ($bytes > self::MYSQL_MAX_ROW_BYTES) {
                // proposed initial-release restriction (not an approved decision): such a table is
                // not convertible; nothing is removed, retyped or reformatted to make it fit
                $blockers[] = ['code' => 'row_size_exceeded', 'message' => "estimated row size $bytes bytes after provisioning exceeds the database limit of " . self::MYSQL_MAX_ROW_BYTES
                    . ' bytes (existing generated index columns and character sets included); this table cannot be converted in this release'];
            }
            if (!empty($unknown)) {
                $warnings[] = ['code' => 'row_size_estimate_unknown_type', 'message' => 'estimated as 8 bytes: ' . implode(', ', $unknown)];
            }

            // second limit: the InnoDB record must fit in half a page (DYNAMIC: short columns
            // inline, long ones as pointers; virtual columns not stored). Calibrated on MariaDB
            // 10.4.32 / 16K pages [ĐO]; the documented limit (page/2 - 66) is below every observed
            // boundary, so the check never allows an ALTER the engine rejected in the sweeps.
            $rowFormat = static::rowFormat($db_table_name);
            $limits['row_format'] = $rowFormat;
            if ($rowFormat !== null && !in_array(strtolower($rowFormat), ['dynamic', 'compressed'], true)) {
                $blockers[] = ['code' => 'row_format_unsupported', 'message' => "row format $rowFormat: the in-record size estimate is only established for DYNAMIC; this table cannot be converted in this release"];
            }
            $inRow = $newInRowBytes;
            $stored = 0;
            foreach ($schemaColumns as $c) {
                if (!empty($c['generation']) && strtolower((string)($c['generation']['type'] ?? '')) === 'virtual') {
                    continue;
                }
                $stored++;
                $inRow += static::innodbInRowBytesOf($c);
            }
            $stored += $added;
            $inRowEstimate = (int)ceil($inRow + $stored * 0.5);
            $inRowLimit = static::innodbInRowLimit();
            $limits['inrow_bytes_after'] = $inRowEstimate;
            $limits['inrow_bytes_max'] = $inRowLimit;
            if ($inRowEstimate > $inRowLimit) {
                $blockers[] = ['code' => 'inrow_size_exceeded', 'message' => "estimated InnoDB record size $inRowEstimate bytes after provisioning exceeds the in-page limit of $inRowLimit bytes (short text columns of the table counted in full); this table cannot be converted in this release"];
            }
        }

        // index capacity: informational for the index phase (no index is created here)
        try {
            $indexes = DB::connection()->getSchemaBuilder()->getIndexes($db_table_name);
            $secondary = count(array_filter($indexes, function ($i) {
                return empty($i['primary']);
            }));
        } catch (\Throwable $ex) {
            $secondary = null;
        }
        $maxIndexes = $isSqlServer ? self::SQLSRV_MAX_INDEXES : self::MYSQL_MAX_INDEXES;
        $limits['indexes_existing'] = $secondary;
        $limits['indexes_max'] = $maxIndexes;
        $limits['indexes_free'] = $secondary === null ? null : max(0, $maxIndexes - $secondary);
        $limits['exment_index_cap'] = (int)config('exment.column_index_enabled_count', 20); // D-8 open
        $limits['key_bytes_max'] = $isSqlServer ? self::SQLSRV_MAX_KEY_BYTES : self::MYSQL_MAX_KEY_BYTES;
        return $limits;
    }

    /**
     * In-row bytes of an existing column on MySQL/MariaDB utf8mb4 (conservative), null if unknown.
     *
     * @param array<string, mixed> $c Schema::getColumns() entry
     */
    public static function mysqlRowBytesOf(array $c): ?int
    {
        $typeName = strtolower((string)($c['type_name'] ?? ''));
        $type = strtolower((string)($c['type'] ?? ''));
        $len = preg_match('/\((\d+)(?:,(\d+))?\)/', $type, $m) ? (int)$m[1] : null;
        switch ($typeName) {
            case 'varchar':
            case 'nvarchar':
                $bytes = ($len ?? 255) * static::bytesPerChar($c);
                return $bytes + ($bytes > 255 ? 2 : 1);
            case 'varbinary':
                return ($len ?? 255) + (($len ?? 255) > 255 ? 2 : 1);
            case 'char':
            case 'nchar':
                return ($len ?? 1) * static::bytesPerChar($c);
            case 'binary':
                return $len ?? 1;
            case 'tinyint':
            case 'year':
                return 1;
            case 'smallint':
                return 2;
            case 'mediumint':
            case 'date':
            case 'time':
                return 3;
            case 'int':
            case 'integer':
            case 'float':
            case 'timestamp':
                return 4;
            case 'datetime':
                return 5;
            case 'bigint':
            case 'double':
                return 8;
            case 'decimal':
            case 'numeric':
                $scale = isset($m[2]) ? (int)$m[2] : 0;
                return static::decimalBytes(($len ?? 10) - $scale) + static::decimalBytes($scale);
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
            case 'json':
                return 12;
            case 'enum':
                return 2;
            case 'set':
                return 8;
        }
        return null;
    }

    /**
     * In-record bytes of an existing stored column for the InnoDB half-page limit (DYNAMIC):
     * variable-length columns whose maximum is <= 255 bytes in full plus a length byte, longer ones
     * and LOB/JSON as a 40-byte pointer, fixed-length columns as mysqlRowBytesOf().
     *
     * @param array<string, mixed> $c
     */
    public static function innodbInRowBytesOf(array $c): int
    {
        $typeName = strtolower((string)($c['type_name'] ?? ''));
        $type = strtolower((string)($c['type'] ?? ''));
        $len = preg_match('/\((\d+)/', $type, $m) ? (int)$m[1] : null;
        switch ($typeName) {
            case 'varchar':
            case 'nvarchar':
                $max = ($len ?? 255) * static::bytesPerChar($c);
                return $max <= 255 ? $max + 1 : 40;
            case 'varbinary':
                return ($len ?? 255) <= 255 ? ($len ?? 255) + 1 : 40;
            case 'tinytext':
            case 'text':
            case 'mediumtext':
            case 'longtext':
            case 'tinyblob':
            case 'blob':
            case 'mediumblob':
            case 'longblob':
            case 'json':
                return 40;
        }
        return static::mysqlRowBytesOf($c) ?? 8;
    }

    /** InnoDB in-page record limit: innodb_page_size / 2 - 66 (8126 for 16K pages). */
    public static function innodbInRowLimit(): int
    {
        if (!isset(static::$innodbPageSize)) {
            try {
                static::$innodbPageSize = (int)(DB::selectOne('select @@innodb_page_size as v')->v ?? 16384);
            } catch (\Throwable $ex) {
                static::$innodbPageSize = 16384;
            }
        }
        return intdiv(static::$innodbPageSize, 2) - 66;
    }

    /** @var int|null */
    private static $innodbPageSize;

    /** The value table's row format (information_schema), or null when unknown. */
    public static function rowFormat(string $db_table_name): ?string
    {
        try {
            $row = DB::selectOne('select ROW_FORMAT as f from information_schema.TABLES where TABLE_SCHEMA = database() and TABLE_NAME = ?', [$db_table_name]);
            return $row ? (string)$row->f : null;
        } catch (\Throwable $ex) {
            return null;
        }
    }

    /**
     * Maximum bytes per character of a column's character set, from its collation as the schema
     * reports it (utf8mb4 4, utf8mb3/utf8 3, ucs2 2, latin1/ascii 1); unknown or missing: 4
     * (conservative). Generated (virtual) columns are counted like stored ones: MariaDB 10.4.32
     * rejects the ALTER with error 1118 when they push the definition over 65,535 bytes [ĐO].
     *
     * @param array<string, mixed> $c
     */
    public static function bytesPerChar(array $c): int
    {
        $collation = strtolower((string)($c['collation'] ?? ''));
        if ($collation === '') {
            return 4;
        }
        if (strpos($collation, 'utf8mb4') === 0) {
            return 4;
        }
        if (strpos($collation, 'utf8mb3') === 0 || strpos($collation, 'utf8_') === 0) {
            return 3;
        }
        if (strpos($collation, 'ucs2') === 0) {
            return 2;
        }
        if (strpos($collation, 'latin1') === 0 || strpos($collation, 'ascii') === 0) {
            return 1;
        }
        return 4;
    }

    /** Fixed in-row bytes of an existing SQL Server column (variable-length: 0, may overflow). */
    public static function sqlServerFixedBytesOf(array $c): int
    {
        switch (strtolower((string)($c['type_name'] ?? ''))) {
            case 'tinyint':
            case 'bit':
                return 1;
            case 'smallint':
                return 2;
            case 'int':
            case 'date':
            case 'real':
            case 'smalldatetime':
                return 4;
            case 'bigint':
            case 'datetime':
            case 'datetime2':
            case 'float':
            case 'money':
                return 8;
            case 'time':
                return 5;
            case 'decimal':
            case 'numeric':
                return 17;
            case 'uniqueidentifier':
                return 16;
        }
        return 0;
    }

    private static function decimalBytes(int $digits): int
    {
        $leftover = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
        return intdiv($digits, 9) * 4 + $leftover[$digits % 9];
    }

    // ------------------------------------------------------------------ provision

    /**
     * Add the missing staging columns and record the table as 'provisioned' (JSON stays the
     * only read source and write target: no generation change). Resumes a partial run.
     *
     * @return array{state: array<string, mixed>, added: array<int, string>, existing: array<int, string>, json: array<int, string>, warnings: array<int, array<string, mixed>>}
     * @throws StorageConversionException
     */
    public function provision(CustomTable $table): array
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }

        $plan = $this->plan($table);
        $eligibility = ConversionEligibility::check($table, $plan);
        if (!$eligibility->isEligible()) {
            throw new StorageConversionException('table is not eligible for conversion', ['blockers' => $eligibility->blockers()]);
        }

        // claim the table first, so the column guard blocks column changes while DDL runs
        $storage = TableStorage::fresh($table);
        if ($storage->state() === StorageState::JSON) {
            $storage = $storage->transitionTo(StorageState::PROVISIONED, StorageMode::JSON, false);
            if ($storage === null) {
                throw new StorageConversionException('storage state changed concurrently', []);
            }
            StorageAudit::record($storage->tableId(), StorageState::JSON, StorageState::PROVISIONED, $storage->generation(), $storage->generation(), ['operation' => 'provision']);
        } elseif ($storage->state() !== StorageState::PROVISIONED) {
            throw new StorageConversionException('storage state is ' . $storage->state(), []);
        }

        // the columns may have changed between plan() and the claim: plan again under the claim
        $plan = $this->plan($table);
        if (!$plan->isProvisionable()) {
            throw new StorageConversionException('physical schema plan has blockers', ['blockers' => $plan->blockers()]);
        }

        $added = $this->applyPlanDdl($table, $plan, $storage->generation());

        return [
            'state' => TableStorage::fresh($table)->toArray(),
            'added' => $added,
            'existing' => array_map(function ($e) {
                return (string)$e['staging_column'];
            }, $plan->existing()),
            'json' => array_values(array_map(function ($e) {
                return (string)$e['column_name'];
            }, array_filter($plan->columns(), function ($e) {
                return $e['action'] === 'json';
            }))),
            'warnings' => $eligibility->warnings(),
        ];
    }

    /**
     * The DDL of a plan: value_original first, then every missing staging column; idempotent, resumable.
     * Shared by provision() (state provisioned) and the initialization of a table created as physical
     * (state initializing). A database refusal leaves the columns added so far (DDL auto-commits), records
     * an audit row with the SQLSTATE/driver code only, and throws; the caller keeps its state.
     *
     * @return array<int, string> the columns added
     * @throws StorageConversionException
     */
    public function applyPlanDdl(CustomTable $table, PhysicalSchemaPlan $plan, int $generation, string $auditState = StorageState::PROVISIONED): array
    {
        $db_table_name = $plan->dbTableName();
        $added = [];
        try {
            // value_original first: a typed column without it could not hold a non-lossless cell
            if (($plan->valueOriginal()['action'] ?? null) === 'add') {
                $this->addValueOriginalColumn($db_table_name);
                $added[] = PhysicalNaming::VALUE_ORIGINAL;
            }
            foreach ($plan->toAdd() as $entry) {
                $this->addStagingColumn($db_table_name, (string)$entry['staging_column'], (string)$entry['physical_type']);
                $added[] = (string)$entry['staging_column'];
            }
        } catch (\Illuminate\Database\QueryException $ex) {
            // The database refused a DDL the plan accepted (e.g. a row-size estimate below a limit
            // the engine still hits). The columns added so far stay (DDL committed); the table stays
            // 'provisioned' and incomplete - plan() reconciles from the live schema, startDualWrite
            // refuses an incomplete schema, deprovision() can remove the (empty) columns. Only the
            // SQLSTATE / driver code is reported: a DB message can carry identifiers and values.
            $code = static::dbErrorCode($ex);
            StorageAudit::record((int)$table->id, $auditState, $auditState, $generation, $generation,
                ['operation' => 'provision_failed', 'db_error' => $code, 'added' => $added]);
            throw new StorageConversionException('provisioning stopped: the database refused a schema change (' . $code . ')'
                . ($code === 'mysql:1118' ? ' - row size limit; this table cannot be converted in this release' : '')
                . '; the table stays provisioned and incomplete', ['added' => $added, 'db_error' => $code]);
        } finally {
            // also after a failure: the columns added so far exist (DDL auto-commits); the table
            // stays 'provisioned' and provision() resumes from the live schema
            $this->forgetColumnListing($db_table_name);
        }

        return $added;
    }

    /**
     * Idempotent ADD COLUMN: re-checks the live schema; a concurrent add of the same column with
     * the expected type is accepted, anything else is an error (never altered or replaced).
     */
    private function addStagingColumn(string $db_table_name, string $name, string $type): void
    {
        $schema = DB::connection()->getSchemaBuilder();
        if ($this->liveColumn($db_table_name, $name) === null) {
            try {
                $schema->table($db_table_name, function (Blueprint $blueprint) use ($type, $name) {
                    PhysicalColumnType::addTo($blueprint, $type, $name);
                });
            } catch (\Illuminate\Database\QueryException $ex) {
                $now = $this->liveColumn($db_table_name, $name);
                if ($now === null || !empty($now['generation']) || !PhysicalColumnType::matches($type, $now, static::driverFamily())) {
                    throw $ex;
                }
            }
        }
        $now = $this->liveColumn($db_table_name, $name);
        if ($now === null || !empty($now['generation']) || !PhysicalColumnType::matches($type, $now, static::driverFamily())) {
            throw new StorageConversionException("staging column $name is not as expected after ADD COLUMN", ['column' => $now]);
        }
    }

    /**
     * The driver error of a QueryException as "<driver>:<code>" (MySQL/MariaDB error number,
     * else SQLSTATE) - never the message.
     */
    public static function dbErrorCode(\Illuminate\Database\QueryException $ex): string
    {
        $info = $ex->errorInfo ?? null;
        if (is_array($info) && isset($info[1]) && $info[1] !== null) {
            return (static::driverFamily() === 'sqlsrv' ? 'sqlsrv:' : 'mysql:') . $info[1];
        }
        return 'sqlstate:' . (string)$ex->getCode();
    }

    /** Idempotent ADD COLUMN value_original (JSON, nullable), same rules as a staging column. */
    private function addValueOriginalColumn(string $db_table_name): void
    {
        $name = PhysicalNaming::VALUE_ORIGINAL;
        $family = static::driverFamily();
        $ok = function () use ($db_table_name, $name, $family) {
            $now = $this->liveColumn($db_table_name, $name);
            return $now !== null && empty($now['generation']) && static::isJsonColumn($now, $family) && ($now['nullable'] ?? true);
        };
        if ($this->liveColumn($db_table_name, $name) === null) {
            try {
                DB::connection()->getSchemaBuilder()->table($db_table_name, function (Blueprint $blueprint) use ($name) {
                    $blueprint->json($name)->nullable();
                });
            } catch (\Illuminate\Database\QueryException $ex) {
                if (!$ok()) {
                    throw $ex;
                }
            }
        }
        if (!$ok()) {
            throw new StorageConversionException("$name is not as expected after ADD COLUMN", ['column' => $this->liveColumn($db_table_name, $name)]);
        }
    }

    /** @return array<string, mixed>|null */
    private function liveColumn(string $db_table_name, string $name): ?array
    {
        foreach (DB::connection()->getSchemaBuilder()->getColumns($db_table_name) as $c) {
            if ((string)$c['name'] === $name) {
                return $c;
            }
        }
        return null;
    }

    /** The hasColumn() listing of this table only (Helpers::hasColumn cache key). */
    private function forgetColumnListing(string $db_table_name): void
    {
        System::clearCache(sprintf(Define::SYSTEM_KEY_SESSION_DATABASE_COLUMN_NAMES_IN_TABLE, $db_table_name));
    }

    // ------------------------------------------------------------------ deprovision

    /**
     * Undo provisioning: drop the staging columns of the table's current columns and return the
     * table to 'json'. Only in state 'provisioned' (nothing reads or writes the columns yet).
     * A staging column is dropped only when it is ours (not generated, expected type) and holds
     * no value; otherwise the call stops before dropping anything.
     *
     * @return array{state: array<string, mixed>, dropped: array<int, string>, kept: array<int, string>}
     * @throws StorageConversionException
     */
    public function deprovision(CustomTable $table): array
    {
        if (!$this->availability->isAvailable()) {
            throw new StorageConversionException('conversion is not available', ['missing' => $this->availability->missing()]);
        }
        $storage = TableStorage::fresh($table);
        if ($storage->state() !== StorageState::PROVISIONED || $storage->mode() !== StorageMode::JSON) {
            throw new StorageConversionException('deprovision needs state provisioned', ['storage' => $storage->toArray()]);
        }

        [$plan, $drop] = $this->dropEmptyStagingColumns($table);

        $next = $storage->transitionTo(StorageState::JSON, StorageMode::JSON, false);
        if ($next === null) {
            throw new StorageConversionException('storage state changed concurrently; staging columns were dropped', ['dropped' => $drop]);
        }
        StorageAudit::record($next->tableId(), StorageState::PROVISIONED, StorageState::JSON, $storage->generation(), $next->generation(), ['operation' => 'deprovision', 'dropped' => $drop]);

        $kept = array_map(function ($w) {
            return (string)$w['message'];
        }, array_filter($plan->warnings(), function ($w) {
            return $w['code'] === 'orphan_staging_column';
        }));

        return ['state' => $next->toArray(), 'dropped' => $drop, 'kept' => array_values($kept)];
    }

    /**
     * Drop the staging columns of the table's current columns and value_original - only when every one is
     * ours (not generated, expected type) AND empty; otherwise nothing is dropped. Shared by deprovision()
     * and the cancellation of an initializing table.
     *
     * @return array{0: PhysicalSchemaPlan, 1: array<int, string>} the plan used, the columns dropped
     * @throws StorageConversionException
     */
    public function dropEmptyStagingColumns(CustomTable $table): array
    {
        $plan = $this->plan($table);
        $db_table_name = $plan->dbTableName();
        $family = static::driverFamily();

        // verify every candidate before the first DROP
        $drop = [];
        foreach ($plan->columns() as $entry) {
            if (!in_array($entry['action'], ['exists'], true)) {
                continue;
            }
            $name = (string)$entry['staging_column'];
            $live = $this->liveColumn($db_table_name, $name);
            if ($live === null) {
                continue;
            }
            if (!empty($live['generation']) || !PhysicalColumnType::matches((string)$entry['physical_type'], $live, $family)) {
                throw new StorageConversionException("$name is not a staging column of this code; nothing dropped", ['column' => $live]);
            }
            if (DB::table($db_table_name)->whereNotNull($name)->exists()) {
                throw new StorageConversionException("$name holds values; nothing dropped", []);
            }
            $drop[] = $name;
        }
        if (($plan->valueOriginal()['action'] ?? null) === 'exists') {
            $name = PhysicalNaming::VALUE_ORIGINAL;
            if (DB::table($db_table_name)->whereNotNull($name)->exists()) {
                throw new StorageConversionException("$name holds values; nothing dropped", []);
            }
            $drop[] = $name;
        }

        if (!empty($drop)) {
            DB::connection()->getSchemaBuilder()->table($db_table_name, function (Blueprint $blueprint) use ($drop) {
                $blueprint->dropColumn($drop);
            });
            $this->forgetColumnListing($db_table_name);
        }

        return [$plan, $drop];
    }

    // ------------------------------------------------------------------ per-column DDL (02 §16 lifecycle)

    /**
     * Idempotent: make sure value_original and the typed column of a single-valued column exist with the
     * expected type (a name taken by anything else is refused, never altered). Returns the typed column
     * name, or null for a column that has none (multi-valued: stays in JSON, R2).
     *
     * @throws StorageConversionException a database refusal (code only) or an unexpected live column
     */
    public function addColumnDdl(CustomTable $table, \Exceedone\Exment\Model\CustomColumn $column): ?string
    {
        if ($column->isMultipleEnabled()) {
            return null;
        }
        $type = PhysicalColumnType::of($column);
        if ($type === null) {
            throw new StorageConversionException("column type {$column->column_type} has no physical type", ['column' => $column->column_name]);
        }
        $db_table_name = getDBTableName($table);
        $name = PhysicalNaming::stagingColumn($column);
        try {
            $this->addValueOriginalColumn($db_table_name);
            $this->addStagingColumn($db_table_name, $name, $type);
        } catch (\Illuminate\Database\QueryException $ex) {
            $code = static::dbErrorCode($ex);
            throw new StorageConversionException('the database refused the column change (' . $code . ')'
                . ($code === 'mysql:1118' ? ' - row size limit' : ''), ['db_error' => $code, 'column' => $column->column_name]);
        } finally {
            $this->forgetColumnListing($db_table_name);
        }
        return $name;
    }

    /**
     * Idempotent: drop the typed column of a column suuid and its index (SQL Server refuses to drop an
     * indexed column; MySQL drops a single-column index with it, but is told explicitly for symmetry).
     * Only a column of our naming that is not a generated column is dropped. Returns whether one was dropped.
     */
    public function dropColumnDdl(CustomTable $table, string $suuid): bool
    {
        $name = PhysicalNaming::STAGING_PREFIX . $suuid;
        if (!PhysicalNaming::isStagingName($name) || strlen(PhysicalNaming::STAGING_INDEX_PREFIX . $suuid) > PhysicalNaming::MAX_IDENTIFIER_LENGTH) {
            throw new StorageConversionException('not a valid typed column name', []);
        }
        $db_table_name = getDBTableName($table);
        $live = $this->liveColumn($db_table_name, $name);
        if ($live === null) {
            return false;
        }
        if (!empty($live['generation'])) {
            throw new StorageConversionException("$name is a generated column; it is not dropped", []);
        }
        $index = PhysicalNaming::STAGING_INDEX_PREFIX . $suuid;
        try {
            $schema = DB::connection()->getSchemaBuilder();
            foreach ($schema->getIndexes($db_table_name) as $i) {
                if ((string)$i['name'] === $index) {
                    $schema->table($db_table_name, function (Blueprint $blueprint) use ($index) {
                        $blueprint->dropIndex($index);
                    });
                    break;
                }
            }
            $schema->table($db_table_name, function (Blueprint $blueprint) use ($name) {
                $blueprint->dropColumn($name);
            });
        } catch (\Illuminate\Database\QueryException $ex) {
            if ($this->liveColumn($db_table_name, $name) !== null) {
                $code = static::dbErrorCode($ex);
                throw new StorageConversionException('the database refused to drop the column (' . $code . ')', ['db_error' => $code]);
            }
        } finally {
            $this->forgetColumnListing($db_table_name);
        }
        return true;
    }

    /**
     * Typed columns of the live schema that no current column owns.
     *
     * @return array<int, string>
     */
    public function orphanStagingColumns(CustomTable $table): array
    {
        $own = [];
        foreach ($table->custom_columns_cache as $column) {
            if (!$column->isMultipleEnabled() && PhysicalColumnType::of($column) !== null && !empty($column->suuid)) {
                $own[PhysicalNaming::stagingColumn($column)] = true;
            }
        }
        $orphans = [];
        foreach (DB::connection()->getSchemaBuilder()->getColumns(getDBTableName($table)) as $c) {
            $name = (string)$c['name'];
            if (PhysicalNaming::isStagingName($name) && !isset($own[$name]) && empty($c['generation'])) {
                $orphans[] = $name;
            }
        }
        return $orphans;
    }
    /** Idempotent: make sure the value_original column exists (recovery of an interrupted lifecycle step). */
    public function ensureValueOriginal(CustomTable $table): void
    {
        $db_table_name = getDBTableName($table);
        try {
            $this->addValueOriginalColumn($db_table_name);
        } catch (\Illuminate\Database\QueryException $ex) {
            throw new StorageConversionException('the database refused to add value_original (' . static::dbErrorCode($ex) . ')', []);
        } finally {
            $this->forgetColumnListing($db_table_name);
        }
    }
}
