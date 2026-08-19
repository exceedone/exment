<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;

/**
 * Parsed view of a dashboard's `options.filter_bar` config — THE place that knows the
 * filter-bar option schema. Domain-agnostic by construction: everything here is column
 * names and parent edges declared per dashboard; no table or column is special.
 *
 *   filter_bar = {
 *     source_table: <table_name>,           // table the dim option lists are read from
 *     root_label:   <string|null>,          // breadcrumb root (default: lang root_all)
 *     scope:        {<column>: <value>}|null, // fixed option-list scope for a one-entity dashboard (see FilterBarContextBuilder)
 *     max_options:  <int|null>,             // cardinality cap per dim (default 500)
 *     dims: [{
 *       column:      <column_name>,         // df_{column} request param
 *       label:       <string|null>,
 *       parent:      <column_name|'-'|null>, // hierarchy edge (child reset + breadcrumb + drill levels):
 *                                           //   column = forced parent, '-' = forced NONE, absent = AUTO —
 *                                           //   inferred from Exment metadata (see parentOf / inferredParentOf)
 *       style:       <'select'|'range'|null>, // control: multi-select of values | from/to range; null = auto by column type
 *       from_master: <bool>,                // list master records instead of data values
 *       advanced:    <bool>,                // render in the collapsible advanced row
 *       note:        <string|null>,         // warning chip while this dim is filtered
 *       disables:    [<column_name>, ...],  // mutual exclusion (see FilterState::sanitizeExclusive)
 *     }, ...]
 *   }
 *
 * Behavior contracts preserved exactly:
 * - fromDashboard() is null ⇔ the old `is_array($config) && dims is array` checks failed,
 *   so "no filter bar configured" keeps meaning exactly what it meant before.
 * - chainColumns() reproduces ChartItem::filterBarChain's derivation: a dim is part of
 *   the hierarchy chain when it has a parent OR is named as another dim's parent, in
 *   declared (root→leaf) order; an independent cross-cut dim is never in the chain.
 *
 * PARENT RESOLUTION: the parent of a dim is what parentOf() says, in this order:
 *   1. an explicit `parent` column in the config  → forced parent
 *   2. `parent` = '-' (PARENT_NONE)                → forced independent
 *   3. otherwise AUTO: inferred from Exment metadata alone (no data statistics) — the
 *      dim's column is a select_table reference to a master, and that master (or a master
 *      it references, up to a few hops) references the master of another dim declared
 *      ABOVE it → that dim is the parent (the NEAREST one when several qualify: a
 *      candidate that is itself an ancestor of another candidate is dropped, ties go to
 *      the dim declared closest above). Two dims on the same master are not related.
 *      Anything else (text / select / number columns, or no qualifying dim above) → null.
 * Every consumer — cascade reset, breadcrumb, drill levels, benchmark parent scope — reads the
 * effective parent through parentOf(), so config and inference behave identically.
 */
class FilterBarConfig
{
    /** Explicit `parent` value meaning "forced NONE" (a column name can never be '-'). */
    public const PARENT_NONE = '-';

    /** How many master-to-master reference hops the inference follows (student → class → grade). */
    protected const REF_HOPS = 4;

    /** @var array */
    protected $config;

    /** @var string[]|null memoized chain columns */
    protected $chain = null;

    /** @var array<string, string|null>|null memoized effective parents (column => parent|null) */
    protected $parents = null;

    /** @var array<string, string|null>|null memoized INFERRED parents (explicit config ignored) */
    protected $inferred = null;

    protected function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @param mixed $dashboard  Dashboard model (or null)
     * @return static|null  null when the dashboard has no usable filter_bar config
     */
    public static function fromDashboard($dashboard)
    {
        $config = $dashboard ? $dashboard->getOption('filter_bar') : null;
        if (!is_array($config) || !is_array(array_get($config, 'dims'))) {
            return null;
        }
        return new static($config);
    }

    /**
     * A config that is not (yet) stored — the admin form asks what the inference would say
     * for the rows currently on screen. Same shape as options.filter_bar.
     *
     * @param array $config
     * @return static|null
     */
    public static function fromArray(array $config)
    {
        if (!is_array(array_get($config, 'dims'))) {
            return null;
        }
        return new static($config);
    }

    /**
     * The raw dim rows, exactly as stored (consumers keep their own per-dim guards so
     * their skip/accept behavior stays byte-identical).
     *
     * @return array<int, array>
     */
    public function dims()
    {
        return array_values((array) array_get($this->config, 'dims'));
    }

    /** @return CustomTable|null */
    public function sourceTable()
    {
        return CustomTable::getEloquent(array_get($this->config, 'source_table'));
    }

    /** @return string|null */
    public function rootLabel()
    {
        $label = array_get($this->config, 'root_label');
        return is_nullorempty($label) ? null : $label;
    }

    /**
     * Fixed option-list scope (`scope` = {column: value}) as column => normalized spec —
     * a dashboard about ONE entity (a school, a branch) lists only that entity's values in
     * every filter item, on the bar and in the chart-level filter popovers alike. Junk
     * entries (non-identifier column, empty value) are dropped; callers still check that
     * the column exists on the table they list from.
     *
     * @return array<string, array>
     */
    public function scope()
    {
        $out = [];
        foreach ((array) array_get($this->config, 'scope', []) as $col => $val) {
            if (FilterState::isIdentifier($col) && FilterState::spec($val) !== null) {
                $out[$col] = FilterState::spec($val);
            }
        }
        return $out;
    }

    /**
     * Cardinality cap per dim. Same fallback as buildDashboardFilterContext: missing,
     * non-numeric or <=0 → 500.
     *
     * @return int
     */
    public function maxOptions()
    {
        $max = (int) array_get($this->config, 'max_options', 500);
        return $max > 0 ? $max : 500;
    }

    /**
     * Hierarchy-chain dim columns in declared root→leaf order (see class doc).
     *
     * @return string[]
     */
    public function chainColumns()
    {
        if ($this->chain !== null) {
            return $this->chain;
        }
        $parents = [];
        foreach ($this->parents() as $col => $parent) {
            if ($parent !== null) {
                $parents[$parent] = true;
            }
        }
        $chain = [];
        foreach ($this->parents() as $col => $parent) {
            if ($parent === null && !isset($parents[$col])) {
                continue; // independent cross-cut (e.g. a measure band) — not a level
            }
            $chain[] = $col;
        }
        return $this->chain = $chain;
    }

    /**
     * Effective parent column of one dim (null = independent). Explicit config first
     * ('-' = forced none), else the metadata inference (see class doc / inferredParentOf).
     *
     * @param string $column
     * @return string|null
     */
    public function parentOf($column)
    {
        return $this->parents()[$column] ?? null;
    }

    /**
     * The parent the metadata inference alone yields for one dim (what the admin form shows
     * as 「自動: …」), regardless of any explicit setting. null = independent.
     *
     * @param string $column
     * @return string|null
     */
    public function inferredParentOf($column)
    {
        return $this->inferredParents()[$column] ?? null;
    }

    /**
     * Whether the dim's parent is set explicitly (a column or '-') rather than inferred.
     *
     * @param string $column
     * @return bool
     */
    public function isParentExplicit($column)
    {
        foreach ($this->dims() as $dim) {
            if (array_get($dim, 'column') === $column) {
                return !is_nullorempty(array_get($dim, 'parent'));
            }
        }
        return false;
    }

    /**
     * Effective parents of every dim, in declared order (column => parent|null).
     *
     * @return array<string, string|null>
     */
    public function parents()
    {
        if ($this->parents !== null) {
            return $this->parents;
        }
        $out = [];
        $columns = [];
        foreach ($this->dims() as $dim) {
            $col = array_get($dim, 'column');
            if (!is_nullorempty($col)) {
                $columns[] = $col;
            }
        }
        foreach ($this->dims() as $dim) {
            $col = array_get($dim, 'column');
            if (is_nullorempty($col)) {
                continue;
            }
            $explicit = array_get($dim, 'parent');
            if (!is_nullorempty($explicit)) {
                // forced: a real dim above (self / unknown / '-' → none)
                $out[$col] = ($explicit !== static::PARENT_NONE && $explicit !== $col && in_array($explicit, $columns, true))
                    ? $explicit : null;
                continue;
            }
            $out[$col] = $this->inferredParents()[$col] ?? null;
        }
        return $this->parents = $out;
    }

    /**
     * Metadata inference for every dim (column => parent|null), explicit config ignored.
     * Reads only Exment's own structure: the source table's select_table columns and the
     * select_table columns of the masters they point at (custom_columns — cached models,
     * no data queries).
     *
     * @return array<string, string|null>
     */
    public function inferredParents()
    {
        if ($this->inferred !== null) {
            return $this->inferred;
        }
        $out = [];
        $source = $this->sourceTable();
        $dims = $this->dims();
        // master table of each dim (null when the column is not a select_table reference)
        $masterOf = [];
        foreach ($dims as $dim) {
            $col = array_get($dim, 'column');
            if (is_nullorempty($col)) {
                continue;
            }
            $masterOf[$col] = null;
            if ($source) {
                $columnModel = $source->custom_columns->firstWhere('column_name', $col);
                if ($columnModel && $columnModel->column_type === 'select_table') {
                    $target = $columnModel->select_target_table;
                    $masterOf[$col] = $target ? (int) $target->id : null;
                }
            }
        }
        $above = [];
        foreach ($masterOf as $col => $master) {
            $out[$col] = null;
            if ($master !== null) {
                // candidates: dims above whose master this dim's master reaches by reference
                $candidates = [];
                foreach ($above as $acol) {
                    $am = $masterOf[$acol];
                    if ($am !== null && $am !== $master && $this->masterReaches($master, $am)) {
                        $candidates[] = $acol;
                    }
                }
                // keep the NEAREST: drop a candidate that is an ancestor of another candidate
                $keep = [];
                foreach ($candidates as $c) {
                    $isAncestorOfOther = false;
                    foreach ($candidates as $o) {
                        if ($o !== $c && $this->masterReaches($masterOf[$o], $masterOf[$c])) {
                            $isAncestorOfOther = true;
                            break;
                        }
                    }
                    if (!$isAncestorOfOther) {
                        $keep[] = $c;
                    }
                }
                // ties (unrelated candidates, e.g. school and grade for a class) → declared closest above
                $out[$col] = empty($keep) ? null : end($keep);
            }
            $above[] = $col;
        }
        return $this->inferred = $out;
    }

    /**
     * Whether master table $fromId references master table $toId through select_table
     * columns, directly or via intermediate masters (breadth-first, at most REF_HOPS hops).
     *
     * @param int $fromId
     * @param int $toId
     * @return bool
     */
    protected function masterReaches($fromId, $toId)
    {
        static $refCache = [];
        $frontier = [(int) $fromId];
        $seen = [(int) $fromId => true];
        for ($hop = 0; $hop < static::REF_HOPS && !empty($frontier); $hop++) {
            $next = [];
            foreach ($frontier as $tableId) {
                if (!array_key_exists($tableId, $refCache)) {
                    $refCache[$tableId] = [];
                    $table = CustomTable::getEloquent($tableId);
                    if ($table) {
                        foreach ($table->custom_columns as $c) {
                            if ($c->column_type === 'select_table') {
                                $t = $c->select_target_table;
                                if ($t) {
                                    $refCache[$tableId][] = (int) $t->id;
                                }
                            }
                        }
                    }
                }
                foreach ($refCache[$tableId] as $ref) {
                    if ($ref === (int) $toId) {
                        return true;
                    }
                    if (!isset($seen[$ref])) {
                        $seen[$ref] = true;
                        $next[] = $ref;
                    }
                }
            }
            $frontier = $next;
        }
        return false;
    }

    /**
     * Whether $column is part of the hierarchy chain (vs an independent cross-cut).
     *
     * @param string $column
     * @return bool
     */
    public function isChainColumn($column)
    {
        return in_array($column, $this->chainColumns(), true);
    }
}
