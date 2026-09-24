<?php

namespace Exceedone\Exment\Enums;

/**
 * Aggregate of a chart box's Y value (box option chart_aggregate), chosen per box like a
 * Power BI visual's summarization. Unset = the aggregate view's own summary condition.
 * sum / count / min / max are engine summary conditions applied in place of the view's;
 * avg has no engine counterpart and is derived as SUM / COUNT of the records.
 */
class ChartAggregate extends EnumBase
{
    public const SUM = 'sum';
    public const AVG = 'avg';
    public const COUNT = 'count';
    public const MIN = 'min';
    public const MAX = 'max';

    // (only aggregates may be constants here: EnumBase lists every constant as an option)

    /**
     * A valid aggregate, or null for unset / unknown (= as the view).
     *
     * @param mixed $value
     */
    public static function resolve($value): ?string
    {
        return is_string($value) && in_array($value, static::arrays(), true) ? $value : null;
    }

    /**
     * The engine summary condition standing in for $aggregate; null for avg.
     */
    public static function summaryCondition(string $aggregate): ?string
    {
        switch ($aggregate) {
            case self::SUM:
                return SummaryCondition::SUM;
            case self::COUNT:
                return SummaryCondition::COUNT;
            case self::MIN:
                return SummaryCondition::MIN;
            case self::MAX:
                return SummaryCondition::MAX;
        }
        return null;
    }

    /**
     * Display name (dashboard.chart_aggregate_options — its own words: the view form's
     * English says "Summary" for sum).
     */
    public static function label(string $aggregate): string
    {
        return exmtrans('dashboard.chart_aggregate_options.' . $aggregate);
    }

    /**
     * Box form options: '' (as the view) first, then every aggregate.
     *
     * @return array<string, string>
     */
    public static function formOptions(): array
    {
        return ['' => exmtrans('dashboard.dashboard_box_options.chart_aggregate_view')]
            + static::transArray('dashboard.chart_aggregate_options');
    }
}
