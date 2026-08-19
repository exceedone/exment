<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;

/**
 * Single source of truth for the dashboard-filter request state — the df_{column}
 * params the dashboard filter bar emits and forwards into every box AJAX request, and
 * the bf_{column} params a box's own chart-level filter puts on its AJAX.
 *
 * Before this class, each consumer re-read request()->all() with its own copy of the
 * guards (ChartItem::applyDashboardFilter / ::filterBarChain, the filter badge in
 * DashboardBoxController, the AI cache-key fingerprint) and they stayed consistent
 * only by a comment-level contract. Every accessor keeps the deliberate differences in
 * strictness of its original call site:
 *
 *   raw()          every df_ param with a non-empty value                (cache fingerprint)
 *   activeColumns() + the column part must be a plain identifier        (filter badge)
 *   columnsOn()     + the column must really exist on the given table   (filtering / chain)
 *
 * VALUE SHAPES (df_ and bf_ alike; see spec()) — one param, three shapes:
 *
 *   df_col=v                     single value            → col = v            (legacy, unchanged)
 *   df_col[]=a&df_col[]=b        several values (slicer) → col IN (a, b)
 *   df_col[from]=x&df_col[to]=y  range (either side)     → col >= x AND col <= y
 *
 * Range bounds compare numerically on number columns (CAST), as ISO dates on date /
 * datetime columns, and as plain strings otherwise; blank values never fall inside a
 * range. Every consumer that turns a value into SQL goes through where() / whereExpr(),
 * so all three shapes behave identically everywhere (box query, cascade option lists,
 * benchmark scopes, level probes).
 *
 * All methods read the LIVE request at call time (no snapshot): sanitizeExclusive()
 * mutates the request, and consumers must observe the post-sanitize state exactly as
 * they did when they read request() directly.
 */
class FilterState
{
    /**
     * Whether $name is a plain identifier (letters / digits / underscore) — the only column
     * names this layer ever interpolates into SQL or a JSON path.
     *
     * @param mixed $name
     * @return bool
     */
    public static function isIdentifier($name)
    {
        return is_string($name) && $name !== '' && preg_match('/^[A-Za-z0-9_]+$/D', $name) === 1; // D: no trailing newline
    }

    /**
     * SQL expression reading one custom column's stored value: the generated index column
     * (`column_<suuid>`) when the column has one — DISTINCT / WHERE over a large fact table
     * is orders of magnitude faster index-backed — else JSON extraction from the value blob.
     * getIndexColumnName(false) never alters the schema. The JSON path interpolates the
     * column name, so callers pass an identifier-validated name (or a CustomColumn model).
     *
     * @param mixed $customColumn  CustomColumn | null
     * @param string|null $column  column name; defaults to $customColumn->column_name
     * @return string
     */
    public static function columnExpr($customColumn, $column = null)
    {
        if (!is_nullorempty($customColumn) && $customColumn->index_enabled) {
            return '`' . $customColumn->getIndexColumnName(false) . '`';
        }
        $column = $column ?? (is_nullorempty($customColumn) ? '' : $customColumn->column_name);
        return "JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.\"{$column}\"'))";
    }

    /**
     * Normalize one df_/bf_ request value into a filter spec, or null when it carries no
     * filter at all (missing, empty, junk shape). Idempotent: a spec goes through unchanged.
     *
     *   scalar 'v'                       → ['in' => ['v']]
     *   list   ['a', 'b']                → ['in' => ['a', 'b']]   ('' dropped, de-duplicated, order kept)
     *   assoc  ['from' => x, 'to' => y]  → ['from' => x|null, 'to' => y|null]  (at least one side)
     *
     * A list is recognised by integer keys only (`df_col[]=…` / `df_col[0]=…`); an
     * associative array with any other keys is not a filter and is ignored — a deep link
     * cannot smuggle a shape the bar never emits.
     *
     * @param mixed $raw
     * @return array|null
     */
    public static function spec($raw)
    {
        if (is_scalar($raw)) {
            $v = (string) $raw;
            return $v === '' ? null : ['in' => [$v]];
        }
        if (!is_array($raw) || empty($raw)) {
            return null;
        }
        // already a spec (columnsOn()/boxFilters() hand these to where())
        if (array_key_exists('in', $raw) && is_array($raw['in']) && count($raw) === 1) {
            return static::spec($raw['in']);
        }
        if (array_key_exists('from', $raw) || array_key_exists('to', $raw)) {
            foreach (array_keys($raw) as $k) {
                if ($k !== 'from' && $k !== 'to') {
                    return null; // mixed shape — not something the bar emits
                }
            }
            $from = static::bound($raw['from'] ?? null);
            $to   = static::bound($raw['to'] ?? null);
            if ($from === null && $to === null) {
                return null;
            }
            return ['from' => $from, 'to' => $to];
        }
        $in = [];
        foreach ($raw as $k => $v) {
            if (!is_int($k) || !is_scalar($v)) {
                continue;
            }
            $v = (string) $v;
            if ($v !== '' && !in_array($v, $in, true)) {
                $in[] = $v;
            }
        }
        return empty($in) ? null : ['in' => $in];
    }

    /**
     * One range bound: a non-empty scalar as a trimmed string, else null.
     *
     * @param mixed $v
     * @return string|null
     */
    protected static function bound($v)
    {
        if (!is_scalar($v)) {
            return null;
        }
        $v = trim((string) $v);
        return $v === '' ? null : $v;
    }

    /**
     * The value when the filter is exactly ONE equality value (the legacy `df_col=v` case,
     * or a one-element list), else null. Consumers whose logic needs a single value —
     * the grid deep link — use this and
     * treat "several values / a range" as "not selected in that sense".
     *
     * @param mixed $raw  request value or spec
     * @return string|null
     */
    public static function single($raw)
    {
        $spec = static::spec($raw);
        if ($spec === null || !isset($spec['in']) || count($spec['in']) !== 1) {
            return null;
        }
        return $spec['in'][0];
    }

    /**
     * Human-readable list of the values a spec selects, for labels/breadcrumbs:
     * the 'in' values as given, or the range as "x – y" / "x –" / "– y".
     *
     * @param mixed $raw
     * @return string[]
     */
    public static function values($raw)
    {
        $spec = static::spec($raw);
        if ($spec === null) {
            return [];
        }
        if (isset($spec['in'])) {
            return $spec['in'];
        }
        return [trim(($spec['from'] ?? '') . ' – ' . ($spec['to'] ?? ''))];
    }

    /**
     * Compact form for callers that used to receive a string: a single value comes back
     * as the plain string it always was, everything else as its spec array. Both forms
     * are accepted everywhere a value is consumed (where(), spec(), single(), values()).
     *
     * @param mixed $raw
     * @return string|array|null
     */
    protected static function compact($raw)
    {
        $single = static::single($raw);
        if ($single !== null) {
            return $single;
        }
        return static::spec($raw);
    }

    /**
     * How a column's values compare inside a RANGE, and which input a range field renders:
     * 'number' (integer / decimal / currency), 'date', 'datetime', or 'text'.
     *
     * @param \Exceedone\Exment\Model\CustomColumn|null $customColumn
     * @return string
     */
    public static function kind($customColumn)
    {
        $type = $customColumn ? $customColumn->column_type : null;
        if (ColumnType::isCalc($type)) {
            return 'number';
        }
        if ($type === ColumnType::DATETIME) {
            return 'datetime';
        }
        if (ColumnType::isDate($type)) {
            return 'date';
        }
        return 'text';
    }

    /**
     * Which control a filter item on $customColumn renders: 'select' (multi-select of the
     * distinct values) or 'range' (from / to). $configured is the admin's explicit choice
     * ('select' | 'range'); anything else = auto: numbers and dates get a range — a
     * dropdown over 2,000 distinct amounts is unusable (and hits the cardinality cap) —
     * every other type gets the select.
     *
     * @param \Exceedone\Exment\Model\CustomColumn|null $customColumn
     * @param mixed $configured
     * @return string
     */
    public static function style($customColumn, $configured = null)
    {
        if ($configured === 'select' || $configured === 'range') {
            return $configured;
        }
        return static::kind($customColumn) === 'text' ? 'select' : 'range';
    }

    /**
     * Every df_ param carrying a filter, as full-key => string token. A single value is
     * its plain string (unchanged); a list / range is a canonical JSON token (list sorted,
     * so [a,b] and [b,a] — the same rows — share one token). No identifier validation —
     * this level feeds the cache fingerprint, which must distinguish requests even by
     * params that later guards would reject.
     *
     * @return array<string, string>
     */
    public static function raw()
    {
        $out = [];
        foreach (request()->all() as $key => $v) {
            if (strncmp((string) $key, 'df_', 3) !== 0) {
                continue;
            }
            $spec = static::spec($v);
            if ($spec === null) {
                continue;
            }
            $out[(string) $key] = static::token($spec);
        }
        // Advanced conditions live under `dfa` (an array param), so they cannot be picked up
        // by the df_ scan above — fold them in as one token, or a cached AI insight would be
        // served for a different filter state.
        $adv = AdvancedFilter::fingerprint();
        if ($adv !== '') {
            $out['dfa'] = $adv;
        }
        return $out;
    }

    /**
     * Canonical string of one spec (see raw()).
     *
     * @param array $spec
     * @return string
     */
    protected static function token(array $spec)
    {
        if (isset($spec['in'])) {
            if (count($spec['in']) === 1) {
                return $spec['in'][0]; // legacy fingerprint input for the single case
            }
            $vals = $spec['in'];
            sort($vals, SORT_STRING);
            return (string) json_encode(['in' => $vals]);
        }
        return (string) json_encode(['from' => $spec['from'], 'to' => $spec['to']]);
    }

    /**
     * Stable fingerprint of the active dashboard filter ('' when none), for cache keys.
     * Exact port of the original AI cache-key fingerprint — same input set, same md5.
     *
     * @return string
     */
    public static function fingerprint()
    {
        $out = static::raw();
        if (empty($out)) {
            return '';
        }
        ksort($out);
        return md5((string) json_encode($out));
    }

    /**
     * Column names of the active df_ params whose name part is a plain identifier, in
     * request order. Exact port of the badge's param scan (no table-membership test:
     * the badge decides per box afterwards which of these its table can honor).
     *
     * @return string[]
     */
    public static function activeColumns()
    {
        $out = [];
        foreach (request()->all() as $key => $value) {
            if (strncmp((string) $key, 'df_', 3) !== 0 || static::spec($value) === null) {
                continue;
            }
            $column = substr((string) $key, 3);
            if (static::isIdentifier($column)) {
                $out[] = $column;
            }
        }
        // Advanced conditions are filters too: a box whose table cannot honor one must
        // disclose that with the same badge the equality items get.
        foreach (AdvancedFilter::columns() as $column) {
            if (!in_array($column, $out, true)) {
                $out[] = $column;
            }
        }
        return $out;
    }

    /**
     * Active df_ params that name a real custom column on $table, as column => value
     * (a single value as its string, a list / range as its spec — see compact()). The
     * membership test doubles as the SQL whitelist (same contract as
     * ChartItem::filterBarChain 'applied' / applyDashboardFilter).
     *
     * With $box given, a dim whose config declares `targets` (slicer targeting) is
     * included only when the box is targeted — so "applied" always means "actually
     * narrows THIS box". Omitting $box keeps the legacy dashboard-wide meaning.
     *
     * @param CustomTable|null $table
     * @param \Exceedone\Exment\Model\DashboardBox|null $box
     * @return array<string, string|array>
     */
    public static function columnsOn($table, $box = null)
    {
        if (is_nullorempty($table)) {
            return [];
        }
        $columns = $table->custom_columns->keyBy('column_name');
        $out = [];
        foreach (request()->all() as $key => $val) {
            if (strncmp((string) $key, 'df_', 3) !== 0) {
                continue;
            }
            $value = static::compact($val);
            if ($value === null) {
                continue;
            }
            $col = substr((string) $key, 3);
            if (static::isIdentifier($col) && !is_nullorempty($columns->get($col))) {
                if ($box !== null && !static::targetsAllow($box, $col)) {
                    continue;
                }
                $out[$col] = $value;
            }
        }
        return $out;
    }

    /**
     * Slicer targeting gate: whether the dashboard-filter dim for $column applies to $box.
     *
     * A dim config may declare `targets` = [box suuid, ...] (admin: 対象ボックス). With a
     * non-empty list, ONLY the listed boxes are narrowed by that dim; every other box —
     * even one whose table carries the column — ignores it (and gets the "not affected"
     * badge). No `targets` key (the default, and every pre-existing config) = the dim
     * applies to every box the column-name match reaches, exactly as before.
     *
     * A df_ param that is not a configured dim at all (deep-linked / stray) has no
     * targeting config, so it keeps the legacy column-name-match behavior too.
     *
     * @param \Exceedone\Exment\Model\DashboardBox|null $box
     * @param string $column
     * @return bool
     */
    public static function targetsAllow($box, $column)
    {
        if (is_nullorempty($box)) {
            return true;
        }
        $config = FilterBarConfig::fromDashboard($box->dashboard ?? null);
        if ($config === null) {
            return true;
        }
        foreach ($config->dims() as $dim) {
            if (array_get($dim, 'column') !== $column) {
                continue;
            }
            $targets = array_get($dim, 'targets');
            if (!is_array($targets) || empty($targets)) {
                return true;
            }
            return in_array((string) array_get($box, 'suuid'), array_map('strval', $targets), true);
        }
        return true;
    }

    /**
     * Active CHART-LEVEL filters of one box, as column => value (same value forms as
     * columnsOn()).
     *
     * A chart box may declare options.chart_filters = [column_name, ...]; the box then
     * renders its own small filter fields and reloads itself with bf_{column} params on
     * its OWN AJAX request only. This whitelist is stricter than the df_ one on purpose:
     * a bf_ param counts only when its column is DECLARED on this box (so a param can
     * never filter a box whose admin did not opt in), is a plain identifier, and really
     * exists on the box's table.
     *
     * With $table given, a declared column that does not exist on it is dropped here (the
     * query would ignore it anyway; the toolbar count / option scopes must not see it).
     *
     * @param \Exceedone\Exment\Model\DashboardBox|null $box
     * @param CustomTable|null $table  the box's own table (optional membership whitelist)
     * @return array<string, string|array>
     */
    public static function boxFilters($box, $table = null)
    {
        if (is_nullorempty($box)) {
            return [];
        }
        $configured = array_get($box, 'options.chart_filters');
        if (!is_array($configured) || empty($configured)) {
            return [];
        }
        $columns = is_nullorempty($table) ? null : $table->custom_columns->keyBy('column_name');
        $out = [];
        foreach ($configured as $col) {
            if (!static::isIdentifier($col)) {
                continue;
            }
            if ($columns !== null && is_nullorempty($columns->get($col))) {
                continue;
            }
            $value = static::compact(request()->input('bf_' . $col));
            if ($value !== null) {
                $out[$col] = $value;
            }
        }
        return $out;
    }

    /**
     * AND every active df_ filter that $table can honor onto $model. Exact port of
     * ChartItem::applyDashboardFilter: identifier + membership whitelist before the
     * column name touches SQL; the physical indexed generated column when the column
     * has one, JSON extraction otherwise.
     *
     * With $box given, two additions ride on the same whitelist + SQL shape:
     * slicer targeting (a df_ dim declaring `targets` is skipped when this box is not
     * listed — see targetsAllow) and the box's own chart-level filters (bf_{column},
     * see boxFilters) ANDed after the dashboard-level ones. Omitting $box keeps the
     * original df-only behavior byte-identical.
     *
     * @param mixed $model  query/builder the caller is about to run
     * @param CustomTable|null $table  the box's own table (whitelist source)
     * @param string[] $except  column names to skip (parent-scope queries)
     * @param \Exceedone\Exment\Model\DashboardBox|null $box
     * @return void
     */
    public static function applyTo($model, $table, array $except = [], $box = null)
    {
        if (is_nullorempty($table) || is_nullorempty($model)) {
            return;
        }
        $columns = $table->custom_columns->keyBy('column_name');

        foreach (request()->all() as $key => $val) {
            if (strncmp((string) $key, 'df_', 3) !== 0) {
                continue;
            }
            $column = substr((string) $key, 3);
            if (!static::isIdentifier($column)) {
                continue;
            }
            if (in_array($column, $except, true)) {
                continue;
            }
            $customColumn = $columns->get($column);
            if (is_nullorempty($customColumn)) {
                continue;
            }
            $spec = static::spec($val);
            if ($spec === null) {
                continue;
            }
            if ($box !== null && !static::targetsAllow($box, $column)) {
                continue;
            }
            static::where($model, $customColumn, $column, $spec);
        }

        // Advanced conditions (Detailed Filter panel, dfa[...]): free operators on any
        // column of this table, ANDed like the equality filters (see AdvancedFilter).
        AdvancedFilter::applyTo($model, $table, $except);

        // Chart-level filters (options.chart_filters + bf_*): AND after the dashboard
        // filters. Global filter and chart filter on the SAME column intersect — the
        // simple, explainable semantic. $except applies here too, so parent-scope
        // queries drop the dim consistently.
        foreach (static::boxFilters($box, $table) as $column => $val) {
            if (in_array($column, $except, true)) {
                continue;
            }
            $customColumn = $columns->get($column);
            if (is_nullorempty($customColumn)) {
                continue;
            }
            static::where($model, $customColumn, $column, $val);
        }
    }

    /**
     * AND one filter value (any shape) for one custom column onto a query: index-backed
     * when the column has its generated index column, JSON extraction otherwise. $column
     * is already identifier-validated by every caller. A single value keeps the exact
     * SQL it always produced (`where(index, v)` / `JSON... = ?`).
     *
     * @param mixed $model
     * @param \Exceedone\Exment\Model\CustomColumn $customColumn
     * @param string $column
     * @param mixed $value  request value, compact value or spec
     * @return void
     */
    public static function where($model, $customColumn, $column, $value)
    {
        $spec = static::spec($value);
        if ($spec === null) {
            return;
        }
        if ($customColumn->index_enabled && isset($spec['in'])) {
            $indexColumn = $customColumn->getIndexColumnName(false);
            if (count($spec['in']) === 1) {
                $model->where($indexColumn, $spec['in'][0]);
            } else {
                $model->whereIn($indexColumn, $spec['in']);
            }
            return;
        }
        static::whereExpr($model, static::columnExpr($customColumn, $column), $spec, static::kind($customColumn));
    }

    /**
     * AND one spec onto a query against a ready SQL expression (a validated generated
     * column or a JSON extraction). $kind (see kind()) decides how range bounds compare:
     *
     *   number    CAST(expr AS DECIMAL(20,4)) >= / <= numeric bound  (non-numeric bound ignored)
     *   date      expr >= 'YYYY-MM-DD' / <= 'YYYY-MM-DD'             (ISO strings compare in order)
     *   datetime  expr >= 'YYYY-MM-DD' / <= 'YYYY-MM-DD 23:59:59'    (whole last day included)
     *   text      plain string compare
     *
     * A range never matches a NULL / blank value (CAST('') would be 0, '' sorts first).
     *
     * @param mixed $model
     * @param string $expr
     * @param mixed $value  request value, compact value or spec
     * @param string $kind
     * @return void
     */
    public static function whereExpr($model, $expr, $value, $kind = 'text')
    {
        $spec = static::spec($value);
        if ($spec === null) {
            return;
        }
        if (isset($spec['in'])) {
            if (count($spec['in']) === 1) {
                $model->whereRaw("{$expr} = ?", [$spec['in'][0]]);
            } else {
                $model->whereRaw("{$expr} IN (" . implode(',', array_fill(0, count($spec['in']), '?')) . ')', $spec['in']);
            }
            return;
        }

        $from = $spec['from'];
        $to   = $spec['to'];
        $cmp  = $expr;
        if ($kind === 'number') {
            $from = ($from !== null && is_numeric($from)) ? $from : null;
            $to   = ($to !== null && is_numeric($to)) ? $to : null;
            $cmp  = "CAST({$expr} AS DECIMAL(20,4))";
        } elseif ($kind === 'date' || $kind === 'datetime') {
            $isDate = function ($v) {
                return $v !== null && preg_match('/^\d{4}-\d{2}-\d{2}/', $v);
            };
            $from = $isDate($from) ? substr($from, 0, 10) : null;
            $to   = $isDate($to) ? substr($to, 0, 10) : null;
            if ($to !== null && $kind === 'datetime') {
                $to .= ' 23:59:59';
            }
        }
        if ($from === null && $to === null) {
            return; // both bounds invalid for this column — no filter rather than a wrong one
        }
        $model->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''");
        if ($from !== null) {
            $model->whereRaw("{$cmp} >= ?", [$from]);
        }
        if ($to !== null) {
            $model->whereRaw("{$cmp} <= ?", [$to]);
        }
    }

    /**
     * Remove df_ params locked out by the dashboard's mutual-exclusion config (dim option
     * 'disables'). Mutating the live request is deliberate — every consumer reads df_
     * straight off the request, so stripping once here keeps them all consistent with
     * what the filter bar shows. Exact port of ChartItem::sanitizeExclusiveDfParams.
     * Idempotent per request.
     *
     * @param \Exceedone\Exment\Model\Dashboard|null $dashboard
     * @return void
     */
    public static function sanitizeExclusive($dashboard)
    {
        $config = FilterBarConfig::fromDashboard($dashboard);
        if ($config === null) {
            return;
        }
        $req = request();
        foreach ($config->dims() as $dim) {
            $col = array_get($dim, 'column');
            $targets = (array) array_get($dim, 'disables', []);
            if (is_nullorempty($col) || empty($targets)) {
                continue;
            }
            if (static::spec($req->input('df_' . $col)) === null) {
                continue;
            }
            foreach ($targets as $t) {
                if (static::isIdentifier($t)) {
                    $req->query->remove('df_' . $t);
                    $req->request->remove('df_' . $t);
                }
            }
        }
    }
}
