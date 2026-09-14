<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems\GridCellStyle;
use Exceedone\Exment\Enums\ColumnType;

/**
 * A named, reusable set of cell appearance settings.
 *
 * The column setting screen already knows how to paint a cell, but every
 * table owner had to rebuild the same "status pill" by hand, column after
 * column. A preset is that work done once: a column or a view column stores
 * only the preset key, and the styling is read back through resolveOptions().
 *
 * Every preset is a row of this table. The catalog Exment ships with is
 * declared in BUILTINS below and seeded into the table by a migration, one
 * row per entry keyed "sys.<name>" - a fresh installation has a full library
 * from day one, and an administrator curates the shipped presets on the same
 * screen as the home-made ones. BUILTINS stays in the code as the seed source
 * and as a reading fallback for the short window between a code update and
 * `exment:update`, when the seeded rows do not exist yet.
 *
 * Storing the key and not the values is what makes "update this preset" mean
 * something: editing one row repaints every column pointing at it. The cost
 * is one cached query per request, which next to Exment's own boot time is
 * not measurable.
 */
class CellStylePreset extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\ClearCacheTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonOptionTrait;

    protected $guarded = ['id'];
    protected $casts = ['options' => 'json', 'column_types' => 'json'];

    /**
     * Key prefix of the seeded catalog rows.
     *
     * A generated suuid is 20 characters of base62 and never contains a dot,
     * so a seeded key can never collide with a hand-made preset's key.
     */
    public const BUILTIN_PREFIX = 'sys.';

    /**
     * Every column type a select-like preset is offered on.
     */
    private const TYPES_SELECT = [ColumnType::SELECT, ColumnType::SELECT_VALTEXT, ColumnType::SELECT_TABLE];

    /**
     * The presets Exment ships with.
     *
     * `column_types` is the list of column types the preset is offered on; an
     * empty list means every type. `options` holds the same keys the column
     * setting screen writes, so a preset and a hand-made column go through
     * exactly the same rendering path.
     */
    public const BUILTINS = [
        'status_pill' => [
            'column_types' => self::TYPES_SELECT,
            'options' => ['grid_style' => GridCellStyle::STYLE_PILL],
        ],
        'status_badge' => [
            'column_types' => self::TYPES_SELECT,
            'options' => ['grid_style' => GridCellStyle::STYLE_BADGE],
        ],
        'label_tag' => [
            'column_types' => self::TYPES_SELECT,
            'options' => ['grid_style' => GridCellStyle::STYLE_TAG],
        ],
        // No fixed colors: the palette hands each choice a color by its
        // position, so the same preset fits any select column.
        'select_dot' => [
            'column_types' => self::TYPES_SELECT,
            'options' => ['grid_style' => GridCellStyle::STYLE_DOT],
        ],
        // Worst first, matching how a priority list is normally ordered. The
        // colors are spelled out rather than left to the palette because a
        // priority column is the one place the reader expects red to mean
        // red, whatever order the options happen to be in.
        'priority_dot' => [
            'column_types' => [ColumnType::SELECT, ColumnType::SELECT_VALTEXT],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_DOT,
                'grid_value_colors' => "1,#c0392b\n2,#e67e22\n3,#f1c40f\n4,#27ae60\n5,#95a5a6",
            ],
        ],
        'level_round' => [
            'column_types' => [ColumnType::SELECT, ColumnType::SELECT_VALTEXT],
            'options' => ['grid_style' => GridCellStyle::STYLE_LVL],
        ],
        // Green for yes, grey for no - the fixed keys of a yesno column. A
        // boolean column stores whatever two values its owner chose, so
        // there the palette takes over instead.
        'flag_badge' => [
            'column_types' => [ColumnType::YESNO, ColumnType::BOOLEAN],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_BADGE,
                'grid_value_colors' => "1,#27ae60\n0,#95a5a6",
            ],
        ],
        'user_avatar' => [
            'column_types' => [ColumnType::USER, ColumnType::ORGANIZATION],
            'options' => ['grid_style' => GridCellStyle::STYLE_AVATAR],
        ],
        'progress_bar' => [
            'column_types' => [ColumnType::INTEGER, ColumnType::DECIMAL],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_BAR,
                'grid_value_colors' => "0,#c0392b\n50,#f1c40f\n80,#27ae60",
            ],
        ],
        'number_strong' => [
            'column_types' => [ColumnType::INTEGER, ColumnType::DECIMAL, ColumnType::CURRENCY],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_TEXT,
                'grid_font_weight' => '700',
                'grid_nowrap' => '1',
            ],
        ],
        'date_nowrap' => [
            'column_types' => [ColumnType::DATE, ColumnType::DATETIME, ColumnType::TIME],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_TEXT,
                'grid_nowrap' => '1',
            ],
        ],
        'code_mono' => [
            'column_types' => [ColumnType::TEXT, ColumnType::AUTO_NUMBER, ColumnType::URL, ColumnType::EMAIL],
            'options' => ['grid_style' => GridCellStyle::STYLE_MONO],
        ],
        'alert_text' => [
            'column_types' => [],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_TEXT,
                'grid_color' => '#c0392b',
                'grid_font_weight' => '700',
            ],
        ],
        'fill_cell' => [
            'column_types' => [],
            'options' => [
                'grid_style' => GridCellStyle::STYLE_CELL,
                'grid_color' => '#2c3e50',
                'grid_bg_color' => '#eef4fa',
            ],
        ],
    ];

    /**
     * Longest accepted per-value setting, in characters.
     *
     * One line per select option; a few hundred options is already far past
     * what a readable list holds, so this only stops an abusive payload from
     * reaching every grid that uses the preset.
     */
    public const VALUE_COLORS_MAX = 2000;

    /**
     * Whether this installation can serve presets at all.
     *
     * Between a code update and `exment:update`, the table does not exist yet
     * while the grid already asks for presets. Answering "none" there keeps
     * every list rendering instead of throwing on a missing table.
     */
    public static function available(): bool
    {
        static $available = null;

        if (is_null($available)) {
            $available = hasTable('cell_style_presets');
        }

        return $available;
    }

    /**
     * The styling behind a preset key, ready to hand to GridCellStyle.
     *
     * Returns an empty array for an unknown key, which is what a column
     * pointing at a deleted preset gets: the column simply renders unstyled
     * rather than breaking the list.
     *
     * @param mixed $key
     * @return array<string, mixed>
     */
    public static function resolveOptions($key): array
    {
        $definition = static::findDefinition($key);

        return is_null($definition) ? [] : $definition['options'];
    }

    /**
     * One preset by key, built-in or custom.
     *
     * @param mixed $key
     * @return array<string, mixed>|null
     */
    public static function findDefinition($key): ?array
    {
        if (!is_string($key) || is_nullorempty($key)) {
            return null;
        }

        // The table first: the seeded catalog lives there under these same
        // "sys." keys, and what an administrator edited must win over what
        // the code shipped with.
        if (static::available()) {
            $preset = static::allRecordsCache(function ($record) use ($key) {
                return $record->suuid == $key;
            }, false)->first();

            if (isset($preset)) {
                return $preset->definition();
            }
        }

        // Reading fallback for an installation whose seeding migration has
        // not run yet: the grid keeps rendering instead of dropping every
        // styled column at once.
        if (strpos($key, static::BUILTIN_PREFIX) === 0) {
            $name = substr($key, strlen(static::BUILTIN_PREFIX));
            if (array_key_exists($name, static::BUILTINS)) {
                return static::builtinDefinition($name);
            }
        }

        return null;
    }

    /**
     * Every preset this user may see, built-ins first.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getDefinitions(): array
    {
        $definitions = [];
        $stored = [];

        if (static::available()) {
            // `false` matters: with `true` an installation that has no rows
            // yet re-queries the table on every call instead of trusting the
            // empty cache.
            $presets = static::allRecordsCache(null, false)
                ->sortBy(function ($preset) {
                    return sprintf('%010d%s', intval($preset->order), strval($preset->preset_name));
                });

            foreach ($presets as $preset) {
                $definitions[] = $preset->definition();
                $stored[strval($preset->suuid)] = true;
            }
        }

        // Only before the seeding migration has run does this add anything:
        // afterwards every BUILTINS entry already sits in the list above as
        // its own row.
        foreach (array_keys(static::BUILTINS) as $name) {
            if (!array_key_exists(static::BUILTIN_PREFIX . $name, $stored)) {
                $definitions[] = static::builtinDefinition($name);
            }
        }

        return $definitions;
    }

    /**
     * Choices for a preset select on a setting screen.
     *
     * Keyed by preset key, so the select stores exactly what the renderer
     * reads back. The chips the dropdown shows are drawn by the browser from
     * the same definitions; this is only the accessible fallback.
     *
     * @return array<string, string>
     */
    public static function getPickerOptions(): array
    {
        $options = [];
        foreach (static::getDefinitions() as $definition) {
            $options[$definition['key']] = $definition['name'];
        }

        return $options;
    }

    /**
     * Put the shipped catalog into the table, one row per BUILTINS entry.
     *
     * Called from a migration, so the full library exists from the moment
     * `exment:update` (or a fresh install) finishes. Insert-if-missing on
     * the fixed "sys." keys: re-running never touches a name or a color an
     * administrator has changed since, and never duplicates a row.
     */
    public static function seedDefaults(): void
    {
        // Asked directly instead of through available(): that answer is
        // cached per process, and the migration right before this one may
        // have created the table in this same process.
        if (!hasTable('cell_style_presets')) {
            return;
        }

        $order = 0;
        foreach (static::BUILTINS as $name => $builtin) {
            $order += 10;

            $key = static::BUILTIN_PREFIX . $name;
            if (static::where('suuid', $key)->exists()) {
                continue;
            }

            $preset = new static();
            $preset->suuid = $key;
            $preset->preset_name = mb_substr(exmtrans('cell_style_preset.builtins.' . $name), 0, 40);
            $preset->column_types = $builtin['column_types'];
            $preset->options = $builtin['options'];
            $preset->order = $order;
            $preset->save();
        }
    }

    /**
     * Whatever door a preset comes in through - the modal's webapi, the
     * management screen's form, the seeding migration - the stored shape
     * comes out the same: styling keys only, each normalized exactly like
     * the renderer would normalize it.
     */
    protected static function boot()
    {
        parent::boot();

        static::saving(function ($preset) {
            $preset->options = static::filterOptions($preset->options);
            $preset->column_types = static::filterColumnTypes($preset->column_types);
        });
    }

    /**
     * This preset as the front end and the renderer both see it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => strval($this->suuid),
            'name' => strval($this->preset_name),
            'builtin' => false,
            'column_types' => static::filterColumnTypes($this->column_types),
            'options' => static::filterOptions($this->options),
            'editable' => $this->isEditableBy(),
        ];
    }

    /**
     * Whether the logged in user may change or remove this preset.
     *
     * A preset is shared by every table that picked it, so letting anyone
     * rewrite one would repaint other people's lists without warning. The
     * owner and a system administrator can; everybody else copies it into a
     * new preset instead.
     */
    public function isEditableBy(): bool
    {
        $user = \Exment::user();
        if (!isset($user)) {
            return false;
        }

        if ($user->isAdministrator()) {
            return true;
        }

        return isset($this->created_user_id) && $this->created_user_id == $user->getUserId();
    }

    /**
     * @param string $name
     * @return array<string, mixed>
     */
    protected static function builtinDefinition(string $name): array
    {
        $builtin = static::BUILTINS[$name];

        return [
            'key' => static::BUILTIN_PREFIX . $name,
            'name' => exmtrans('cell_style_preset.builtins.' . $name),
            'builtin' => true,
            'column_types' => $builtin['column_types'],
            'options' => $builtin['options'],
            'editable' => false,
        ];
    }

    /**
     * Keep only the styling keys, each one normalized the same way the
     * renderer would normalize it.
     *
     * The renderer already refuses a malformed color, so this is the second
     * of two gates rather than the only one - but it is the gate that keeps
     * the bad value out of the database, where it would otherwise sit in
     * every list using the preset.
     *
     * @param mixed $options
     * @return array<string, mixed>
     */
    public static function filterOptions($options): array
    {
        if ($options instanceof \Illuminate\Support\Collection) {
            $options = $options->toArray();
        }
        if (!is_array($options)) {
            return [];
        }

        $filtered = [];

        $style = strval(array_get($options, 'grid_style'));
        if (array_key_exists($style, GridCellStyle::getStyleOptions())) {
            $filtered['grid_style'] = $style;
        }

        foreach (['grid_color', 'grid_bg_color', 'grid_border_color'] as $key) {
            $color = GridCellStyle::normalizeColor(array_get($options, $key));
            if (isset($color)) {
                $filtered[$key] = $color;
            }
        }

        $weight = strval(array_get($options, 'grid_font_weight'));
        if (array_key_exists($weight, GridCellStyle::getFontWeightOptions())) {
            $filtered['grid_font_weight'] = $weight;
        }

        $icon = GridCellStyle::normalizeIcon(array_get($options, 'grid_icon'));
        if (isset($icon)) {
            $filtered['grid_icon'] = $icon;
        }

        if (boolval(array_get($options, 'grid_nowrap'))) {
            $filtered['grid_nowrap'] = '1';
        }

        $value_colors = array_get($options, 'grid_value_colors');
        if (is_string($value_colors) && !is_nullorempty($value_colors)) {
            // Re-serialized from what the parser accepted, so a line the
            // renderer would drop never reaches the database at all.
            $lines = [];
            foreach (GridCellStyle::parseValueColors(mb_substr($value_colors, 0, static::VALUE_COLORS_MAX)) as $key => $row) {
                $lines[] = implode(',', array_merge([$key], array_values($row)));
            }
            if (!empty($lines)) {
                $filtered['grid_value_colors'] = implode("\n", $lines);
            }
        }

        return $filtered;
    }

    /**
     * Keep only real column types.
     *
     * @param mixed $column_types
     * @return array<int, string>
     */
    public static function filterColumnTypes($column_types): array
    {
        if ($column_types instanceof \Illuminate\Support\Collection) {
            $column_types = $column_types->toArray();
        }
        if (!is_array($column_types)) {
            return [];
        }

        $all = ColumnType::arrays();

        return array_values(array_unique(array_filter($column_types, function ($column_type) use ($all) {
            return is_string($column_type) && in_array($column_type, $all, true);
        })));
    }
}
