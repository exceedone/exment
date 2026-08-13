<?php

namespace Exceedone\Exment\ColumnItems;

use Exceedone\Exment\Enums\ColumnType;

/**
 * How one column paints its cells in the data grid.
 *
 * Everything here comes from the column setting screen and is stored in
 * `custom_columns.options`, so a table owner styles a list without touching
 * code and without a view kind of its own.
 *
 * Two levels are involved and they are deliberately separate:
 *
 *   - STYLE_CELL paints the `<td>` itself, through gridStyle().
 *   - every other style wraps each *value* in a span, through wrap(). That
 *     is what a badge is: on a multi-value column each value gets its own
 *     badge, which one `<td>` background could never express.
 *
 * make() returns null when the column has no styling at all. That keeps the
 * default grid byte-for-byte what it was before this feature existed - the
 * overwhelming majority of columns never open this section.
 */
class GridCellStyle
{
    public const STYLE_PLAIN = 'plain';
    public const STYLE_TEXT = 'text';
    public const STYLE_TAG = 'tag';
    public const STYLE_PILL = 'pill';
    public const STYLE_BADGE = 'badge';
    public const STYLE_DOT = 'dot';
    public const STYLE_LVL = 'lvl';
    public const STYLE_MONO = 'mono';
    public const STYLE_AVATAR = 'avatar';
    public const STYLE_BAR = 'bar';
    public const STYLE_CELL = 'cell';

    /**
     * Fallback colors handed to select values in option order.
     *
     * Same list as the kanban board so one value keeps one color wherever it
     * is shown. Red first: a select column is usually ordered worst-to-best
     * (priority, severity), and that ordering is the common case.
     */
    public const PALETTE = [
        '#c0392b', '#e67e22', '#f1c40f', '#27ae60', '#95a5a6',
        '#3c8dbc', '#8e44ad', '#16a085', '#d35400', '#2980b9',
    ];

    /**
     * Colors given to people, by name hash, so one person keeps one color
     * without anybody configuring it.
     */
    public const AVATAR_PALETTE = [
        '#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9',
        '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c',
    ];

    /**
     * Styles that draw a filled shape, so they need a background and a border
     * even when the setting only gives a text color.
     */
    protected const FILLED_STYLES = [self::STYLE_TAG, self::STYLE_PILL];

    /**
     * Styles that put a colored mark in front of the text.
     */
    protected const MARKED_STYLES = [self::STYLE_DOT, self::STYLE_LVL];

    /** @var \Exceedone\Exment\Model\CustomColumn */
    protected $custom_column;

    /** @var string */
    protected $style;

    /** @var string|null */
    protected $color;

    /** @var string|null */
    protected $bg_color;

    /** @var string|null */
    protected $border_color;

    /** @var string|null */
    protected $font_weight;

    /** @var string|null */
    protected $icon;

    /** @var bool */
    protected $nowrap;

    /**
     * value => color, from the per-value setting.
     *
     * @var array<string, array<string, string>>
     */
    protected $value_colors = [];

    /**
     * value => palette color, built on first use only.
     *
     * @var array<string, string>|null
     */
    protected $auto_colors = null;

    /**
     * @param \Exceedone\Exment\Model\CustomColumn $custom_column
     */
    protected function __construct($custom_column)
    {
        $this->custom_column = $custom_column;
        $this->style = strval($custom_column->getOption('grid_style')) ?: static::STYLE_PLAIN;
        $this->color = static::normalizeColor($custom_column->getOption('grid_color'));
        $this->bg_color = static::normalizeColor($custom_column->getOption('grid_bg_color'));
        $this->border_color = static::normalizeColor($custom_column->getOption('grid_border_color'));
        $this->font_weight = static::normalizeWeight($custom_column->getOption('grid_font_weight'));
        $this->icon = static::normalizeIcon($custom_column->getOption('grid_icon'));
        $this->nowrap = boolval($custom_column->getOption('grid_nowrap', false));
        $this->value_colors = static::parseValueColors($custom_column->getOption('grid_value_colors'));
    }

    /**
     * Build the styler of a column, or null when the column is left alone.
     *
     * @param \Exceedone\Exment\Model\CustomColumn|null $custom_column
     * @return static|null
     */
    public static function make($custom_column)
    {
        if (!isset($custom_column)) {
            return null;
        }

        $style = new static($custom_column);

        return $style->isEmpty() ? null : $style;
    }

    /**
     * Nothing was configured, so the grid must render exactly as before.
     */
    public function isEmpty(): bool
    {
        return $this->style === static::STYLE_PLAIN
            && is_null($this->color)
            && is_null($this->bg_color)
            && is_null($this->border_color)
            && is_null($this->font_weight)
            && is_null($this->icon)
            && !$this->nowrap
            && empty($this->value_colors);
    }

    /**
     * Choices offered on the column setting screen.
     *
     * @return array<string, string>
     */
    public static function getStyleOptions(): array
    {
        $keys = [
            static::STYLE_PLAIN, static::STYLE_TEXT, static::STYLE_TAG, static::STYLE_PILL,
            static::STYLE_BADGE, static::STYLE_DOT, static::STYLE_LVL, static::STYLE_MONO,
            static::STYLE_AVATAR, static::STYLE_BAR, static::STYLE_CELL,
        ];

        $options = [];
        foreach ($keys as $key) {
            $options[$key] = exmtrans('custom_column.grid_style_options.' . $key);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function getFontWeightOptions(): array
    {
        // No "not specified" entry: the select is clearable, and an option
        // with an empty value would sit next to the empty one the field
        // already renders, two entries doing the same thing. The align
        // setting right above this one is built the same way.
        return [
            '400' => exmtrans('custom_column.grid_font_weight_options.normal'),
            '600' => exmtrans('custom_column.grid_font_weight_options.semibold'),
            '700' => exmtrans('custom_column.grid_font_weight_options.bold'),
        ];
    }

    /**
     * Extra declarations for the `<td>` tag.
     *
     * Only STYLE_CELL paints the cell; every other style paints the span
     * inside it, so all that is left here is the line-break setting.
     *
     * @return array<string, string>
     */
    public function tdStyle(): array
    {
        $array = [];

        if ($this->nowrap) {
            $array['white-space'] = 'nowrap';
        }

        if ($this->style !== static::STYLE_CELL) {
            return $array;
        }

        // A cell has no value to key on - the same paint applies to the whole
        // column, so a per-value setting would have nothing to choose from.
        if (isset($this->color)) {
            $array['color'] = $this->color;
        }
        if (isset($this->bg_color)) {
            $array['background-color'] = $this->bg_color;
        }
        if (isset($this->border_color)) {
            $array['border-color'] = $this->border_color;
        }
        if (isset($this->font_weight)) {
            $array['font-weight'] = $this->font_weight;
        }

        return $array;
    }

    /**
     * Wrap one rendered value.
     *
     * $html is already escaped by the column item; $value is the raw stored
     * value and is only read, never printed. $text is the plain display text,
     * needed by the avatar to take an initial - the html may carry markup and
     * its first character could be a "<".
     *
     * @param string $html
     * @param mixed $value
     * @param mixed $text
     */
    public function wrap($html, $value, $text = null): string
    {
        if ($this->style === static::STYLE_CELL) {
            return $html;
        }

        if ($this->style === static::STYLE_AVATAR) {
            return $this->avatarHtml($html, $text);
        }

        if ($this->style === static::STYLE_BAR) {
            return $this->barHtml($html, $value);
        }

        $icon = $this->iconHtml();

        // A plain column with only an icon set still gets the icon: the style
        // says "do not change the shape", not "change nothing".
        if ($this->style === static::STYLE_PLAIN) {
            return $icon === '' ? $html : '<span class="exm-cell-text">' . $icon . $html . '</span>';
        }

        $color = $this->colorFor($value);
        $css = $this->spanCss($color, $value);
        $attr = empty($css) ? '' : ' style="' . e(implode('; ', $css)) . '"';
        $class = 'exm-cell-' . $this->style;

        if (in_array($this->style, static::MARKED_STYLES, true)) {
            $mark = ' style="background-color:' . e($color ?? '#95a5a6') . '"';
            return '<span class="' . $class . '"' . $attr . '><span class="exm-cell-mark"' . $mark . '></span>'
                . $icon . $html . '</span>';
        }

        return '<span class="' . $class . '"' . $attr . '>' . $icon . $html . '</span>';
    }

    /**
     * Initial in a colored circle, then the name.
     *
     * The circle color comes from the text and never from a setting: a people
     * column has no fixed option list to configure, but the same person must
     * still keep the same color on every row and every page.
     *
     * @param string $html
     * @param mixed $text
     */
    protected function avatarHtml($html, $text): string
    {
        $text = is_scalar($text) ? strval($text) : '';
        if ($text === '') {
            return $html;
        }

        $initial = mb_substr($text, 0, 1);
        $color = $this->colorFor($text) ?? static::hashColor($text);

        return '<span class="exm-cell-avatar"><span class="exm-cell-av" style="background-color:' . e($color) . '">'
            . esc_html($initial) . '</span>' . $html . '</span>';
    }

    /**
     * A progress bar for a 0-100 value, with the number next to it.
     *
     * The fill and the number share one color so the number keeps saying the
     * same thing when a compact table hides the track. A value beyond the
     * range still prints as stored - only the drawn width is clamped.
     *
     * @param string $html
     * @param mixed $value
     */
    protected function barHtml($html, $value): string
    {
        $number = is_numeric($value) ? floatval($value) : null;

        // A decimal column with the percent option stores 0-1 and already
        // prints its own "%" - follow both, or the bar and its number would
        // tell two different stories.
        if (isset($number) && boolval($this->custom_column->getOption('percent_format'))) {
            $number *= 100;
        }

        $width = is_null($number) ? 0 : max(0, min(100, $number));
        $color = (is_null($number) ? null : $this->barColor($number)) ?? $this->color ?? '#3c8dbc';

        $txt = ['color:' . $color];
        if (isset($this->font_weight)) {
            $txt[] = 'font-weight:' . $this->font_weight;
        }

        $suffix = (is_null($number) || str_ends_with(rtrim(strip_tags($html)), '%')) ? '' : '%';

        return '<span class="exm-cell-bar">'
            . '<span class="exm-cell-bar-track"><span class="exm-cell-bar-fill" style="width:'
            . e($width) . '%;background-color:' . e($color) . '"></span></span>'
            . '<span class="exm-cell-bar-txt" style="' . e(implode('; ', $txt)) . '">'
            . $html . $suffix . '</span>'
            . '</span>';
    }

    /**
     * The threshold color of a number: of every configured line whose
     * threshold the number reached, the highest one wins.
     */
    protected function barColor(float $number): ?string
    {
        $picked = null;
        $picked_at = null;

        foreach ($this->value_colors as $threshold => $row) {
            if (!is_numeric($threshold) || !isset($row['color'])) {
                continue;
            }

            $threshold = floatval($threshold);
            if ($threshold <= $number && (is_null($picked_at) || $threshold >= $picked_at)) {
                $picked = $row['color'];
                $picked_at = $threshold;
            }
        }

        return $picked;
    }

    /**
     * The configured icon, or an empty string.
     */
    protected function iconHtml(): string
    {
        return is_null($this->icon) ? '' : '<i class="fa ' . e($this->icon) . '"></i>';
    }

    /**
     * A stable color for a free text value.
     */
    public static function hashColor(string $text): string
    {
        $number = 0;
        $length = mb_strlen($text);
        for ($i = 0; $i < $length; $i++) {
            $number = ($number * 31 + mb_ord(mb_substr($text, $i, 1))) % 100000;
        }

        return static::AVATAR_PALETTE[$number % count(static::AVATAR_PALETTE)];
    }

    /**
     * Declarations put on the wrapping span.
     *
     * @param string|null $color
     * @param mixed $value
     * @return array<int, string>
     */
    protected function spanCss($color, $value): array
    {
        $row = $this->valueColorsFor($value);
        $css = [];

        // A badge turns the color chain around: the one color a value has
        // paints the fill, and the text stays white (from the css) unless the
        // column names its own text color. Going through colorFor() here
        // would leak that text color into the fill.
        if ($this->style === static::STYLE_BADGE) {
            if (isset($this->color)) {
                $css[] = 'color:' . $this->color;
            }
            if (isset($this->font_weight)) {
                $css[] = 'font-weight:' . $this->font_weight;
            }

            $key = is_null($value) ? '' : strval($value);
            $bg = $row['background'] ?? $row['color'] ?? $this->bg_color ?? array_get($this->autoColors(), $key);
            $border = $row['border'] ?? $this->border_color;

            if (isset($bg)) {
                $css[] = 'background-color:' . $bg;
            }
            if (isset($border)) {
                $css[] = 'border-color:' . $border;
            }

            return $css;
        }

        if (isset($color)) {
            $css[] = 'color:' . $color;
        }
        if (isset($this->font_weight)) {
            $css[] = 'font-weight:' . $this->font_weight;
        }

        if (!in_array($this->style, static::FILLED_STYLES, true)) {
            return $css;
        }

        // A filled shape needs three colors but the setting usually gives one.
        // Deriving the other two from it keeps the shape readable at any hue
        // and means one color picker is enough for the common case.
        $bg = $row['background'] ?? $this->bg_color ?? (isset($color) ? static::rgba($color, 0.12) : null);
        $border = $row['border'] ?? $this->border_color ?? (isset($color) ? static::rgba($color, 0.32) : null);

        if (isset($bg)) {
            $css[] = 'background-color:' . $bg;
        }
        if (isset($border)) {
            $css[] = 'border-color:' . $border;
        }

        return $css;
    }

    /**
     * The color of one value: the per-value setting first, then the column
     * color, then the palette for a select column.
     *
     * @param mixed $value
     */
    public function colorFor($value): ?string
    {
        $row = $this->valueColorsFor($value);
        if (isset($row['color'])) {
            return $row['color'];
        }
        if (isset($this->color)) {
            return $this->color;
        }

        return array_get($this->autoColors(), is_null($value) ? '' : strval($value));
    }

    /**
     * The per-value setting line of one value, or an empty array.
     *
     * @param mixed $value
     * @return array<string, string>
     */
    protected function valueColorsFor($value): array
    {
        return $this->value_colors[is_null($value) ? '' : strval($value)] ?? [];
    }

    /**
     * Palette colors of a select column, by option order.
     *
     * @return array<string, string>
     */
    protected function autoColors(): array
    {
        if (isset($this->auto_colors)) {
            return $this->auto_colors;
        }

        $this->auto_colors = [];

        // Only a fixed option list can be colored this way: a free text column
        // has no stable order, so the same value could change color between
        // pages once a new value appears.
        if (!in_array(array_get($this->custom_column, 'column_type'), [ColumnType::SELECT, ColumnType::SELECT_VALTEXT], true)) {
            return $this->auto_colors;
        }

        $index = 0;
        foreach ($this->custom_column->createSelectOptions() as $key => $label) {
            $this->auto_colors[strval($key)] = static::PALETTE[$index % count(static::PALETTE)];
            $index++;
        }

        return $this->auto_colors;
    }

    /**
     * Read the per-value setting.
     *
     * One value per line, `value,#color[,#background[,#border]]`, the same
     * shape the select options themselves already use so the screen stays
     * consistent. A line that names no usable color is dropped rather than
     * stored half-empty. The bar style reads the same lines as thresholds:
     * `75,#f39c12` means "this color from 75 up".
     *
     * @param mixed $text
     * @return array<string, array<string, string>>
     */
    public static function parseValueColors($text): array
    {
        if (!is_string($text) || is_nullorempty($text)) {
            return [];
        }

        $colors = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            $parts = explode(',', $line);
            if (count($parts) < 2) {
                continue;
            }

            $key = mbTrim(array_shift($parts));
            $row = [];
            foreach (['color', 'background', 'border'] as $i => $name) {
                $color = static::normalizeColor(array_get($parts, $i));
                if (isset($color)) {
                    $row[$name] = $color;
                }
            }

            if (!empty($row)) {
                $colors[$key] = $row;
            }
        }

        return $colors;
    }

    /**
     * Accept `#abc`, `#aabbcc` and the same without the hash; refuse anything
     * else so a typo can never inject css into the page.
     *
     * @param mixed $value
     */
    public static function normalizeColor($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = ltrim(mbTrim($value), '#');
        if (!preg_match('/^([0-9a-f]{3}|[0-9a-f]{6})$/i', $value)) {
            return null;
        }

        return '#' . strtolower($value);
    }

    /**
     * Accept a Font Awesome class name and nothing else, so the setting can
     * never close the attribute and inject markup.
     *
     * @param mixed $value
     */
    public static function normalizeIcon($value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = mbTrim($value);
        if (!preg_match('/^fa-[0-9a-z-]+$/i', $value)) {
            return null;
        }

        return strtolower($value);
    }

    /**
     * @param mixed $value
     */
    protected static function normalizeWeight($value): ?string
    {
        $value = strval($value);

        return in_array($value, ['400', '600', '700'], true) ? $value : null;
    }

    /**
     * Translucent version of a color, for the fill and the border of a badge.
     */
    protected static function rgba(string $hex, float $alpha): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $number = intval(hexdec($hex));

        return sprintf('rgba(%d,%d,%d,%s)', ($number >> 16) & 255, ($number >> 8) & 255, $number & 255, $alpha);
    }
}
