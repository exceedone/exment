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

    /**
     * @return array<int,array<string,mixed>>
     */
    public function suggest(string $q): array
    {
        $limit = 10;
        // Constrain to tables the user may view, then over-fetch: the row-level
        // scope below can still drop hits, but never a whole table's worth.
        $hits = $this->service->searchHighlighted($q, $limit * 4, [
            'permitted_tables' => SavedSearchService::searchableTableNames(),
        ]);

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
     * Only select_table columns can hide content: Exment replaces a linked record
     * the viewer cannot open with the "no permission" text while the document
     * keeps its real label, so such a field is withheld. user/organization
     * columns are not permission-gated on the record screen, so they stay.
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
        if ($column->column_type !== ColumnType::SELECT_TABLE) {
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
