<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SummaryCondition;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewSummary;

/**
 * Runtime summary condition of a chart box (`ca` on the box request), picked in the box menu's
 * 集計方法 submenu: the chart's measure — a summary column of its aggregate view — is drawn summarized
 * another way (SUM / AVG / COUNT / MIN / MAX) for this render only.
 *
 * The numbers come from the view's own query: apply() swaps the summary column's condition in
 * memory while the box runs it and puts it back afterwards. Nothing is saved, nothing is
 * derived from the drawn values, and the choice is not remembered (a reload shows the view's
 * setting again). A condition the measure cannot take is ignored.
 */
final class ChartSummarySwitch
{
    /** the request value */
    public const PARAM = 'ca';

    /** @var CustomView|null */
    private $custom_view;

    /** @var CustomViewSummary|null the measure, when it is a summary column of the view */
    private $summary;

    /** @var string|null the condition the view sets */
    private $configured;

    /** @var string[] the conditions offered, in the view form's order */
    private $choices = [];

    /** @var string|null the picked condition when it differs from the view's, else null */
    private $picked;

    /** @var string|null columnLabel() memo */
    private $column_label;

    /**
     * @param CustomView|null $custom_view  the box's view
     * @param mixed $view_column_y  the measure (CustomViewSummary::getSummaryViewColumn of chart_axisy)
     * @param mixed $requested  `ca` of the request
     * @param bool $average_allowed  false when the rendered type cannot draw an average (ChartItem::isAverageOfExtraGroups)
     * @param bool $additive_only  the rendered type draws shares of a total (ChartType::isFullStacked):
     *   SUM / COUNT only; a view setting other than those leaves no switch
     */
    public function __construct($custom_view, $view_column_y, $requested, bool $average_allowed = true, bool $additive_only = false)
    {
        if (!$custom_view instanceof CustomView || !$view_column_y instanceof CustomViewSummary
            || $view_column_y->custom_view_id != $custom_view->id) {
            return;
        }
        $this->custom_view = $custom_view;
        $this->summary = $view_column_y;
        $this->configured = static::normalize(array_get($view_column_y, 'view_summary_condition'));

        $choices = static::conditionsFor($view_column_y);
        if (!$average_allowed) {
            $choices = array_values(array_filter($choices, function ($condition) {
                return $condition !== SummaryCondition::AVG || $condition === $this->configured;
            }));
        }
        if ($additive_only) {
            $choices = array_values(array_filter($choices, [self::class, 'isAdditive']));
        }
        // a measure set in a way the form no longer offers keeps it: no switch
        if ($this->configured === null || !in_array($this->configured, $choices, true)) {
            return;
        }
        $this->choices = $choices;

        $requested = static::normalize($requested);
        if ($requested !== null && $requested !== $this->configured && in_array($requested, $choices, true)) {
            $this->picked = $requested;
        }
    }

    /**
     * The conditions a summary column may be summarized by on a chart. A number column takes
     * every condition, as the view form offers it (CustomViewController::getSummaryCondition,
     * validateSummaryCondition). Any other column takes COUNT only: its MIN / MAX is a text or a
     * date, which no value axis can draw — the box form offers such a measure for COUNT only
     * (CustomView::getSelectColumn, is_number).
     *
     * @param CustomViewSummary $summary
     * @return string[]
     */
    public static function conditionsFor($summary): array
    {
        // a number column as the box form tells one (ConditionItems\ColumnItem::isSelectColumnNumber)
        if (ColumnType::isCalc(data_get($summary->custom_column, 'column_type'))) {
            return static::allConditions();
        }
        return [SummaryCondition::COUNT];
    }

    /**
     * Whether values summarized by $condition add up to a total (SUM, COUNT): what a share of a
     * total (a 100% stacked chart) needs. An average, a minimum, a maximum does not.
     *
     * @param mixed $condition
     */
    public static function isAdditive($condition): bool
    {
        return in_array(static::normalize($condition), [SummaryCondition::SUM, SummaryCondition::COUNT], true);
    }

    /**
     * Whether the toolbar offers the switch: the measure has another condition to take.
     */
    public function available(): bool
    {
        return count($this->choices) > 1;
    }

    /**
     * Whether the chart is drawn by a condition other than the view's.
     */
    public function switched(): bool
    {
        return $this->picked !== null;
    }

    /**
     * The condition the chart is drawn by; null when the measure is no summary column.
     */
    public function condition(): ?string
    {
        return $this->picked ?? $this->configured;
    }

    public function configured(): ?string
    {
        return $this->configured;
    }

    /**
     * @return string[]
     */
    public function choices(): array
    {
        return $this->choices;
    }

    /**
     * Run $query (the box's data query) with the measure summarized by condition(), then put
     * the view's setting back — on the view's own summary columns, which its query reads
     * (SummaryGrid::getQuery), and on the label their item was given meanwhile.
     *
     * @template T
     * @param callable(): T $query
     * @return T
     */
    public function apply(callable $query)
    {
        if (!$this->switched()) {
            return $query();
        }
        $saved = [];
        foreach ($this->summaryInstances() as $summary) {
            $saved[] = [$summary, $summary->view_summary_condition, $summary->column_item ? $summary->column_item->label() : null];
            $summary->view_summary_condition = $this->picked;
        }
        try {
            return $query();
        } finally {
            foreach ($saved as [$summary, $condition, $label]) {
                $summary->view_summary_condition = $condition;
                if ($summary->column_item && $label !== null) {
                    $summary->column_item->setLabel($label);
                }
            }
        }
    }

    /**
     * The name of a condition: 合計, 平均, …
     */
    public static function conditionLabel(?string $condition): string
    {
        if (static::normalize($condition) === null) {
            return '';
        }
        return (string) exmtrans('custom_view.summary_condition_options.' . SummaryCondition::getSummaryConditionName($condition));
    }

    /**
     * The measure's column as the view form names it, without the view's summary: 点数.
     */
    public function columnLabel(): string
    {
        if ($this->column_label === null) {
            $label = null;
            if ($this->summary !== null && $this->summary->view_column_type == ConditionType::COLUMN && $this->summary->custom_column) {
                // a fresh item: the view's own one carries the label its query gave it
                $label = CustomItem::getItem($this->summary->custom_column, null, $this->summary->view_column_target)->label();
            }
            $this->column_label = (string) ($label ?? '');
        }
        return $this->column_label;
    }

    /**
     * The measure's label when it is switched, as the view names a summary column it gives no
     * name of its own (SummaryGrid::setSummaryItem): 最小値 ： 点数. null when not switched.
     */
    public function valueLabel(): ?string
    {
        if (!$this->switched()) {
            return null;
        }
        return (string) exmtrans('common.format_keyvalue', static::conditionLabel($this->picked), $this->columnLabel());
    }

    /**
     * The view's summary columns that are the measure: the ones its query reads, and the
     * measure itself should it be another instance.
     *
     * @return CustomViewSummary[]
     */
    private function summaryInstances(): array
    {
        $instances = [];
        foreach ($this->custom_view->custom_view_summaries_cache as $summary) {
            if ($summary->id == $this->summary->id) {
                $instances[spl_object_id($summary)] = $summary;
            }
        }
        $instances[spl_object_id($this->summary)] = $this->summary;
        return array_values($instances);
    }

    /**
     * @param mixed $condition
     */
    private static function normalize($condition): ?string
    {
        if (!is_scalar($condition) || $condition === '') {
            return null;
        }
        $condition = (string) $condition;
        return in_array($condition, static::allConditions(), true) ? $condition : null;
    }

    /**
     * Every condition, in the view form's order (合計, 平均, 件数, 最小値, 最大値).
     *
     * @return string[]
     */
    private static function allConditions(): array
    {
        return array_map('strval', array_keys(SummaryCondition::getOptions()));
    }
}
