<?php

namespace Exceedone\Exment\Tests\Support;

use Exceedone\Exment\Services\Storage\PhysicalSchemaManager;

/**
 * Driver-aware SQL fingerprints for tests that assert the SQL text a code path generates.
 *
 * The behaviour under test is the same on every supported driver; only the dialect of the text differs
 * (identifier quoting, JSON accessors). A test states the expected text through these helpers - the
 * expectation per driver stays explicit and exact, it is not loosened.
 *
 *   MySQL / MariaDB : `col`, json_unquote(json_extract(`col`, '$."k"'))
 *   SQL Server      : [col], json_value([col], '$."k"')
 */
trait DriverSql
{
    protected static function sqlServer(): bool
    {
        return PhysicalSchemaManager::driverFamily() === 'sqlsrv';
    }

    /** The grammar's quoting of an identifier (or "table.column"). */
    protected static function q(string $identifier): string
    {
        return \DB::getQueryGrammar()->wrap($identifier);
    }

    /** The text value of a JSON key of a (already quoted) column, as the engine reads it for comparison. */
    protected static function jsonText(string $quotedColumn, string $key): string
    {
        if (static::sqlServer()) {
            return "json_value($quotedColumn, '$.\"$key\"')";
        }
        return "json_unquote(json_extract($quotedColumn, '$.\"$key\"'))";
    }

    /**
     * Set (or, with '__unset__', remove) keys of one row's JSON by rewriting the JSON text through the query
     * builder: an arrangement that does not depend on a driver's JSON update grammar (SQL Server's Laravel
     * grammar cannot express `value->key = ?`), and still a plain query-builder write (no model, no event).
     *
     * @param array<string, mixed> $changes key => value
     */
    protected static function rawJsonWrite(string $db, $id, array $changes): void
    {
        $value = json_decode((string)\DB::table($db)->where('id', $id)->value('value'), true) ?? [];
        foreach ($changes as $k => $v) {
            if ($v === '__unset__') {
                unset($value[$k]);
            } else {
                $value[$k] = $v;
            }
        }
        \DB::table($db)->where('id', $id)->update(['value' => json_encode($value)]);
    }

    /** The raw JSON extraction of a key (MySQL json_extract; SQL Server json_value reads scalars only). */
    protected static function jsonExtract(string $quotedColumn, string $key): string
    {
        if (static::sqlServer()) {
            return "json_value($quotedColumn, '$.\"$key\"')";
        }
        return "json_extract($quotedColumn, '$.\"$key\"')";
    }
}
