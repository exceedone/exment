<?php

namespace Exceedone\Exment\Services\Dashboard;

use Exceedone\Exment\Enums\ChartType;

/**
 * Presentation dispatch for chart boxes: which RENDER FAMILY a chart_type belongs to,
 * which blade serves that family, and which other types the same dataset could render
 * as (the runtime chart-type switcher rides on that).
 *
 * ChartType (Enums) stays the type CATALOG — the constants and the family lists live
 * there; this registry is the single place that turns a type into rendering decisions,
 * so ChartItem::body() no longer hard-codes the dispatch chain and a new type only
 * touches the catalog + (for a new family) one blade.
 *
 * Families and their dataset shapes:
 *   chartjs        labels[] + values[]      Chart.js canvas (bar / line / pie)
 *   echarts        labels[] + values[]      ECharts canvas, single series
 *   echarts_multi  X × series matrix        ECharts canvas, pivoted 2-group view
 *
 * An UNKNOWN or empty type renders through the chartjs family — exactly the fallthrough
 * the old if-chain in body() had — so legacy configs keep behaving identically.
 *
 * The runtime switcher (`ct` param on the box AJAX) may only move a box WITHIN its
 * dataset shape: chartjs ↔ echarts single-series types are one pool (same labels+values),
 * echarts_multi is its own pool. Validation is a
 * whitelist against those pools — an unknown or cross-shape request falls back to the
 * configured type, silently (presentation concern only; data/filter logic never sees it).
 */
class ChartRendererRegistry
{
    public const FAMILY_CHARTJS = 'chartjs';
    public const FAMILY_ECHARTS = 'echarts';
    public const FAMILY_ECHARTS_MULTI = 'echarts_multi';

    /**
     * Render family for a chart_type. Order mirrors the old body() dispatch chain:
     * multi-series, echarts single, then Chart.js as the fallthrough for
     * bar / line / pie AND any unknown value.
     *
     * @param string|null $type
     * @return string
     */
    public static function family($type)
    {

        if (ChartType::isMultiSeries($type)) {
            return static::FAMILY_ECHARTS_MULTI;
        }
        if (ChartType::isEcharts($type)) {
            return static::FAMILY_ECHARTS;
        }
        return static::FAMILY_CHARTJS;
    }

    /**
     * The types a box of $configuredType may switch to at RUNTIME — every type that
     * renders the very same dataset shape. Empty array = this box has no switcher
     * (anything a future family opts out of).
     *
     * @param string|null $configuredType
     * @return string[]
     */
    public static function switchPool($configuredType)
    {
        switch (static::family($configuredType)) {
            case static::FAMILY_CHARTJS:
            case static::FAMILY_ECHARTS:
                // one pool: labels[] + values[] renders on either canvas
                return array_merge([ChartType::BAR, ChartType::LINE, ChartType::PIE], ChartType::singleSeriesEchartsTypes());
            case static::FAMILY_ECHARTS_MULTI:
                return ChartType::multiSeriesTypes();
            default:
                return []; // a family without a canvas has no switcher
        }
    }

    /**
     * Resolve the type a box should actually render as: the runtime-requested type when
     * it is a legal switch for the configured one, else the configured type untouched.
     * Presentation-only by construction — callers still compute data from the SAME view.
     *
     * @param string|null $configuredType
     * @param mixed $requestedType  e.g. request()->input('ct'); non-string / unknown ignored
     * @return string|null
     */
    public static function effectiveType($configuredType, $requestedType)
    {
        if (!is_string($requestedType) || $requestedType === '' || $requestedType === $configuredType) {
            return $configuredType;
        }
        if (!in_array($requestedType, static::switchPool($configuredType), true)) {
            return $configuredType; // unknown type or cross-shape switch — ignore, render as configured
        }
        return $requestedType;
    }
}
