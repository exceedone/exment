<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Exceedone\Exment\Services\Meili\SavedSearchService;

/**
 * Global search header suggestions: a single Meilisearch query, permission
 * filtered, mapped to the same response structure as the original header().
 */
class HeaderSuggester
{
    public function __construct(private MeiliSearchService $service)
    {
    }

    /** Suggestions the header shows. */
    public const LIMIT = 10;

    /** How much wider than LIMIT the first read is, and how much each retry grows by. */
    public const OVER_FETCH = 4;

    /** Hits one keystroke may read at most, however many get dropped. */
    public const MAX_FETCH = 400;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function suggest(string $q): array
    {
        // Constrain to tables the user may view, then over-fetch: the row-level
        // scope and the readability checks below still drop hits.
        $permitted = SavedSearchService::searchableTableNames();
        $fetch = self::LIMIT * self::OVER_FETCH;

        // Reading LIMIT * OVER_FETCH once and filtering afterwards returns fewer
        // than LIMIT suggestions whenever most of the early hits are ones this
        // viewer may not be shown - while further hits that would have passed sit
        // just outside the window. So widen the read until LIMIT suggestions
        // survive, the index runs out of hits, or MAX_FETCH is reached.
        while (true) {
            $hits = $this->service->searchHighlighted($q, $fetch, ['permitted_tables' => $permitted]);
            $results = $this->renderHits($hits, self::LIMIT);

            if (!self::needsWiderFetch(count($results), count($hits), $fetch)) {
                return $results;
            }

            $fetch = self::nextFetch($fetch);
        }
    }

    /**
     * Whether reading further into the hits is worth another query: short of
     * LIMIT suggestions, Meilisearch filled the window it was given (so there
     * are more hits to read), and the ceiling is not reached yet.
     */
    public static function needsWiderFetch(int $found, int $hitsRead, int $fetch): bool
    {
        return $found < self::LIMIT && $hitsRead >= $fetch && $fetch < self::MAX_FETCH;
    }

    /** The next, wider read - never past MAX_FETCH. */
    public static function nextFetch(int $fetch): int
    {
        return min(self::MAX_FETCH, max(1, $fetch) * self::OVER_FETCH);
    }

    /**
     * Turn hits into suggestions, dropping the ones this viewer may not be shown.
     *
     * @param  array<int,array<string,mixed>>  $hits
     * @return array<int,array<string,mixed>>
     */
    private function renderHits(array $hits, int $limit): array
    {
        // Record permission filter: queries automatically apply the
        // CustomValueModelScope global scope.
        $accessible = $this->accessibleModels($hits);

        $results = [];
        foreach (MeiliSearchService::filterAccessibleHits($hits, $accessible, $limit) as $hit) {
            $tableName = array_get($hit, 'table_name');
            $table = CustomTable::getEloquent($tableName);
            $model = $accessible[$tableName][array_get($hit, 'value_id')] ?? null;

            // Label from the scoped model, not from the document: a document keeps
            // the labels of linked records as resolved when it was indexed, which
            // may name a record this viewer cannot open.
            $label = $model ? (string) $model->label : (string) array_get($hit, 'label');

            // [Highlight] displayed text contains <mark>; the value filled into
            // the input box is the plain label.
            $snippet = $this->permittedSnippet($hit, $table, $model, $label);
            // Matched nothing but content this viewer may not read: showing the
            // record would still confirm that content exists.
            if ($snippet === null) {
                continue;
            }
            $text = self::toHighlightedHtml($snippet !== '' ? $snippet : $label);
            $results[] = [
                'value' => $label
                , 'text_html' => $text
                , 'icon' => array_get($table, 'options.icon')
                , 'table_view_name' => array_get($table, 'table_view_name')
                , 'table_name' => array_get($table, 'table_name')
                , 'value_id' => array_get($hit, 'value_id')
                , 'color' => array_get($table, 'options.color') ?? "#3c8dbc"
                ];
        }

        return $results;
    }

    /**
     * The highlighted excerpt, limited to what this viewer is allowed to read.
     * Null when the keyword matched only content the viewer may not read.
     *
     * @param  array<string,mixed>  $hit
     * @param  mixed  $table
     * @param  mixed  $model  the permission-scoped record, null when unavailable
     */
    private function permittedSnippet(array $hit, $table, $model, string $label): ?string
    {
        $pre = MeiliSearchService::HIGHLIGHT_PRE;
        $formatted = (array) (array_get($hit, 'formatted') ?: []);

        // The indexed label only stands if it still matches the label the viewer
        // may see; built from a hidden linked record, the two differ.
        $indexedLabel = (string) ($formatted['label'] ?? '');
        if (self::stripHighlight($indexedLabel) !== $label) {
            if (strpos($indexedLabel, $pre) !== false) {
                return null;    // the keyword matched a label the viewer may not read
            }
            unset($formatted['label']);
        } elseif (strpos($indexedLabel, $pre) !== false) {
            return $indexedLabel;
        }

        // Only the fields that actually carry the keyword are worth a permission
        // check - resolving a linked value costs a lookup per column.
        $hiddenHit = false;
        foreach (($formatted['fields'] ?? []) as $name => $value) {
            if (!is_string($value) || strpos($value, $pre) === false) {
                continue;
            }
            if (self::isReadableField($table, $model, (string) $name)) {
                return $value;
            }
            $hiddenHit = true;
        }

        // Nothing readable matched: keep the hit only if nothing hidden matched
        // either - then it came from the table name, which is not record data.
        return $hiddenHit ? null : (string) ($formatted['label'] ?? '');
    }

    /**
     * Whether this viewer may be shown a freeword field's indexed content.
     *
     * Only linked columns can hide content: Exment replaces a linked record the
     * viewer cannot open with the "no permission" text while the document keeps
     * its real label, so such a field is withheld. user/organization columns
     * count too - they look ungated only because filter_multi_user is off by
     * default; once it is on, the user table is narrowed to the viewer's own
     * organizations exactly like any other linked table.
     *
     * @param  mixed  $table
     * @param  mixed  $model
     */
    private static function isReadableField($table, $model, string $name): bool
    {
        if (!$table) {
            return false;
        }

        $column = $table->getFreewordSearchColumns()->first(fn ($c) => $c->column_name === $name);
        if (!$column) {
            return false;
        }
        if (!ColumnType::isSelectTable($column->column_type)) {
            return true;
        }
        // Target fully visible -> its labels are never replaced, so no check needed.
        $target = $column->select_target_table;
        if ($target && SavedSearchService::canViewAllRows($target)) {
            return true;
        }

        $shown = $model ? (string) $model->getValue($column, true) : '';

        return $shown !== '' && strpos($shown, exmtrans('common.message.no_permission')) === false;
    }

    private static function stripHighlight(string $value): string
    {
        return str_replace([MeiliSearchService::HIGHLIGHT_PRE, MeiliSearchService::HIGHLIGHT_POST], '', $value);
    }

    /**
     * Convert a raw snippet (record content with highlight markers) into safe
     * html: escape everything first, then turn the non-html markers inserted
     * by Meilisearch into <mark> tags. Escaping must come first — the snippet
     * is untrusted record data and is rendered as html by the autocomplete.
     */
    public static function toHighlightedHtml(string $snippet): string
    {
        return str_replace(
            [MeiliSearchService::HIGHLIGHT_PRE, MeiliSearchService::HIGHLIGHT_POST],
            ['<mark>', '</mark>'],
            e($snippet)
        );
    }

    /**
     * For each table appearing in the hits, return the records the current user
     * can actually view (scoped query), keyed by id. The models themselves, not
     * just their ids: the label and the linked values are re-read from them so
     * what is rendered follows this viewer's permissions.
     *
     * @param array<int,array<string,mixed>> $hits
     * @return array<string,array<int|string,mixed>>
     */
    private function accessibleModels(array $hits): array
    {
        $idsByTable = [];
        foreach ($hits as $hit) {
            $tn = array_get($hit, 'table_name');
            $vid = array_get($hit, 'value_id');
            if ($tn !== null && $vid !== null) {
                $idsByTable[$tn][] = $vid;
            }
        }

        $accessible = [];
        foreach ($idsByTable as $tableName => $ids) {
            $table = CustomTable::getEloquent($tableName);
            if (!$table || !$table->hasPermission(Permission::AVAILABLE_VIEW_CUSTOM_VALUE)) {
                $accessible[$tableName] = [];
                continue;
            }
            $accessible[$tableName] = getModelName($table)::whereIn('id', array_values(array_unique($ids)))
                ->get()
                ->keyBy('id')
                ->all();
        }

        return $accessible;
    }
}
