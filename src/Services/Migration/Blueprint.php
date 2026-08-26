<?php

namespace Exceedone\Exment\Services\Migration;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * Turns a preset into real Exment tables.
 *
 * It does not create anything itself. It builds the same template array that a
 * template zip would carry and hands it to TemplateImporter, which is the code
 * that already knows how to make a custom table, its physical table, its
 * indexes and its column linkages - and knows it for every database Exment
 * supports. Writing a second, parallel way to create a table would mean owning
 * all of that a second time and getting it subtly different.
 *
 * The one column added on top of whatever the preset asks for is the migration
 * key. Everything imported carries where it came from, e.g.
 *
 *     backlog:issue:DEMO-42
 *     servicenow:incident:9c573169c611228700193229fff72400
 *
 * That single string is what makes the whole thing re-runnable: the second run
 * finds the row it wrote the first time and updates it, instead of producing a
 * second copy of every ticket. A migration nobody dares run twice is a
 * migration that has to be perfect first time, and none ever are.
 */
class Blueprint
{
    /** Column every migrated table gets, holding the id it had in the old system. */
    public const KEY_COLUMN = 'migration_key';

    /** Column holding the raw source record, for when a mapping turns out wrong. */
    public const RAW_COLUMN = 'migration_source';

    /** Exment's own limit on a table or column name. */
    public const NAME_MAX = 30;

    /** @var array<string, mixed> */
    protected $preset;

    /**
     * Choice lists gathered from the source, keyed "stream.column".
     *
     * @var array<string, array<string, string>>
     */
    protected $choices;

    /** @var string[] */
    protected $notes = [];

    /**
     * @param array<string, mixed> $preset
     * @param array<string, array<string, string>> $choices
     */
    public function __construct(array $preset, array $choices = [])
    {
        $this->preset = $preset;
        $this->choices = $choices;
    }

    /**
     * @return string[]
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * The streams that produce an Exment table, in preset order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function streams(): array
    {
        $streams = [];

        foreach ((array)array_get($this->preset, 'streams', []) as $name => $stream) {
            if (array_get($stream, 'skip')) {
                continue;
            }
            if (is_nullorempty(array_get($stream, 'table'))) {
                continue;
            }
            $streams[$name] = $stream;
        }

        return $streams;
    }

    /**
     * stream name => Exment table name.
     *
     * @return array<string, string>
     */
    public function tableNames(): array
    {
        $names = [];

        foreach ($this->streams() as $name => $stream) {
            $names[$name] = strval(array_get($stream, 'table'));
        }

        return $names;
    }

    /**
     * Everything wrong with the preset, before anything is created.
     *
     * Checked up front on purpose. TemplateImporter will happily create four
     * of five tables and then fail on the fifth, and half a schema is worse
     * than none: the run looks partly successful and the missing piece only
     * surfaces when records start failing to find their parent.
     *
     * @return string[]
     */
    public function sanity(): array
    {
        $problems = [];

        foreach ($this->streams() as $name => $stream) {
            $table = strval(array_get($stream, 'table'));

            foreach ($this->nameProblems($table, 'table') as $problem) {
                $problems[] = sprintf('stream "%s": %s', $name, $problem);
            }

            if (is_nullorempty(array_get($stream, 'key'))) {
                $problems[] = sprintf('stream "%s" has no "key", so imported rows could not be matched on a re-run', $name);
            }

            $columns = (array)array_get($stream, 'columns', []);
            if (empty($columns)) {
                $problems[] = sprintf('stream "%s" maps no columns', $name);
            }

            foreach ($columns as $column => $definition) {
                foreach ($this->nameProblems(strval($column), 'column') as $problem) {
                    $problems[] = sprintf('%s.%s: %s', $table, $column, $problem);
                }

                $type = strval(array_get($definition, 'type', ColumnType::TEXT));
                if (!in_array($type, ColumnType::arrays())) {
                    $problems[] = sprintf('%s.%s: "%s" is not a column type Exment has', $table, $column, $type);
                }

                if ($reference = array_get($definition, 'ref')) {
                    $target = array_get($reference, 'stream');
                    if (!array_key_exists($target, $this->streams())) {
                        $problems[] = sprintf(
                            '%s.%s points at stream "%s", which does not produce a table',
                            $table,
                            $column,
                            $target
                        );
                    }
                }

                if (in_array($column, [static::KEY_COLUMN, static::RAW_COLUMN])) {
                    $problems[] = sprintf('%s.%s collides with a column the migration adds itself', $table, $column);
                }
            }
        }

        return $problems;
    }

    /**
     * The template array, as TemplateImporter wants it.
     *
     * No forms and no views: Exment builds a default one for a table that has
     * none the first time somebody opens it, so generating them here would
     * only be a second opinion about the same thing.
     *
     * @return array<string, mixed>
     */
    public function template(): array
    {
        $tables = [];

        foreach ($this->streams() as $name => $stream) {
            $tables[] = [
                'table_name' => array_get($stream, 'table'),
                'table_view_name' => array_get($stream, 'label', array_get($stream, 'table')),
                'description' => array_get($stream, 'description', sprintf(
                    'Imported from %s (%s).',
                    array_get($this->preset, 'label', array_get($this->preset, 'name')),
                    $name
                )),
                'showlist_flg' => array_get($stream, 'showlist', true) ? 1 : 0,
                'options' => [
                    'search_enabled' => '1',
                    'comment_flg' => '1',
                    'attachment_flg' => '1',
                ],
                'custom_columns' => $this->columns($name, $stream),
                'custom_column_multisettings' => $this->labelSetting($name, $stream),
            ];
        }

        return [
            'custom_tables' => $tables,
            'custom_views' => $this->views(),
        ];
    }

    /**
     * One grid view per table, listing columns worth seeing.
     *
     * Without this the tables come out looking empty. Exment builds a default
     * view for a table that has none, but that view only carries the system
     * columns - id, created, updated - so a freshly migrated issue table shows
     * three columns of machine data and none of the ticket. Somebody opening it
     * for the first time concludes the migration failed.
     *
     * The suuid is derived from the table name rather than generated, because
     * views are matched on it: a random one would mean a brand new view on
     * every re-run, and a table with nine identical views after a week of
     * tuning the mapping.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function views(): array
    {
        $views = [];

        foreach ($this->streams() as $name => $stream) {
            $table = strval(array_get($stream, 'table'));

            $columns = [[
                'view_column_type' => ConditionType::SYSTEM,
                'view_column_target_name' => SystemColumn::ID,
                'order' => 1,
            ]];

            $order = 10;
            foreach ($this->listColumns($stream) as $column) {
                $columns[] = [
                    'view_column_type' => ConditionType::COLUMN,
                    'view_column_table_name' => $table,
                    'view_column_target_name' => $column,
                    'order' => $order,
                ];
                $order += 10;
            }

            $views[] = [
                'suuid' => substr(md5('exment-migration-view:' . $table), 0, 20),
                'table_name' => $table,
                'view_view_name' => array_get($stream, 'view_label', array_get($this->preset, 'view_label', 'All records')),
                'view_type' => ViewType::SYSTEM,
                'view_kind_type' => ViewKindType::ALLDATA,
                'default_flg' => 1,
                'custom_view_columns' => $columns,
            ];
        }

        return $views;
    }

    /**
     * Which columns the grid shows.
     *
     * A preset says so by flagging them; otherwise the first few mapped
     * columns are used, which for every preset written so far puts the
     * identifier and the summary at the front - the two things somebody needs
     * to recognise a record.
     *
     * @param array<string, mixed> $stream
     * @return string[]
     */
    protected function listColumns(array $stream): array
    {
        $columns = (array)array_get($stream, 'columns', []);

        $named = array_values(array_filter((array)array_get($stream, 'list', [])));
        if (!empty($named)) {
            // only ones that exist, so a typo in the preset costs a column
            // rather than the whole view
            return array_values(array_intersect(array_map('strval', $named), array_map('strval', array_keys($columns))));
        }

        return array_slice(array_map('strval', array_keys($columns)), 0, 6);
    }

    /**
     * Create or update the tables.
     *
     * @param bool $update overwrite a column that already exists
     * @return array<string, mixed>
     */
    public function apply(bool $update = true): array
    {
        $problems = $this->sanity();
        if (!empty($problems)) {
            return ['ok' => false, 'problems' => $problems];
        }

        $before = CustomTable::whereIn('table_name', array_values($this->tableNames()))
            ->pluck('table_name')
            ->toArray();

        (new TemplateImporter())->import($this->template(), false, $update);

        // clear the caches Exment keeps of tables and columns, or the very next
        // lookup in this same process returns the state from before the import
        // and every record write fails to find its column
        System::clearCache();

        $after = CustomTable::whereIn('table_name', array_values($this->tableNames()))
            ->pluck('table_name')
            ->toArray();

        $missing = array_values(array_diff(array_values($this->tableNames()), $after));

        return [
            'ok' => empty($missing),
            'created' => array_values(array_diff($after, $before)),
            'existing' => array_values($before),
            'missing' => $missing,
            'problems' => $missing ? ['these tables were not created: ' . implode(', ', $missing)] : [],
        ];
    }

    // ------------------------------------------------------------ columns ---

    /**
     * Which column stands for the record.
     *
     * Left alone, Exment labels a record by its first column - which here is
     * the migration key. Every link then reads
     * "servicenow:incident:9c573169c611228700193229fff72400" instead of
     * "INC0010001", which is technically correct and completely useless. The
     * preset says which column a human would use to recognise the thing.
     *
     * @param string $name
     * @param array<string, mixed> $stream
     * @return array<int, array<string, mixed>>
     */
    protected function labelSetting(string $name, array $stream): array
    {
        $table = strval(array_get($stream, 'table'));
        $columns = (array)array_get($stream, 'columns', []);

        $column = strval(array_get($stream, 'label_column', ''));

        if ($column === '' || !array_key_exists($column, $columns)) {
            $listed = $this->listColumns($stream);
            $column = !empty($listed) ? $listed[0] : strval(array_key_first($columns));
        }

        if ($column === '' || !array_key_exists($column, $columns)) {
            $this->notes[] = sprintf('%s has no column to label its records with', $table);
            return [];
        }

        return [[
            'suuid' => substr(md5('exment-migration-label:' . $table), 0, 20),
            'multisetting_type' => MultisettingType::TABLE_LABELS,
            'options' => [
                'table_label_table_name' => $table,
                'table_label_column_name' => $column,
            ],
        ]];
    }

    /**
     * @param string $name
     * @param array<string, mixed> $stream
     * @return array<int, array<string, mixed>>
     */
    protected function columns(string $name, array $stream): array
    {
        $columns = [];

        // the migration key goes first so it is the leftmost thing anybody
        // sees when they open the table wondering where a row came from
        $columns[] = [
            'column_name' => static::KEY_COLUMN,
            'column_view_name' => array_get($this->preset, 'key_label', 'Migration key'),
            'column_type' => ColumnType::TEXT,
            'options' => [
                'index_enabled' => '1',
                'freeword_search' => '1',
                'help' => 'Identifier this record had in the system it was imported from. Do not edit.',
            ],
        ];

        foreach ((array)array_get($stream, 'columns', []) as $column => $definition) {
            $columns[] = $this->column($name, strval($column), (array)$definition);
        }

        if (array_get($stream, 'keep_raw', array_get($this->preset, 'keep_raw', true))) {
            // the untouched source record. It costs disk and it earns its keep
            // the first time somebody asks "where did this date come from" -
            // without it the answer needs the old system back online
            $columns[] = [
                'column_name' => static::RAW_COLUMN,
                'column_view_name' => array_get($this->preset, 'raw_label', 'Source record'),
                'column_type' => ColumnType::TEXTAREA,
                'options' => [
                    'help' => 'The original record as the source system sent it. Kept so a mapping mistake can be corrected without fetching everything again.',
                ],
            ];
        }

        return $columns;
    }

    /**
     * @param string $stream
     * @param string $column
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    protected function column(string $stream, string $column, array $definition): array
    {
        $type = strval(array_get($definition, 'type', ColumnType::TEXT));

        $options = (array)array_get($definition, 'options', []);

        if (array_get($definition, 'index')) {
            $options['index_enabled'] = '1';
        }
        if (array_get($definition, 'search', true) && $this->searchable($type)) {
            $options['freeword_search'] = '1';
        }
        if ($help = array_get($definition, 'help')) {
            $options['help'] = $help;
        }

        if ($type == ColumnType::SELECT_VALTEXT) {
            $options['select_item_valtext'] = $this->choiceText($stream, $column, $definition);
        }

        if ($type == ColumnType::SELECT_TABLE) {
            $target = array_get($definition, 'ref.stream');
            $tables = $this->tableNames();

            if ($target && array_key_exists($target, $tables)) {
                // the importer resolves the name to an id after every table in
                // the template exists, which is why the name is used here and
                // not a lookup that would fail on a first run
                $options['select_target_table_name'] = $tables[$target];
            } elseif ($explicit = array_get($definition, 'target_table')) {
                $options['select_target_table_name'] = $explicit;
            }

            $options['index_enabled'] = '1';
        }

        return [
            'column_name' => $column,
            'column_view_name' => array_get($definition, 'label', $column),
            'column_type' => $type,
            'options' => $options,
        ];
    }

    /**
     * The "value,label" block a select column wants.
     *
     * @param string $stream
     * @param string $column
     * @param array<string, mixed> $definition
     * @return string
     */
    protected function choiceText(string $stream, string $column, array $definition): string
    {
        $list = (array)array_get($definition, 'choices', []);

        // anything gathered from the source wins: a hand written list in the
        // preset is a guess about the customer's configuration, and the data
        // itself is not
        $discovered = array_get($this->choices, $stream . '.' . $column, []);
        if (!empty($discovered)) {
            $list = $discovered + $list;
        }

        if (empty($list)) {
            $this->notes[] = sprintf(
                '%s.%s is a choice column but no options were found; it will accept nothing until options are added',
                $stream,
                $column
            );
            return '';
        }

        $lines = [];
        foreach ($list as $value => $label) {
            $value = str_replace([',', "\n", "\r"], ' ', strval($value));
            $label = str_replace([',', "\n", "\r"], ' ', strval($label));
            if ($value === '') {
                continue;
            }
            $lines[] = $value . ',' . ($label === '' ? $value : $label);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $type
     * @return bool
     */
    protected function searchable(string $type): bool
    {
        return in_array($type, [
            ColumnType::TEXT,
            ColumnType::TEXTAREA,
            ColumnType::EDITOR,
            ColumnType::URL,
            ColumnType::EMAIL,
            ColumnType::SELECT,
            ColumnType::SELECT_VALTEXT,
            ColumnType::SELECT_TABLE,
            ColumnType::USER,
            ColumnType::ORGANIZATION,
        ]);
    }

    /**
     * @param string $name
     * @param string $kind
     * @return string[]
     */
    protected function nameProblems(string $name, string $kind): array
    {
        $problems = [];

        if ($name === '') {
            return [$kind . ' name is empty'];
        }

        if (!preg_match('/' . Define::RULES_REGEX_SYSTEM_NAME . '/', $name)) {
            $problems[] = sprintf(
                '"%s" is not a usable %s name (must start with a letter, contain only letters, digits, - and _, and not end with - or _)',
                $name,
                $kind
            );
        }

        if (mb_strlen($name) > static::NAME_MAX) {
            $problems[] = sprintf('"%s" is longer than the %d characters Exment allows for a %s name', $name, static::NAME_MAX, $kind);
        }

        return $problems;
    }
}
