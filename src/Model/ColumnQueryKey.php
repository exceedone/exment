<?php

namespace Exceedone\Exment\Model;

/**
 * The query expression of one column value, with the context a later storage routing needs:
 * the owning table, the column (when the key is one), the table name or alias written in front,
 * and the selection policy the caller uses today.
 *
 * Today every policy builds exactly the string the caller built before - JSON storage only. There
 * is no storage mode here: routing a converted table to its own columns is Stage B work.
 *
 * The expression is built when the object is created, so any index-column DDL runs at the same
 * point as before. Creating the object never looks up metadata; the owning table of a column is
 * only resolved when table() is asked for.
 *
 * Index names used for CSS classes, field names or display (CustomItem::index()) are not query
 * expressions and do not go through here.
 */
final class ColumnQueryKey
{
    /** CustomColumn::getQueryKeyFor(): index column when index_enabled (DDL if missing), else JSON. */
    public const POLICY_INDEX_PREFERRING = 'index_preferring';
    /** CustomColumn::getIndexColumnName(): the index column whatever index_enabled says (DDL if missing). */
    public const POLICY_INDEX = 'index';
    /** CustomColumn::getIndexColumnName(false): the index column whatever index_enabled says; never DDL. */
    public const POLICY_INDEX_WITHOUT_DDL = 'index_without_ddl';
    /** Index column when index_enabled and the column exists, else JSON; never DDL. */
    public const POLICY_INDEX_IF_PRESENT = 'index_if_present';
    /** CustomColumn::jsonValuePath(): the JSON path, whatever the index setting. */
    public const POLICY_JSON = 'json';

    /** @var CustomTable|null */
    private $table;
    /** @var CustomColumn|null */
    private $column;
    /** @var string */
    private $key;
    /** @var string */
    private $policy;
    /** @var string|null */
    private $qualifier;
    /** @var string */
    private $expression;

    /**
     * @param CustomTable|null $table
     * @param CustomColumn|null $column
     * @param mixed $key
     * @param string $policy
     * @param string|null $qualifier
     * @param string $expression
     */
    private function __construct($table, $column, $key, string $policy, ?string $qualifier, string $expression)
    {
        $this->table = $table;
        $this->column = $column;
        $this->key = (string)$key;
        $this->policy = $policy;
        $this->qualifier = $qualifier;
        $this->expression = isset($qualifier) ? $qualifier . '.' . $expression : $expression;
    }

    /**
     * Index-preferring key for a query purpose (CustomColumn::QUERY_PURPOSE_*).
     */
    public static function indexPreferring(CustomColumn $column, string $purpose, ?string $qualifier = null): self
    {
        return new self(null, $column, $column->column_name, self::POLICY_INDEX_PREFERRING, $qualifier, $column->getQueryKeyFor($purpose));
    }

    /**
     * The index column, unconditionally (callers gate on index_enabled themselves where they do).
     *
     * Untyped, and the name is asked for first: a caller passing a missing column (null) fails
     * with the same Error as its former $column->getIndexColumnName() call.
     *
     * @param CustomColumn $column
     */
    public static function index($column, ?string $qualifier = null): self
    {
        $expression = $column->getIndexColumnName();
        return new self(null, $column, $column->column_name, self::POLICY_INDEX, $qualifier, $expression);
    }

    /**
     * The index column, unconditionally and without DDL (callers rely on it existing).
     *
     * @param CustomColumn $column
     */
    public static function indexWithoutDdl($column, ?string $qualifier = null): self
    {
        $expression = $column->getIndexColumnName(false);
        return new self(null, $column, $column->column_name, self::POLICY_INDEX_WITHOUT_DDL, $qualifier, $expression);
    }

    /**
     * Index column when index_enabled and present in $db_table_name, else the JSON path; no DDL.
     */
    public static function indexIfPresent(CustomColumn $column, string $db_table_name, ?string $qualifier = null): self
    {
        $index = $column->getIndexColumnName(false);
        $expression = $column->index_enabled && hasColumn($db_table_name, $index) ? $index : CustomColumn::jsonValuePath($column->column_name);
        return new self(null, $column, $column->column_name, self::POLICY_INDEX_IF_PRESENT, $qualifier, $expression);
    }

    /**
     * The explicit JSON path of a column, or of a key given by a setting (then no column and no
     * metadata lookup; pass the owning table when the caller has it).
     *
     * @param CustomColumn|string|int $column_or_key
     * @param CustomTable|null $table
     * @param string|null $qualifier
     */
    public static function json($column_or_key, ?CustomTable $table = null, ?string $qualifier = null): self
    {
        $column = $column_or_key instanceof CustomColumn ? $column_or_key : null;
        $key = isset($column) ? $column->column_name : $column_or_key;
        return new self($table, $column, $key, self::POLICY_JSON, $qualifier, CustomColumn::jsonValuePath($key));
    }

    /** The expression to put into the query builder - the same string as before. */
    public function expression(): string
    {
        return $this->expression;
    }

    public function __toString(): string
    {
        return $this->expression;
    }

    public function policy(): string
    {
        return $this->policy;
    }

    /** Column name, or the key given by a setting. */
    public function key(): string
    {
        return $this->key;
    }

    public function column(): ?CustomColumn
    {
        return $this->column;
    }

    /** Table name or alias written in front of the expression, if any. */
    public function qualifier(): ?string
    {
        return $this->qualifier;
    }

    /**
     * The owning table: the one given, or the column's table (resolved now, not when created).
     */
    public function table(): ?CustomTable
    {
        if (isset($this->table)) {
            return $this->table;
        }
        return isset($this->column) ? $this->column->custom_table_cache : null;
    }
}
