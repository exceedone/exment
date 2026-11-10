<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\CurrencySymbol;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;

/**
 * The 2つ目の値 of a chart box, drawn with the first one on the 複合 type (ChartType::COMBO), as
 * Excel's 組み合わせグラフ: the first value as bars on the left axis, the second a line (or bars)
 * on an axis of its own on the right, each axis named and numbered in its own units. Both are
 * summary columns of the box's aggregate view, read off the same rows of its query.
 *
 * Box options, flat in the embedded options as the 目標線's:
 *   chart_axisy2        the summary column, named as chart_axisy ("{AGGREGATE}_{summary id}")
 *   chart_axisy2_style  line | bar (not chart_axisy2_type: a template names the column's view kind so)
 *   chart_axisy2_right  '1' on the right axis, '0' on the left one with the first value
 *
 * The 集計方法 of the box menu switches the first value only; the 表示方法 is not offered on the
 * 複合 type. A view changed since — the column gone, or made the first value — leaves the box
 * its first value: it draws that alone, as bars.
 */
final class ChartSecondValue
{
    public const COLUMN = 'chart_axisy2';
    public const TYPE = 'chart_axisy2_style';
    public const RIGHT = 'chart_axisy2_right';

    public const LINE = 'line';
    public const BAR = 'bar';

    /** the series key a color painted on the second value is kept under (ChartColors::SERIES): no name a single-series box has */
    public const COLOR_KEY = '#value2';

    /** @var CustomViewSummary */
    private $summary;

    /** @var string LINE / BAR */
    private $type;

    /** @var bool */
    private $right;

    private function __construct(CustomViewSummary $summary, string $type, bool $right)
    {
        $this->summary = $summary;
        $this->type = $type;
        $this->right = $right;
    }

    /**
     * The types a box draws its second value with or can be switched to draw it: the single
     * series of an aggregate view along a category axis. The form offers the field on them.
     */
    public static function supportsType(?string $type): bool
    {
        return in_array($type, [ChartType::BAR, ChartType::LINE, ChartType::AREA, ChartType::COMBO], true);
    }

    /**
     * The box's second value: null when it has none, or none its view still has as a summary
     * column other than the first value.
     *
     * @param mixed $custom_view
     * @param mixed $options  the box options
     */
    public static function fromOptions($custom_view, $options): ?self
    {
        $column = array_get($options, static::COLUMN);
        $summary = static::summaryOf($custom_view, $column);
        if ($summary === null || $column === array_get($options, 'chart_axisy')) {
            return null;
        }
        $type = array_get($options, static::TYPE) === static::BAR ? static::BAR : static::LINE;
        return new self($summary, $type, (string) array_get($options, static::RIGHT) !== '0');
    }

    /**
     * The summary column $column names, when it is one of the aggregate view $custom_view.
     *
     * @param mixed $custom_view
     * @param mixed $column  "{AGGREGATE}_{summary id}"
     */
    public static function summaryOf($custom_view, $column): ?CustomViewSummary
    {
        if (!$custom_view instanceof CustomView || $custom_view->view_kind_type != ViewKindType::AGGREGATE
            || !is_string($column) || !preg_match('/\A' . ViewKindType::AGGREGATE . '_\d+\z/', $column)) {
            return null;
        }
        $summary = CustomViewSummary::getSummaryViewColumn($column);
        return $summary instanceof CustomViewSummary && $summary->custom_view_id == $custom_view->id ? $summary : null;
    }

    /**
     * The choices of the form's field: the summary columns of an aggregate view (none of a list
     * view), as the 値 field names them; an empty one first, as the field may be left empty.
     *
     * @param mixed $custom_view
     * @return array<int, array{id: string, text: mixed}>
     */
    public static function selectOptions($custom_view): array
    {
        if (!$custom_view instanceof CustomView || $custom_view->view_kind_type != ViewKindType::AGGREGATE) {
            return [];
        }
        $options = [];
        foreach ($custom_view->getViewColumnsSelectOptions(true) as $option) {
            if (static::summaryOf($custom_view, array_get($option, 'id')) !== null) {
                $options[] = ['id' => (string) $option['id'], 'text' => $option['text'] ?? null];
            }
        }
        return $options;
    }

    /**
     * What is wrong with the 2つ目の値 fields of the posted options, the field at fault => a
     * message. Nothing on a type the form hides them on (the save drops them).
     *
     * @param array<string, mixed> $options  the box options as posted
     * @return array<string, string>
     */
    public static function errors(array $options): array
    {
        $type = $options['chart_type'] ?? null;
        if (!static::supportsType(is_string($type) ? $type : null)) {
            return [];
        }
        $errors = [];
        $column = $options[static::COLUMN] ?? null;
        if ($column === null || $column === '') {
            if ($type === ChartType::COMBO) {
                $errors[static::COLUMN] = (string) exmtrans('dashboard.chart_second.required');
            }
        } elseif (static::summaryOf(CustomView::getEloquent(is_scalar($options['target_view_id'] ?? null) ? $options['target_view_id'] : null), $column) === null) {
            $errors[static::COLUMN] = (string) exmtrans('dashboard.chart_second.not_summary');
        } elseif ($column === ($options['chart_axisy'] ?? null)) {
            $errors[static::COLUMN] = (string) exmtrans('dashboard.chart_second.same');
        }
        $draw = $options[static::TYPE] ?? null;
        if ($draw !== null && $draw !== '' && !in_array($draw, [static::LINE, static::BAR], true)) {
            $errors[static::TYPE] = trans('validation.in');
        }
        return $errors;
    }

    /**
     * The options a save stores: the column, its type (a line unless bars) and its axis (the
     * right one only when ticked), on the types the form shows them on; none of them without a
     * column.
     *
     * @param array<string, mixed> $options  the box options as posted (validated)
     * @return array<string, mixed>
     */
    public static function saved(array $options): array
    {
        $column = $options[static::COLUMN] ?? null;
        $type = $options['chart_type'] ?? null;
        if (!is_string($column) || $column === '' || !static::supportsType(is_string($type) ? $type : null)) {
            unset($options[static::COLUMN], $options[static::TYPE], $options[static::RIGHT]);
            return $options;
        }
        $options[static::TYPE] = ($options[static::TYPE] ?? null) === static::BAR ? static::BAR : static::LINE;
        // ticked: '1'; unticked, the form posts the checkbox's hidden empty value
        $options[static::RIGHT] = (string) ($options[static::RIGHT] ?? '') === '1' ? '1' : '0';
        return $options;
    }

    public function summary(): CustomViewSummary
    {
        return $this->summary;
    }

    /**
     * How the second value is drawn: LINE / BAR.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * Whether it has the right axis of its own (第2軸), else the left one with the first value.
     */
    public function onRight(): bool
    {
        return $this->right;
    }

    /**
     * Its name, as the 値 axis names the first value: the view column's own, else the item's.
     */
    public function label(): string
    {
        $named = array_get($this->summary, 'view_column_name');
        return (string) (is_nullorempty($named) ? $this->summary->column_item->label() : $named);
    }

    /**
     * Its summary condition (SummaryCondition).
     */
    public function condition(): string
    {
        return (string) $this->summary->view_summary_condition;
    }

    /**
     * How its numbers read (unitOf).
     *
     * @return array{prefix: string, suffix: string, digits: int|null}
     */
    public function unit(): array
    {
        return static::unitOf($this->summary, $this->condition());
    }

    /**
     * How the numbers of a measure read, as the view shows them: a count in 件, a currency with its
     * symbol before or after, as many decimals as the view rounds them to (an integer none, an
     * average its column's or 2) — `digits` null: as many as they have.
     *
     * @param mixed $summary  the measure (CustomViewSummary)
     * @param mixed $condition  the summary condition it is drawn by
     * @return array{prefix: string, suffix: string, digits: int|null}
     */
    public static function unitOf($summary, $condition): array
    {
        $condition = (string) $condition;
        if ($condition === SummaryCondition::COUNT) {
            return ['prefix' => '', 'suffix' => (string) exmtrans('dashboard.chart_second.count_unit'), 'digits' => 0];
        }
        $column = is_object($summary) ? data_get($summary, 'custom_column') : null;
        $column_type = data_get($column, 'column_type');
        $decimal = data_get($column, 'options.decimal_digit');
        $decimal = is_numeric($decimal) ? (int) $decimal : null;
        if ($condition === SummaryCondition::AVG) {
            $digits = $decimal ?? 2;
        } elseif ($column_type === ColumnType::INTEGER) {
            $digits = 0;
        } else {
            $digits = in_array($column_type, [ColumnType::DECIMAL, ColumnType::CURRENCY], true) ? $decimal : null;
        }
        $unit = ['prefix' => '', 'suffix' => '', 'digits' => $digits];
        $symbol = $column_type === ColumnType::CURRENCY ? CurrencySymbol::getEnum(data_get($column, 'options.currency_symbol')) : null;
        if ($symbol instanceof CurrencySymbol) {
            $option = (array) $symbol->getOption();
            $unit[($option['type'] ?? '') === 'before' ? 'prefix' : 'suffix'] = (string) ($option['text'] ?? '');
        }
        return $unit;
    }

    /**
     * Excel's number format of a measure's cells and axis: grouped by thousands with its decimals
     * (as many as $values have when it has none of its own), its unit around it — 394件, ¥1,200.
     * `$millions`: the numbers shown in millions (#,##0,,"百万"), for an axis of large ones.
     *
     * @param array{prefix: string, suffix: string, digits: int|null} $unit
     * @param array<int, mixed> $values
     */
    public static function excelFormat(array $unit, array $values, bool $millions = false): string
    {
        $digits = $unit['digits'];
        if ($millions) {
            $number = '#,##0,,"' . static::quoted((string) exmtrans('dashboard.chart_second.excel_million')) . '"';
        } else {
            if ($digits === null) {
                $digits = 0;
                foreach ($values as $value) {
                    if (is_numeric($value)) {
                        $fixed = rtrim(rtrim(sprintf('%.10F', $value + 0), '0'), '.');
                        $dot = strpos($fixed, '.');
                        $digits = max($digits, $dot === false ? 0 : strlen($fixed) - $dot - 1);
                    }
                }
            }
            $number = $digits > 0 ? '#,##0.' . str_repeat('0', $digits) : '#,##0';
        }
        $prefix = $unit['prefix'] === '' ? '' : '"' . static::quoted($unit['prefix']) . '"';
        $suffix = $unit['suffix'] === '' ? '' : '"' . static::quoted($unit['suffix']) . '"';
        return $prefix . $number . $suffix;
    }

    /**
     * The units an axis of large numbers counts in, largest first: [[10000, '万'], ...] (the
     * language's, dashboard.chart_second.compact_units "100000000:億,10000:万").
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public static function compactUnits(): array
    {
        $units = [];
        foreach (explode(',', (string) exmtrans('dashboard.chart_second.compact_units')) as $pair) {
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2 && ctype_digit(trim($parts[0])) && (int) $parts[0] > 1) {
                $units[] = [(int) $parts[0], trim($parts[1])];
            }
        }
        usort($units, function ($a, $b) {
            return $b[0] <=> $a[0];
        });
        return $units;
    }

    /**
     * A text inside the quotes of a number format: no quote of its own.
     */
    private static function quoted(string $text): string
    {
        return str_replace('"', '', $text);
    }
}
