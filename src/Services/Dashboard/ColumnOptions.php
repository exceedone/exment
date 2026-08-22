<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Model\CustomTable;

/**
 * Option lists for a filter control: the DISTINCT values of one column of a table
 * (optionally narrowed by a scope), resolved to display labels.
 */
final class ColumnOptions
{
    /**
     * @param CustomTable $table
     * @param mixed $column  CustomColumn of $table
     * @param callable|null $scope  receives the query builder to narrow it
     * @param int $limit  cardinality cap; over it the list is withheld (capped = true)
     * @return array{options: array<int, array{id:string,name:string}>, capped: bool}
     */
    public static function distinct(CustomTable $table, $column, ?callable $scope = null, int $limit = FilterBarConfig::DEFAULT_MAX_OPTIONS): array
    {
        $expr = FilterValue::columnExpr($column);
        $query = $table->getValueQuery(); // the value model's query: soft-deleted rows excluded
        if ($scope !== null) {
            $scope($query);
        }
        $values = $query
            ->whereRaw("{$expr} IS NOT NULL AND {$expr} <> ''")
            ->selectRaw("{$expr} as v")
            ->distinct()
            ->limit($limit + 1)
            ->pluck('v')
            ->map(function ($v) {
                return (string) $v;
            })
            ->unique()
            ->values()
            ->all();

        if (count($values) > $limit) {
            return ['options' => [], 'capped' => true];
        }

        usort($values, function ($a, $b) {
            // numeric-aware: ids sort 1, 2, 10 — not "1", "10", "2"
            return (is_numeric($a) && is_numeric($b)) ? ($a + 0 <=> $b + 0) : strcmp($a, $b);
        });

        $labels = self::labels($column, $values);
        $options = [];
        foreach ($values as $v) {
            $options[] = ['id' => $v, 'name' => (string) ($labels[$v] ?? $v)];
        }
        return ['options' => self::uniqueNames($options), 'capped' => false];
    }

    /**
     * Display labels of stored values: a select_table column stores target ids → the
     * target record's label; a select_valtext column stores keys → the configured text.
     *
     * @param mixed $column  CustomColumn
     * @param string[] $values
     * @return array<string, string> value => label (values without a label are absent)
     */
    public static function labels($column, array $values): array
    {
        if (empty($values)) {
            return [];
        }
        $labels = [];
        $target = $column->select_target_table;
        if ($target) {
            foreach ($target->getValueQuery()->whereIn('id', $values)->get() as $record) {
                $labels[(string) $record->id] = (string) $record->getLabel();
            }
            return $labels;
        }
        $valtext = $column->getOption('select_item_valtext');
        if (is_string($valtext) && $valtext !== '') {
            foreach (preg_split('/\r\n|\r|\n/', $valtext) as $line) {
                $parts = explode(',', $line, 2);
                if (count($parts) === 2 && trim($parts[0]) !== '') {
                    $labels[trim($parts[0])] = trim($parts[1]);
                }
            }
        }
        return $labels;
    }

    /**
     * Two options with the same label (two same-name students) get the raw value appended,
     * so every option stays tellable apart.
     */
    private static function uniqueNames(array $options): array
    {
        $counts = array_count_values(array_column($options, 'name'));
        return array_map(function ($opt) use ($counts) {
            if ($counts[$opt['name']] > 1 && $opt['name'] !== $opt['id']) {
                $opt['name'] .= ' #' . $opt['id'];
            }
            return $opt;
        }, $options);
    }
}
