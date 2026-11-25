<?php

namespace Exceedone\Exment\Services\Storage;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Illuminate\Database\Schema\Blueprint;

/**
 * Physical type of a single-valued column (02 §3.2 as corrected in §11.2).
 *
 * The type depends only on column_type (immutable), never on an editable option
 * (string_length, decimal_digit, number_min/max...), so changing an option never needs DDL.
 * Multi-valued columns stay in the JSON "value" field (R2) and have no physical type.
 *
 * Each type carries: the Blueprint call that creates it, what the schema reports for it per
 * driver (to recognise an existing staging column as ours), its in-row size for the row-size
 * check and its index key size (InnoDB utf8mb4 / SQL Server nvarchar).
 */
final class PhysicalColumnType
{
    public const MEDIUMTEXT = 'mediumtext';
    public const VARCHAR255 = 'varchar255';
    public const VARCHAR768 = 'varchar768';
    public const BIGINT = 'bigint';
    public const DECIMAL = 'decimal22_8';
    public const DATE = 'date';
    public const TIME = 'time';
    public const DATETIME = 'datetime';
    public const TINYINT = 'tinyint';
    public const UINT = 'uint';

    /** column_type => physical type (single-valued). */
    private const MAP = [
        ColumnType::TEXT => self::MEDIUMTEXT,
        ColumnType::TEXTAREA => self::MEDIUMTEXT,
        ColumnType::EDITOR => self::MEDIUMTEXT,
        ColumnType::URL => self::MEDIUMTEXT,
        ColumnType::EMAIL => self::VARCHAR255,
        ColumnType::SELECT => self::VARCHAR255,
        ColumnType::SELECT_VALTEXT => self::VARCHAR255,
        ColumnType::AUTO_NUMBER => self::VARCHAR255,
        ColumnType::BOOLEAN => self::VARCHAR255,
        ColumnType::INTEGER => self::BIGINT,
        ColumnType::DECIMAL => self::DECIMAL,
        ColumnType::CURRENCY => self::DECIMAL,
        ColumnType::DATE => self::DATE,
        ColumnType::TIME => self::TIME,
        ColumnType::DATETIME => self::DATETIME,
        ColumnType::YESNO => self::TINYINT,
        ColumnType::SELECT_TABLE => self::UINT,
        ColumnType::USER => self::UINT,
        ColumnType::ORGANIZATION => self::UINT,
        ColumnType::FILE => self::VARCHAR768,
        ColumnType::IMAGE => self::VARCHAR768,
    ];

    /**
     * Per type: in-row bytes on MySQL/MariaDB utf8mb4 (LOB = pointer, conservative 12),
     * fixed in-row bytes on SQL Server (0 = variable length, may overflow off row),
     * index key bytes (null = not indexable without a prefix), and the type names the schema
     * reports (lower case) per driver family, with the parameters that must match.
     */
    private const SPEC = [
        self::MEDIUMTEXT => ['row' => 12, 'sqlsrv_fixed' => 0, 'key' => null,
            'mysql' => ['mediumtext', null], 'sqlsrv' => ['nvarchar', 'max']],
        self::VARCHAR255 => ['row' => 1022, 'sqlsrv_fixed' => 0, 'key' => 1020,
            'mysql' => ['varchar', '255'], 'sqlsrv' => ['nvarchar', '255']],
        self::VARCHAR768 => ['row' => 3074, 'sqlsrv_fixed' => 0, 'key' => 3072,
            'mysql' => ['varchar', '768'], 'sqlsrv' => ['nvarchar', '768']],
        self::BIGINT => ['row' => 8, 'sqlsrv_fixed' => 8, 'key' => 8,
            'mysql' => ['bigint', null], 'sqlsrv' => ['bigint', null]],
        self::DECIMAL => ['row' => 11, 'sqlsrv_fixed' => 13, 'key' => 11,
            'mysql' => ['decimal', '22,8'], 'sqlsrv' => ['decimal', '22,8']],
        self::DATE => ['row' => 3, 'sqlsrv_fixed' => 3, 'key' => 3,
            'mysql' => ['date', null], 'sqlsrv' => ['date', null]],
        self::TIME => ['row' => 3, 'sqlsrv_fixed' => 5, 'key' => 3,
            'mysql' => ['time', null], 'sqlsrv' => ['time', null]],
        self::DATETIME => ['row' => 5, 'sqlsrv_fixed' => 8, 'key' => 5,
            'mysql' => ['datetime', null], 'sqlsrv' => ['datetime', null]],
        self::TINYINT => ['row' => 1, 'sqlsrv_fixed' => 1, 'key' => 1,
            'mysql' => ['tinyint', null], 'sqlsrv' => ['tinyint', null]],
        self::UINT => ['row' => 4, 'sqlsrv_fixed' => 4, 'key' => 4,
            'mysql' => ['int', 'unsigned'], 'sqlsrv' => ['int', null]],
    ];

    /** Physical type of a column, or null when it has none (multi-valued, or unknown type). */
    public static function of(CustomColumn $column): ?string
    {
        if ($column->isMultipleEnabled()) {
            return null;
        }
        return self::MAP[(string)$column->column_type] ?? null;
    }

    /** Whether the column type has a mapping at all (single-valued use). */
    public static function isKnownColumnType(string $column_type): bool
    {
        return array_key_exists($column_type, self::MAP);
    }

    /** Add the column (nullable, no default, no index) to a Blueprint. */
    public static function addTo(Blueprint $table, string $type, string $name): void
    {
        switch ($type) {
            case self::MEDIUMTEXT:
                $table->mediumText($name)->nullable();
                return;
            case self::VARCHAR255:
                $table->string($name, 255)->nullable();
                return;
            case self::VARCHAR768:
                $table->string($name, 768)->nullable();
                return;
            case self::BIGINT:
                $table->bigInteger($name)->nullable();
                return;
            case self::DECIMAL:
                $table->decimal($name, 22, 8)->nullable();
                return;
            case self::DATE:
                $table->date($name)->nullable();
                return;
            case self::TIME:
                $table->time($name)->nullable();
                return;
            case self::DATETIME:
                $table->dateTime($name)->nullable();
                return;
            case self::TINYINT:
                $table->tinyInteger($name)->nullable();
                return;
            case self::UINT:
                $table->unsignedInteger($name)->nullable();
                return;
        }
        throw new \InvalidArgumentException("unknown physical type $type");
    }

    public static function rowBytes(string $type): int
    {
        return self::SPEC[$type]['row'];
    }

    /**
     * InnoDB in-record bytes (DYNAMIC row format): a variable-length column whose maximum is over
     * 255 bytes, and a LOB, may be stored off page and are counted as 40 bytes; fixed-length
     * columns at full size.
     */
    public static function inRowBytes(string $type): int
    {
        switch ($type) {
            case self::MEDIUMTEXT:
            case self::VARCHAR255:  // 1020 bytes in utf8mb4: off-page eligible
            case self::VARCHAR768:
                return 40;
        }
        return self::SPEC[$type]['row'];
    }

    public static function sqlServerFixedBytes(string $type): int
    {
        return self::SPEC[$type]['sqlsrv_fixed'];
    }

    /** Index key bytes, or null when the type cannot be indexed without a prefix (LOB). */
    public static function keyBytes(string $type): ?int
    {
        return self::SPEC[$type]['key'];
    }

    /**
     * Whether a column the schema reports (Schema::getColumns() entry) is this type: used to
     * recognise an existing staging column as the one this code would have created.
     * SQL Server parameters are not compared for time/datetime (precision reported by the
     * driver not verified) [CHƯA KIỂM CHỨNG].
     *
     * @param array<string, mixed> $schemaColumn
     */
    public static function matches(string $type, array $schemaColumn, string $driverFamily): bool
    {
        [$typeName, $param] = self::SPEC[$type][$driverFamily === 'sqlsrv' ? 'sqlsrv' : 'mysql'];
        if (strtolower((string)($schemaColumn['type_name'] ?? '')) !== $typeName) {
            return false;
        }
        if ($param === null) {
            return true;
        }
        $full = strtolower(str_replace(' ', '', (string)($schemaColumn['type'] ?? '')));
        if ($param === 'unsigned') {
            return strpos($full, 'unsigned') !== false;
        }
        if ($driverFamily === 'sqlsrv' && $typeName === 'nvarchar' && ctype_digit((string)$param)) {
            // Schema::getColumns() reports an nvarchar length in BYTES (nvarchar(255) => "nvarchar(510)")
            // [measured on SQL Server 2025 with pdo_sqlsrv]; accept the character form too
            return strpos($full, '(' . ((int)$param * 2) . ')') !== false || strpos($full, '(' . $param . ')') !== false;
        }
        return strpos($full, '(' . $param . ')') !== false;
    }
}
