<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;

/**
 * Builds the dashboard filter bar's render context from a dashboard's
 * `options.filter_bar` config (see FilterBarConfig for the schema) and the current
 * df_* request state. Extracted 1:1 from DashboardController — the controller keeps a
 * thin delegate so its public surface (and the blade contract) is unchanged.
 *
 * Responsibilities: per-dim option lists (distinct-on-data or master-driven, cascade-
 * scoped by selected ancestors, cardinality-capped, labels resolved + de-duplicated),
 * strict-cascade gating, mutual exclusion, breadcrumb and note rows.
 *
 * Everything here is config- and data-driven; no table or column name is special.
 */
class FilterBarContextBuilder
{
    /**
     * Render context for one dashboard's filter bar, or null when the dashboard has no
     * usable filter_bar config (the blade then renders no bar at all).
     *
     * Each dim's options are the DISTINCT values of its column on source_table, narrowed by
     * the currently-selected ancestor dims — so the cascade only ever offers values that have
     * data. A dim flagged `from_master` lists its MASTER records instead (see
     * masterColumnOptions), so a record registered before it has any data is still selectable,
     * flagged `no_data`. When a dim's column is a select_table, its ids are resolved to the
     * target record's label.
     *
     * @param mixed $dashboard  Dashboard model
     * @param Request $request
     * @return array|null
     */
    public function build($dashboard, Request $request)
    {
        $config = $dashboard->getOption('filter_bar');
        if (is_nullorempty($config) || empty($config['dims']) || !is_array($config['dims'])) {
            return null;
        }
        $source = CustomTable::getEloquent(array_get($config, 'source_table'));
        if (is_nullorempty($source)) {
            return null;
        }
        $sourceColumns = $source->custom_columns->pluck('column_name')->all();

        // Current selection: df_{column} on the request, for each configured dim — kept as
        // its normalized spec (single value / list / range, see FilterState::spec) so the same
        // value can scope the cascade queries and be echoed back into the field.
        $sel = [];
        foreach ($config['dims'] as $dim) {
            $col = array_get($dim, 'column');
            $sel[$col] = $col ? FilterState::spec($request->input('df_' . $col)) : null;
        }

        // Mutual exclusion: a dim config may declare 'disables' => [columns]. While that dim
        // is selected, the listed dims render DISABLED and their selections are dropped from
        // the bar (so the next navigation loses them) — used when combining the filters would
        // produce numbers that contradict each other on screen (e.g. a score-range band
        // defined on the overall average combined with a per-subject view). Symmetric by
        // construction: while the disabled dim is somehow active first, picking the disabling
        // dim clears it client-side (data-disables in the blade JS).
        $disabledBy = [];
        foreach ($config['dims'] as $dim) {
            $col = array_get($dim, 'column');
            if (is_nullorempty($col) || ($sel[$col] ?? null) === null) {
                continue;
            }
            foreach ((array) array_get($dim, 'disables', []) as $target) {
                if (is_string($target) && $target !== '') {
                    $disabledBy[$target] = array_get($dim, 'label', $col);
                }
            }
        }
        foreach ($disabledBy as $target => $byLabel) {
            $sel[$target] = null; // dropped — boxes may still see a deep-linked df once; the bar never re-emits it
        }

        $barConfig = FilterBarConfig::fromDashboard($dashboard);

        // Cardinality guard: a dim whose (scoped) distinct count exceeds this is not listed —
        // it renders disabled with a "narrow a higher filter first" hint instead of dumping
        // thousands of <option>s into the page. Per-dashboard override: options.filter_bar.max_options.
        $maxOptions = $barConfig ? $barConfig->maxOptions() : 500;

        // Fixed scope (options.filter_bar.scope = {column: value, ...}): a dashboard that is
        // ABOUT one entity — one school, one branch — lists only that entity's values in every
        // item (a class list of 12, not 1,536), while the entity itself is not an item on the
        // bar. Purely an option-list scope: the boxes' own views carry the matching static
        // filter, and FilterState never reads it. Same value shapes as a selection.
        $fixedScope = [];
        foreach ($barConfig ? $barConfig->scope() : [] as $scol => $sspec) {
            if (in_array($scol, $sourceColumns, true)) {
                $fixedScope[$scol] = $sspec;
            }
        }

        $dims = [];
        foreach ($config['dims'] as $dim) {
            $col    = array_get($dim, 'column');
            $parent = $barConfig ? $barConfig->parentOf($col) : null; // effective: explicit or metadata-inferred
            if (is_nullorempty($col) || !in_array($col, $sourceColumns, true)) {
                continue; // config references a column the source table doesn't have — skip safely
            }

            // Relevant-values cascade (BI standard, like a Power BI slicer): EVERY dim is
            // selectable from the start — its options are the distinct values within the scope of
            // whatever ancestor dims are currently selected (nothing selected → the full list).
            // The only thing that disables a dim is the cardinality cap below, so a user can jump
            // straight to any level (e.g. pick the school without walking region→pref→city).
            // Narrow the distinct query by every ancestor dim that currently has a selection
            // (the fixed scope, when configured, is the outermost ancestor of them all).
            $ancestors = $fixedScope;
            unset($ancestors[$col]); // a scope on the dim's own column would only re-list itself
            foreach ($config['dims'] as $a) {
                $acol = array_get($a, 'column');
                if ($acol === $col) {
                    break; // only dims declared BEFORE this one are ancestors
                }
                if ($acol && in_array($acol, $sourceColumns, true) && ($sel[$acol] ?? null) !== null) {
                    $ancestors[$acol] = $sel[$acol];
                }
            }
            // Where the choices come from: by default the values present in the DATA. A dim
            // flagged `from_master` lists the master records instead, so one registered before it
            // has any data (a student added today, a region with no school yet) is still offered.
            $fromMaster = boolval(array_get($dim, 'from_master', false));

            // Control style: a multi-select over the distinct values, or a from/to RANGE — the
            // admin's choice (dim option `style`), else auto by column type (numbers / dates get
            // the range; a dropdown over every distinct amount is unusable and only hits the cap).
            $columnModel = $source->custom_columns->firstWhere('column_name', $col);
            $style = FilterState::style($columnModel, array_get($dim, 'style'));
            $kind  = FilterState::kind($columnModel);
            $selected = $sel[$col] ?? null;
            $selectedValues = isset($selected['in']) ? $selected['in'] : [];

            $capped = false;
            $options = [];
            if ($style === 'select') {
                $options = $this->dimColumnOptions($source, $col, $ancestors, $maxOptions + 1, $fromMaster);
                $capped  = count($options) > $maxOptions;
                if ($capped) {
                    // Too many values to list (e.g. the student dim near the top of the tree). If the
                    // dim IS selected anyway (deep link, click-to-filter), resolve just those values so
                    // the select shows them and they stay clearable.
                    $options = empty($selectedValues)
                        ? []
                        : $this->dimColumnOptions($source, $col, $ancestors + [$col => $selected], null, $fromMaster);
                }
                if ($fromMaster && !empty($options)) {
                    $options = $this->flagOptionsWithoutData($source, $col, $ancestors, $options, $maxOptions + 1);
                }
                // A selected value the current ancestor scope does not offer (a deep link, or a
                // child picked before its parent moved elsewhere) still FILTERS — so it must
                // stay visible and clearable, never silently rendered as "(all)". Resolve just
                // those values (no ancestor scope, only the dim's own column) and append them.
                $offered = array_map('strval', array_column($options, 'id'));
                $missing = array_values(array_diff(array_map('strval', $selectedValues), $offered));
                if (!empty($missing)) {
                    foreach ($this->dimColumnOptions($source, $col, [$col => ['in' => $missing]], null, $fromMaster) as $opt) {
                        $opt['out_of_scope'] = true;
                        $options[] = $opt;
                    }
                }
            }
            // The range field echoes the bounds back. A plain value on a range dim (deep link,
            // click-to-filter, an older bar) still filters as an equality; the field shows it as
            // from = to = value (several values: their min / max), so it stays visible and editable.
            $range = ['from' => '', 'to' => ''];
            if ($selected !== null && !isset($selected['in'])) {
                $range = ['from' => (string) ($selected['from'] ?? ''), 'to' => (string) ($selected['to'] ?? '')];
            } elseif ($style === 'range' && !empty($selectedValues)) {
                $sorted = $selectedValues;
                usort($sorted, function ($a, $b) {
                    return (is_numeric($a) && is_numeric($b)) ? (($a + 0) <=> ($b + 0)) : strcmp($a, $b);
                });
                $range = ['from' => (string) reset($sorted), 'to' => (string) end($sorted)];
            }

            $dims[] = [
                'column'   => $col,
                'label'    => array_get($dim, 'label', $col),
                'options'  => $options,
                // the single selected value ('' when none / several / a range) — legacy field
                'selected' => count($selectedValues) === 1 ? $selectedValues[0] : '',
                // every selected value (multi-select), request order
                'selected_values' => $selectedValues,
                // 'select' | 'range' — which control the field renders (see FilterState::style)
                'style'    => $style,
                // 'number' | 'date' | 'datetime' | 'text' — input type / compare rule of a range
                'kind'     => $kind,
                'range'    => $range,
                'enabled'  => (!$capped || $selected !== null) && !isset($disabledBy[$col]),
                // capped && !enabled → blade shows the "narrow a higher filter first" hint.
                'capped'   => $capped,
                // mutual exclusion (see above): label of the dim currently locking this one,
                // '' when free — the blade shows it as the disabled-tooltip.
                'disabled_by' => $disabledBy[$col] ?? '',
                // columns this dim locks while selected — blade JS clears them on change.
                'disables' => array_values(array_filter((array) array_get($dim, 'disables', []), 'is_string')),
                // parent link, for the blade's descendant map (changing OR clearing a dim resets
                // the dims cascading under it; independent dims keep their selection).
                'parent'   => is_nullorempty($parent) ? '' : $parent,
                // advanced grouping is strictly OPT-IN per dim (config flag / admin switch).
                // With nothing flagged the bar renders as one flat row — no toggle, no split.
                'advanced' => boolval(array_get($dim, 'advanced', false)),
            ];
        }

        // Advanced-group state for the blade: badge count = advanced dims currently filtered;
        // the group opens itself whenever one of its dims is active (so a deep link / click-to-
        // filter selection is never hidden), otherwise starts collapsed.
        $advancedCount = 0;
        foreach ($dims as $d) {
            if (!empty($d['advanced']) && ($sel[$d['column']] ?? null) !== null) {
                $advancedCount++;
            }
        }

        // Breadcrumb (drill orientation) + per-dim caution notes. Chain dims = linked by a
        // parent edge (the same definition the level-view logic uses); the crumb lists each
        // ACTIVE chain dim's selected label in declared root→leaf order after the root label
        // (config filter_bar.root_label, e.g. 全国). Selections may be non-contiguous — the
        // crumb simply shows what is selected. A dim config may carry a 'note' string that is
        // rendered as a warning while that dim is filtered (e.g. "score-band biases averages").
        // chain membership from the EFFECTIVE parents (explicit or metadata-inferred)
        $parents = [];
        foreach ($barConfig ? $barConfig->parents() : [] as $c => $p) {
            if ($p !== null) {
                $parents[$p] = true;
            }
        }
        $noteOf = [];
        foreach ($config['dims'] as $d) {
            $c = array_get($d, 'column');
            $n = array_get($d, 'note');
            if ($c && !is_nullorempty($n)) {
                $noteOf[$c] = (string) $n;
            }
        }
        $breadcrumb = [];
        $notes = [];
        $hasChain = false;
        foreach ($dims as $d) {
            $isChain = !is_nullorempty($d['parent']) || isset($parents[$d['column']]);
            if ($isChain) {
                $hasChain = true;
            }
            $spec = $sel[$d['column']] ?? null;
            if ($spec === null) {
                continue;
            }
            if (isset($noteOf[$d['column']])) {
                $notes[] = $noteOf[$d['column']];
            }
            if ($isChain) {
                // one crumb per chain dim; several selected values join into one crumb
                // ("関東, 関西"), a range reads "from – to"
                $names = [];
                foreach (FilterState::values($spec) as $v) {
                    $name = (string) $v;
                    foreach ($d['options'] as $opt) {
                        if ((string) $opt['id'] === (string) $v) {
                            $name = $opt['name'];
                            break;
                        }
                    }
                    $names[] = $name;
                }
                $breadcrumb[] = ['column' => $d['column'], 'label' => implode(', ', $names)];
            }
        }

        // Detailed Filter panel (Power-BI-style advanced conditions): the Field select lists
        // this source table's own columns, and the panel opens itself whenever a condition is
        // active so a deep-linked condition is never hidden behind the collapse.
        $advConditions = AdvancedFilter::conditions();
        $advColumns = [];
        foreach ($source->custom_columns as $column) {
            $advColumns[] = ['name' => $column->column_name, 'label' => $column->column_view_name];
        }

        return [
            'adv_columns'     => $advColumns,
            'adv_operators'   => AdvancedFilter::OPERATORS,
            'adv_valueless'   => AdvancedFilter::VALUELESS,
            'adv_conditions'  => $advConditions,
            'adv_open'        => count($advConditions) > 0,
            'dims'            => $dims,
            // null = the config has no hierarchy chain at all → no crumb row
            'breadcrumb'      => $hasChain ? $breadcrumb : null,
            'root_label'      => array_get($config, 'root_label') ?: exmtrans('dashboard.filter_bar.root_all'),
            'notes'           => $notes,
            'has_selection'   => (bool) array_filter($sel, function ($v) { return $v !== null; }),
            'advanced_count'  => $advancedCount,
            'advanced_open'   => $advancedCount > 0,
            'dashboard_suuid' => $dashboard->suuid,
        ];
    }

    /**
     * PUBLIC option list for one column of a table — the chart-level filter strip
     * (box option chart_filters, ChartItem::boxFilterHtml) reuses the exact machinery
     * of the bar dims: distinct-on-data (index-backed, capped), labels resolved via
     * select_table / select_valtext, duplicate labels disambiguated.
     *
     * @param CustomTable $table
     * @param string $column
     * @param array $scope  column => value already narrowing the box (df + other bf; any value shape)
     * @param int|null $limit
     * @return array [{id, name}]
     */
    public function columnOptions(CustomTable $table, $column, array $scope = [], $limit = null)
    {
        return $this->distinctColumnOptions($table, $column, $scope, $limit);
    }

    /**
     * DISTINCT values of one column on a table (optionally narrowed by ancestor column=value
     * pairs), returned as [{id, name}] with labels resolved via the column's select_table target
     * when applicable. Column names are validated as plain identifiers before touching SQL.
     *
     * @param CustomTable $source
     * @param string $column
     * @param array $ancestors  column => value, any shape (all must be plain-identifier columns on $source)
     * @param int|null $limit   stop after this many distinct values (cardinality-cap probe)
     * @return array
     */
    protected function distinctColumnOptions(CustomTable $source, $column, array $ancestors = [], $limit = null)
    {
        $values = $this->distinctColumnValues($source, $column, $ancestors, $limit);

        if ($values->isEmpty()) {
            return [];
        }

        // Resolve labels: a select_table column stores target ids → show the target's label;
        // a select_valtext column stores keys → show the configured "key,label" text.
        $labelMap = [];
        $columnModel = $source->custom_columns->firstWhere('column_name', $column);
        $targetTable = $columnModel ? $columnModel->select_target_table : null;
        if ($targetTable) {
            foreach ($targetTable->getValueQuery()->whereIn('id', $values->all())->get() as $r) {
                $labelMap[(string) $r->id] = $r->getLabel();
            }
        } elseif ($columnModel) {
            $valtext = $columnModel->getOption('select_item_valtext');
            if (is_string($valtext) && $valtext !== '') {
                foreach (preg_split('/\r\n|\r|\n/', $valtext) as $line) {
                    $parts = explode(',', $line, 2);
                    if (count($parts) === 2 && trim($parts[0]) !== '') {
                        $labelMap[trim($parts[0])] = trim($parts[1]);
                    }
                }
            }
        }

        $options = $values->map(function ($v) use ($labelMap) {
            return ['id' => $v, 'name' => (string) ($labelMap[$v] ?? $v)];
        })->all();

        return $this->uniqueOptionLabels($options);
    }

    /**
     * The DISTINCT raw values of one column on a table, narrowed by ancestor column => value
     * pairs, sorted numeric-aware. Column names are validated as plain identifiers.
     *
     * @param CustomTable $source
     * @param string $column
     * @param array $ancestors
     * @param int|null $limit
     * @return \Illuminate\Support\Collection
     */
    protected function distinctColumnValues(CustomTable $source, $column, array $ancestors = [], $limit = null)
    {
        if (!FilterState::isIdentifier($column)) {
            return collect();
        }
        $query = \DB::table('exm__' . $source->suuid);

        foreach ($ancestors as $acol => $aval) {
            if (!FilterState::isIdentifier($acol)) {
                continue;
            }
            // any value shape (single / list / range) — the same rule the box query applies
            FilterState::whereExpr(
                $query,
                $this->columnValueExpr($source, $acol),
                $aval,
                FilterState::kind($source->custom_columns->firstWhere('column_name', $acol))
            );
        }
        // Alias the (indexed or extracted) value as `v` and DISTINCT on it (pluck can't take a raw
        // expression, and ORDER BY a non-selected expr under DISTINCT is rejected — sort in PHP).
        // LIMIT applies AFTER DISTINCT, so a caller probing the cardinality cap fetches at most
        // cap+1 values instead of the full list; sorting a truncated set is fine — the caller
        // discards an over-cap result entirely.
        if ($limit !== null && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        return $query->selectRaw($this->columnValueExpr($source, $column) . ' as v')->distinct()->pluck('v')
            ->filter(function ($v) { return $v !== null && $v !== ''; })
            ->map(function ($v) { return (string) $v; })
            ->unique()
            ->sort(function ($a, $b) {
                // numeric-aware: ids sort 1,2,10 not "1","10","2"; falls back to string compare.
                if (is_numeric($a) && is_numeric($b)) {
                    return ($a + 0) <=> ($b + 0);
                }
                return strcmp($a, $b);
            })
            ->values();
    }

    /**
     * Options for one filter-bar dim: the master records when the dim asks for them, the values
     * present in the data otherwise (and as the fallback when the column has no master to read).
     *
     * @param CustomTable $source
     * @param string $column
     * @param array $ancestors  column => value of the ancestor dims currently selected
     * @param int|null $limit
     * @param bool $fromMaster
     * @return array
     */
    protected function dimColumnOptions(CustomTable $source, $column, array $ancestors, $limit, $fromMaster)
    {
        if ($fromMaster) {
            $options = $this->masterColumnOptions($source, $column, $ancestors, $limit);
            if (isset($options)) {
                return $options;
            }
            // no master behind this column (plain text / number / date) — the data is all there is
        }

        return $this->distinctColumnOptions($source, $column, $ancestors, $limit);
    }

    /**
     * Options read from the MASTER a dim's column points at, instead of from the values that
     * happen to be in the data (dim config `from_master`).
     *
     * Rationale: a master record with no rows yet is still a real choice — a student registered
     * before their first exam, a region added this morning. The cascade still narrows the list,
     * matching ancestors against the MASTER's own columns; that works because a master normally
     * carries its whole FK chain (m_city has prefecture + region, m_student has all six). An
     * ancestor the master does not carry cannot be applied from here and is skipped, so a dim
     * whose master holds only part of the chain is better left on the data-driven list.
     *
     * A select / select_valtext column has no master table — its configured items ARE the master,
     * so the whole item list is returned (every score band, both semesters, used yet or not).
     *
     * @param CustomTable $source
     * @param string $column
     * @param array $ancestors
     * @param int|null $limit
     * @return array|null  null = nothing to read here; the caller falls back to the data
     */
    protected function masterColumnOptions(CustomTable $source, $column, array $ancestors = [], $limit = null)
    {
        if (!FilterState::isIdentifier($column)) {
            return null;
        }
        $columnModel = $source->custom_columns->firstWhere('column_name', $column);
        if (!isset($columnModel)) {
            return null;
        }

        $target = $columnModel->select_target_table;
        if (!isset($target)) {
            $items = $this->selectItemOptions($columnModel);
            return count($items) ? $items : null;
        }

        $query = $target->getValueQuery();
        foreach ($ancestors as $acol => $aval) {
            if (!FilterState::isIdentifier($acol)) {
                continue;
            }
            if ($acol === $column) {
                // the dim's own value(s) (capped-but-selected re-resolve) → the master rows themselves
                $own = FilterState::spec($aval);
                if ($own !== null && isset($own['in'])) {
                    $query->whereIn('id', $own['in']);
                }
                continue;
            }
            $ancestorColumn = $target->custom_columns->firstWhere('column_name', $acol);
            if (!isset($ancestorColumn)) {
                continue; // the master does not carry this ancestor — it cannot narrow by it
            }
            FilterState::whereExpr($query, $this->columnValueExpr($target, $acol), $aval, FilterState::kind($ancestorColumn));
        }

        // Ordered by id = the order the master was registered in, which for a code-driven master
        // is its natural order. Labels come hydrated, so unlike the data-driven path there is no
        // second query to resolve them.
        $query->orderBy('id');
        if ($limit !== null && (int) $limit > 0) {
            $query->limit((int) $limit);
        }

        $options = [];
        foreach ($query->get() as $record) {
            $label = $record->getLabel();
            $options[] = [
                'id'   => (string) $record->id,
                'name' => is_nullorempty($label) ? (string) $record->id : (string) $label,
            ];
        }

        return $this->uniqueOptionLabels($options);
    }

    /**
     * The configured choices of a select / select_valtext column, in their configured order:
     * `key,label` lines for a valtext column, plain lines for a select.
     *
     * @param CustomColumn $columnModel
     * @return array
     */
    protected function selectItemOptions(CustomColumn $columnModel)
    {
        $valtext = $columnModel->getOption('select_item_valtext');
        $isValtext = is_string($valtext) && $valtext !== '';
        $lines = $isValtext ? $valtext : $columnModel->getOption('select_item');
        if (!is_string($lines) || $lines === '') {
            return [];
        }

        $options = [];
        foreach (preg_split('/\r\n|\r|\n/', $lines) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (!$isValtext) {
                $options[] = ['id' => $line, 'name' => $line];
                continue;
            }
            $parts = explode(',', $line, 2);
            $key = trim($parts[0]);
            if ($key !== '') {
                $options[] = ['id' => $key, 'name' => count($parts) === 2 ? trim($parts[1]) : $key];
            }
        }

        return $options;
    }

    /**
     * Flag the options that have no row in the source table within the current scope (`no_data`),
     * so the bar can offer them while saying they will draw an empty dashboard.
     *
     * Only a master-driven list can hold such a value — the data-driven one is, by definition,
     * values that exist. So the flag is the difference between the two lists, and it reuses the
     * very same DISTINCT the data-driven path runs, cap and all: an IN(…) restricted to the
     * options on screen reads like the cheaper query but is not (measured 38s against ms on the
     * 384k-row fact), and dropping the LIMIT costs another 3x for the same rows.
     *
     * @param CustomTable $source
     * @param string $column
     * @param array $ancestors
     * @param array $options
     * @param int|null $limit  cardinality cap; a saturated probe flags nothing (see below)
     * @return array
     */
    protected function flagOptionsWithoutData(CustomTable $source, $column, array $ancestors, array $options, $limit = null)
    {
        $values = $this->distinctColumnValues($source, $column, $ancestors, $limit);
        if ($limit !== null && count($values) >= (int) $limit) {
            // Truncated by the cap, so a value missing from it may still have data — say nothing
            // rather than mislabel. Only reachable if the data holds more distinct values than
            // the master listed, i.e. ids pointing at records that no longer exist.
            return $options;
        }

        $withData = [];
        foreach ($values as $v) {
            $withData[(string) $v] = true;
        }

        return array_map(function ($opt) use ($withData) {
            $opt['no_data'] = !isset($withData[(string) $opt['id']]);
            return $opt;
        }, $options);
    }

    /**
     * SQL expression reading one custom column of a table (FilterState::columnExpr, looked up
     * by name; every caller validates $column as a plain identifier first).
     *
     * @param CustomTable $table
     * @param string $column
     * @return string
     */
    protected function columnValueExpr(CustomTable $table, $column)
    {
        return FilterState::columnExpr($table->custom_columns->firstWhere('column_name', $column), $column);
    }

    /**
     * Disambiguate duplicate labels (e.g. two same-name students, two same-name wards): every
     * option in a filter select must be tellable apart, so non-unique labels get the raw value
     * appended. Raw values are distinct by construction, so the result is unique.
     *
     * @param array $options
     * @return array
     */
    protected function uniqueOptionLabels(array $options)
    {
        $labelCounts = array_count_values(array_column($options, 'name'));

        return array_map(function ($opt) use ($labelCounts) {
            if ($labelCounts[$opt['name']] > 1 && $opt['name'] !== (string) $opt['id']) {
                $opt['name'] .= ' #' . $opt['id'];
            }
            return $opt;
        }, $options);
    }
}
