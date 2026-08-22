<?php

namespace Exceedone\Exment\Enums;

/**
 * Chart types of a dashboard chart box, grouped by the dataset shape they render and the
 * library that draws them. The runtime chart-type switcher may move a box to any other
 * type of the SAME shape (single-series ↔ single-series, multi ↔ multi).
 */
class ChartType extends EnumBase
{
    // single series (labels[] + values[]) — Chart.js
    public const BAR = 'bar';
    public const LINE = 'line';
    public const PIE = 'pie';

    // single series — ECharts
    public const HBAR = 'hbar';
    public const AREA = 'area';
    public const DOUGHNUT = 'doughnut';
    public const RADAR = 'radar';
    public const FUNNEL = 'funnel';
    public const GAUGE = 'gauge';
    public const SCATTER = 'scatter';

    // multi series (X × series matrix from a 2-column aggregate view) — ECharts
    public const MBAR = 'mbar';
    public const SBAR = 'sbar';
    public const MLINE = 'mline';
    public const HEATMAP = 'heatmap';
    public const SAREA = 'sarea';
    public const TREEMAP = 'treemap';
    public const SUNBURST = 'sunburst';
    public const BOXPLOT = 'boxplot';

    // (only chart types may be constants here: EnumBase lists every constant as an option)

    /**
     * @return string[]
     */
    public static function chartjsTypes(): array
    {
        return [self::BAR, self::LINE, self::PIE];
    }

    /**
     * @return string[]
     */
    public static function echartsSingleTypes(): array
    {
        return [self::HBAR, self::AREA, self::DOUGHNUT, self::RADAR, self::FUNNEL, self::GAUGE, self::SCATTER];
    }

    /**
     * @return string[]
     */
    public static function multiTypes(): array
    {
        return [self::MBAR, self::SBAR, self::MLINE, self::HEATMAP, self::SAREA, self::TREEMAP, self::SUNBURST, self::BOXPLOT];
    }

    /**
     * Multi-series type (ECharts, pivoted matrix).
     */
    public static function isMulti($type): bool
    {
        return in_array($type, self::multiTypes(), true);
    }

    /**
     * Single-series type drawn by ECharts; anything else (bar / line / pie, or an unknown
     * legacy value) is drawn by Chart.js.
     */
    public static function isEcharts($type): bool
    {
        return in_array($type, self::echartsSingleTypes(), true);
    }

    /**
     * Types without X/Y axes (slices / petals) — the ones where a legend is meaningful.
     */
    public static function isCircular($type): bool
    {
        return in_array($type, [self::PIE, self::DOUGHNUT, self::FUNNEL, self::RADAR], true);
    }

    /**
     * Types whose option checkbox is "legend" rather than "begin at zero".
     *
     * @return string[]
     */
    public static function legendTypes(): array
    {
        return array_merge([self::PIE, self::DOUGHNUT, self::FUNNEL, self::RADAR], self::multiTypes());
    }

    /**
     * Types a box configured as $configured may be switched to at runtime.
     *
     * @return string[]
     */
    public static function switchPool($configured): array
    {
        if (self::isMulti($configured)) {
            return self::multiTypes();
        }
        return array_merge(self::chartjsTypes(), self::echartsSingleTypes());
    }

    /**
     * The type to render: the requested one when it is a legal switch, else the configured one.
     */
    public static function resolve($configured, $requested): ?string
    {
        if (is_string($requested) && in_array($requested, self::switchPool($configured), true)) {
            return $requested;
        }
        return $configured;
    }
}
