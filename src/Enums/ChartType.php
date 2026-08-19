<?php

namespace Exceedone\Exment\Enums;

class ChartType extends EnumBase
{
    // Chart.js types (existing)
    public const BAR = 'bar';
    public const LINE = 'line';
    public const PIE = 'pie';

    // ECharts single-series types. These reuse the same single-series chart data
    // (labels[] + values[]) but render through Apache ECharts for more variety.
    public const HBAR = 'hbar';                 // horizontal bar
    public const AREA = 'area';                 // area line
    public const DOUGHNUT = 'doughnut';         // doughnut
    public const RADAR = 'radar';               // radar
    public const FUNNEL = 'funnel';             // funnel
    public const GAUGE = 'gauge';               // gauge (KPI)
    public const SCATTER = 'scatter';           // scatter (index x value)


    // ECharts multi-series types. These need an aggregate Custom View grouped by
    // TWO columns: column #1 = X axis, column #2 = series (legend). The measure
    // (chart_axisy) fills each cell. Data is pivoted into an X x series matrix.
    public const MBAR = 'mbar';                 // grouped (clustered) bar
    public const SBAR = 'sbar';                 // stacked bar
    public const MLINE = 'mline';               // multi-line
    public const HEATMAP = 'heatmap';           // heatmap
    public const SAREA = 'sarea';               // stacked area
    public const TREEMAP = 'treemap';           // treemap (2-level hierarchy)
    public const SUNBURST = 'sunburst';         // sunburst (2-level hierarchy)
    public const BOXPLOT = 'boxplot';           // boxplot (distribution per category)

    /**
     * ECharts single-series types (reuse labels[] + values[]).
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function singleSeriesEchartsTypes()
    {
        return [
            self::HBAR,
            self::AREA,
            self::DOUGHNUT,
            self::RADAR,
            self::FUNNEL,
            self::GAUGE,
            self::SCATTER,
        ];
    }

    /**
     * Chart types rendered by Apache ECharts.
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function echartsTypes()
    {
        return array_merge(self::singleSeriesEchartsTypes(), self::multiSeriesTypes());
    }

    /**
     * ECharts multi-series types (need a 2-column aggregate view, pivoted to a matrix).
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function multiSeriesTypes()
    {
        return [
            self::MBAR,
            self::SBAR,
            self::MLINE,
            self::HEATMAP,
            self::SAREA,
            self::TREEMAP,
            self::SUNBURST,
            self::BOXPLOT,
        ];
    }

    /**
     * Whether the type needs pivoted multi-series data (X x series matrix).
     *
     * @param string|null $type
     * @return bool
     */
    // @phpstan-ignore-next-line
    public static function isMultiSeries($type)
    {
        return in_array($type, self::multiSeriesTypes());
    }

    /**
     * Whether the given type is rendered by ECharts (vs the legacy Chart.js path).
     *
     * @param string|null $type
     * @return bool
     */
    // @phpstan-ignore-next-line
    public static function isEcharts($type)
    {
        return in_array($type, self::echartsTypes());
    }

    /**
     * "Pie family" chart types (no X/Y axes; slices/petals).
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function circularTypes()
    {
        return [self::PIE, self::DOUGHNUT, self::FUNNEL, self::RADAR];
    }

    /**
     * Whether the type is a "pie family" chart (no X/Y axes; slices/petals).
     *
     * @param string|null $type
     * @return bool
     */
    // @phpstan-ignore-next-line
    public static function isCircular($type)
    {
        return in_array($type, self::circularTypes());
    }

    /**
     * Types where a LEGEND is meaningful (multiple slices or series) rather than a
     * "begin at zero" Y-axis option. Used by the box form, the saving filter and the
     * AI default-option logic so all three stay consistent.
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function legendTypes()
    {
        return array_values(array_unique(array_merge(self::circularTypes(), self::multiSeriesTypes())));
    }
}
