<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Enums\ColumnType;

/**
 * Filter-bar "fitness check" (適合チェック) shown on the dashboard setting screen: which of the
 * dashboard's boxes a filter on the chosen source table would actually reach, why a box is
 * missed (no such column / type mismatch / no value in common), and which table would reach
 * the most boxes. Advisory only — the runtime filtering never consults it.
 *
 * Mirrors the runtime rule exactly (a box is filtered only when it is a CHART box whose own
 * table carries the filter column — see DashboardBoxController::filterUnaffectedBadge and
 * FilterState::columnsOn), so the setting screen and the runtime badges can never disagree.
 */
class FilterBarCoverage
{
    /**
     * Which of this dashboard's boxes a filter on $table_name would actually reach.
     *
     * Mirrors DashboardBoxController::filterUnaffectedBadge exactly — a box is filtered only
     * when it is a CHART box (no other box type applies df_* params) whose own table carries at
     * least one of the filter columns — so the setting screen and the runtime badges can never
     * disagree.
     *
     * @param Dashboard|null $dashboard
     * @param string|null $table_name  chosen source table
     * @param array<int, string> $dims  configured dim columns; empty = every column of the source table
     * @param bool $deep  also probe whether the two columns hold any value in common (costs a
     *                    handful of indexed queries — off for the table-scoring scan)
     * @return array{boxes: array<int, array<string, mixed>>, covered: int, total: int, reach: int, columns: array<int, string>}|null
     */
    public static function coverage($dashboard, $table_name, array $dims = [], $deep = false)
    {
        if (is_nullorempty($dashboard) || is_nullorempty($table_name)) {
            return null;
        }
        $source = CustomTable::getEloquent($table_name);
        if (is_nullorempty($source)) {
            return null;
        }

        $sourceColumns = $source->custom_columns->keyBy('column_name');
        // Only columns that really exist on the source table can ever become filters.
        $columns = count($dims)
            ? array_values(array_intersect($dims, $sourceColumns->keys()->all()))
            : $sourceColumns->keys()->all();

        $boxes = [];
        $covered = 0;
        $reach = 0;      // total (box, applicable column) pairs — how deeply the filter bites
        $samples = [];   // column => sample of the source's distinct values, reused across boxes
        $probes = [];    // "boxTableId:column" => bool; boxes commonly share a table, so the
                         // overlap probe must run once per TABLE, not once per box
        foreach ($dashboard->dashboard_boxes as $box) {
            $boxTable = CustomTable::getEloquent(array_get($box->options ?? [], 'target_table_id'));
            if (is_nullorempty($boxTable)) {
                continue; // no data table (system news, html, ...) — makes no data claim
            }

            $isChart = ($box->dashboard_box_type == DashboardBoxType::CHART);
            $boxColumns = $boxTable->custom_columns->keyBy('column_name');

            // A shared name is not enough: the filter compares STORED VALUES as text, so a
            // column pair that cannot hold the same value would filter to zero rows. Split the
            // name matches into ones that can really work and ones that only look like they do:
            //   mismatched — the value TYPES cannot ever be equal (id vs label)
            //   novalue    — types agree but the two columns hold no value in common, which is
            //                what an accidental name collision looks like (`student.name` vs
            //                `quote.name`): the filter would select nothing.
            $matched = [];
            $mismatched = [];
            $novalue = [];
            if ($isChart) {
                foreach ($columns as $column_name) {
                    $boxColumn = $boxColumns->get($column_name);
                    if (is_nullorempty($boxColumn)) {
                        continue;
                    }
                    if (!static::columnsComparable($sourceColumns->get($column_name), $boxColumn)) {
                        $mismatched[] = $column_name;
                        continue;
                    }
                    // Same physical table as the source → the values are trivially shared.
                    if ($deep && $boxTable->id != $source->id) {
                        $probeKey = $boxTable->id . ':' . $column_name;
                        if (!array_key_exists($probeKey, $probes)) {
                            if (!array_key_exists($column_name, $samples)) {
                                $samples[$column_name] = static::sampleValues($source, $column_name);
                            }
                            $probes[$probeKey] = static::hasAnyValue($boxTable, $column_name, $samples[$column_name]);
                        }
                        if (!$probes[$probeKey]) {
                            $novalue[] = $column_name;
                            continue;
                        }
                    }
                    $matched[] = $column_name;
                }
            }

            if (count($matched)) {
                $covered++;
                $reach += count($matched);
            }
            $boxes[] = [
                'label'      => $box->dashboard_box_view_name,
                'table'      => $boxTable->table_view_name,
                'matched'    => $matched,
                'mismatched' => $mismatched,
                'novalue'    => $novalue,
                'reason'     => (count($matched) || count($mismatched) || count($novalue)) ? '' : ($isChart
                    ? exmtrans('dashboard.filter_bar.check_reason_columns')
                    : exmtrans('dashboard.filter_bar.check_reason_type')),
            ];
        }

        return [
            'boxes'   => $boxes,
            'covered' => $covered,
            'total'   => count($boxes),
            'reach'   => $reach,
            'columns' => $columns,
        ];
    }

    /**
     * Can a filter on $source really narrow $target, given they share a column name?
     *
     * applyDashboardFilter compares the STORED value as text, so the two columns must hold the
     * same kind of value. The test is deliberately narrow — it only rejects the id-vs-label
     * mismatch, which is the one pairing that silently yields zero rows:
     *
     *   - one side stores a record id (select_table / user / organization) and the other does
     *     not: id "1" can never equal the label "Hokkaido";
     *   - both are select_table but point at DIFFERENT tables: ids from unrelated tables would
     *     collide numerically and filter to something meaningless.
     *
     * Everything else (text vs text, integer vs decimal, …) is treated as comparable: the string
     * comparison can legitimately succeed, and crying wolf there would make the report useless.
     *
     * @param CustomColumn|null $source
     * @param CustomColumn|null $target
     * @return bool
     */
    protected static function columnsComparable($source, $target)
    {
        if (is_nullorempty($source) || is_nullorempty($target)) {
            return false;
        }

        $idTypes = [ColumnType::SELECT_TABLE, ColumnType::USER, ColumnType::ORGANIZATION];
        $sourceIsId = in_array($source->column_type, $idTypes, true);
        $targetIsId = in_array($target->column_type, $idTypes, true);

        if ($sourceIsId !== $targetIsId) {
            return false;
        }
        if (!$sourceIsId) {
            return true;
        }
        if ($source->column_type !== $target->column_type) {
            return false;
        }
        if ($source->column_type !== ColumnType::SELECT_TABLE) {
            return true; // user / organization always reference the same system table
        }

        return strval(array_get($source->options ?? [], 'select_target_table'))
            === strval(array_get($target->options ?? [], 'select_target_table'));
    }

    /**
     * SQL expression reading one column's stored value — the indexed generated column when the
     * column has an index, the JSON path otherwise. Same preference (and the same identifier
     * validation) as ChartItem::applyDashboardFilter, so a probe sees what the filter will see.
     *
     * @param CustomTable $table
     * @param string $column_name
     * @return string|null null when the column is unusable (missing / unsafe name)
     */
    protected static function valueExpr(CustomTable $table, $column_name)
    {
        if (!FilterState::isIdentifier($column_name)) {
            return null;
        }
        $column = $table->custom_columns->firstWhere('column_name', $column_name);
        if (is_nullorempty($column)) {
            return null;
        }

        return FilterState::columnExpr($column, $column_name);
    }

    /**
     * A small sample of the distinct values a filter column actually offers.
     *
     * @param CustomTable $table
     * @param string $column_name
     * @param int $limit
     * @return array<int, string>
     */
    protected static function sampleValues(CustomTable $table, $column_name, $limit = 50)
    {
        $expr = static::valueExpr($table, $column_name);
        if (is_nullorempty($expr)) {
            return [];
        }

        return \DB::table('exm__' . $table->suuid)
            ->selectRaw($expr . ' as v')->distinct()->limit((int) $limit)->pluck('v')
            ->filter(function ($v) {
                return $v !== null && $v !== '';
            })
            ->map(function ($v) {
                return (string) $v;
            })
            ->values()->all();
    }

    /**
     * Does $table hold any of $values in $column_name? A "no" on a same-named, same-typed column
     * is the signature of an accidental name collision between unrelated tables: the filter would
     * be applied and select nothing.
     *
     * Returns true when it cannot tell (no sample, unusable column) — the report must not invent
     * a problem it has not actually observed.
     *
     * This is a heuristic, not a proof: the sample is bounded, so a box table holding only a
     * narrow subset of the source's values can be flagged even though the columns do mean the
     * same thing. It therefore only ever WARNS — the filter itself is never blocked.
     *
     * @param CustomTable $table
     * @param string $column_name
     * @param array<int, string> $values
     * @return bool
     */
    protected static function hasAnyValue(CustomTable $table, $column_name, array $values)
    {
        if (empty($values)) {
            return true;
        }
        $expr = static::valueExpr($table, $column_name);
        if (is_nullorempty($expr)) {
            return true;
        }

        $placeholders = implode(',', array_fill(0, count($values), '?'));

        return \DB::table('exm__' . $table->suuid)
            ->whereRaw($expr . ' IN (' . $placeholders . ')', $values)
            ->exists();
    }

    /**
     * The source-table that would reach the most boxes, so the admin is told which table fits
     * instead of having to work it out. Scores every selectable table by how many of this
     * dashboard's chart boxes share a column name with it.
     *
     * @param Dashboard|null $dashboard
     * @return array{table_name: string, label: string, covered: int, total: int}|null
     */
    protected static function bestTable($dashboard)
    {
        if (is_nullorempty($dashboard)) {
            return null;
        }

        $best = null;
        foreach (CustomTable::filterList(null, ['with' => 'custom_columns']) as $table) {
            $coverage = static::coverage($dashboard, $table->table_name);
            if (is_nullorempty($coverage) || $coverage['covered'] === 0) {
                continue;
            }
            // Tie-break on `reach` (matched columns summed over the boxes), not on the box count
            // alone: a master table often touches the same boxes as the fact table but with far
            // fewer shared columns, which would leave most of the intended filters inert.
            if (
                is_null($best)
                || $coverage['covered'] > $best['covered']
                || ($coverage['covered'] === $best['covered'] && $coverage['reach'] > $best['reach'])
            ) {
                $best = [
                    'table_name' => $table->table_name,
                    'label'      => $table->table_view_name,
                    'covered'    => $coverage['covered'],
                    'total'      => $coverage['total'],
                    'reach'      => $coverage['reach'],
                ];
            }
        }

        return $best;
    }

    /**
     * Coverage panel for the setting screen: the recommended source table plus a per-box
     * "filtered / not filtered" list for the table currently chosen.
     *
     * @param Dashboard|null $dashboard
     * @param string|null $table_name
     * @param array<int, string> $dims
     * @return string
     */
    public static function html($dashboard, $table_name, array $dims = [])
    {
        $note = function ($text, $color = '#888') {
            return '<span class="help-block" style="color:' . $color . ';"><i class="fa fa-info-circle"></i>&nbsp;'
                . esc_html($text) . '</span>';
        };

        if (is_nullorempty($dashboard)) {
            // create screen: there are no boxes to check against yet
            return $note(exmtrans('dashboard.filter_bar.check_after_boxes'));
        }

        $best = static::bestTable($dashboard);
        $coverage = static::coverage($dashboard, $table_name, $dims, true);

        // When another table would apply more widely than the one selected, say so instead of
        // quietly printing the recommendation: picking a table that shares only a generic column
        // name (`name`, `code`, …) with the boxes is the easiest mistake to make here.
        $html = '';
        $betterExists = !is_nullorempty($best)
            && !is_nullorempty($coverage)
            && $coverage['covered'] > 0
            && $best['table_name'] !== $table_name
            && ($best['covered'] > $coverage['covered']
                || ($best['covered'] === $coverage['covered'] && $best['reach'] > $coverage['reach']));

        if ($betterExists) {
            $selected = CustomTable::getEloquent($table_name);
            $html .= $note(exmtrans('dashboard.filter_bar.check_recommend_better', [
                'selected'         => is_nullorempty($selected) ? strval($table_name) : $selected->table_view_name,
                'selected_covered' => $coverage['covered'],
                'selected_reach'   => $coverage['reach'],
                'best'             => $best['label'],
                'best_covered'     => $best['covered'],
                'best_reach'       => $best['reach'],
                'total'            => $coverage['total'],
            ]), '#8a6d1a');
        } elseif (!is_nullorempty($best)) {
            $html .= $note(exmtrans('dashboard.filter_bar.check_recommend', [
                'table'   => $best['label'],
                'covered' => $best['covered'],
                'total'   => $best['total'],
            ]), '#3c8dbc');
        }

        if (is_nullorempty($coverage)) {
            return $html . $note(exmtrans('dashboard.filter_bar.check_pick_table'));
        }
        if ($coverage['total'] === 0) {
            return $html . $note(exmtrans('dashboard.filter_bar.check_no_boxes'));
        }

        $allMissed = ($coverage['covered'] === 0);
        $html .= '<div style="margin-bottom:6px; font-weight:600; color:' . ($allMissed ? '#dd4b39' : '#00a65a') . ';">'
            . ($allMissed ? '<i class="fa fa-exclamation-triangle"></i>&nbsp;' : '<i class="fa fa-check"></i>&nbsp;')
            . esc_html(exmtrans('dashboard.filter_bar.check_covered', [
                'covered' => $coverage['covered'],
                'total'   => $coverage['total'],
            ]))
            . '</div>';

        $html .= '<table class="table table-bordered" style="margin-bottom:6px; background:#fff;"><tbody>';
        foreach ($coverage['boxes'] as $box) {
            $ok = count($box['matched']) > 0;
            $suspect = count($box['mismatched']) > 0 || count($box['novalue']) > 0;

            // Three verdicts, not two: a box whose ONLY name matches cannot actually select
            // anything (wrong value type, or no value in common) must not be reported as filtered.
            if ($ok) {
                $badge = '<span class="label label-success">' . esc_html(exmtrans('dashboard.filter_bar.check_box_ok')) . '</span>';
            } elseif ($suspect) {
                // name the actual reason: a wrong value TYPE and a disjoint value SET are
                // different mistakes and lead the admin to different fixes
                $badge = '<span class="label label-warning">' . esc_html(exmtrans(count($box['novalue'])
                    ? 'dashboard.filter_bar.check_box_novalue'
                    : 'dashboard.filter_bar.check_box_mismatch')) . '</span>';
            } else {
                $badge = '<span class="label label-default">' . esc_html(exmtrans('dashboard.filter_bar.check_box_ng')) . '</span>';
            }

            $detail = $ok ? esc_html(implode(', ', $box['matched'])) : esc_html($box['reason']);
            // shown next to the applicable columns too: that part of the filter is inert here
            foreach ([
                'check_reason_mismatch' => $box['mismatched'],
                'check_reason_novalue'  => $box['novalue'],
            ] as $key => $columns) {
                if (empty($columns)) {
                    continue;
                }
                $detail .= ($detail === '' ? '' : '<br />')
                    . '<span style="color:#8a6d1a;">' . esc_html(exmtrans('dashboard.filter_bar.' . $key, [
                        'columns' => implode(', ', $columns),
                    ])) . '</span>';
            }

            $html .= '<tr>'
                . '<td style="width:1%; white-space:nowrap;">' . $badge . '</td>'
                . '<td>' . esc_html($box['label']) . '<small style="color:#999;">&nbsp;(' . esc_html($box['table']) . ')</small></td>'
                . '<td style="color:#888;">' . $detail . '</td>'
                . '</tr>';
        }
        $html .= '</tbody></table>';

        if ($allMissed) {
            $html .= $note(exmtrans('dashboard.filter_bar.check_all_missed'), '#dd4b39');
        }

        return $html;
    }
}
