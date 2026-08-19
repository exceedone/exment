<?php

namespace Exceedone\Exment\Services;

/**
 * Deterministic anomaly detection via Tukey's IQR fences.
 *
 * Single source of truth shared by:
 *   - AiChatService  — grounds the AI insight/chat text in the exact outliers, and returns
 *                      them to the "🧠 AI summary" strip.
 *   - ChartItem      — draws the same points as red markers + an expected-range band on the
 *                      chart itself.
 *
 * Because both call this one detector, the points flagged ON the chart always match the
 * points the AI explains. No LLM is involved — the numbers are exact and free.
 *
 * Rule: a value outside [Q1 - k·IQR, Q3 + k·IQR] is an outlier; those fences double as the
 * "expected range" band. Tunable via config: exment.ai.anomaly_iqr_k, anomaly_min_points.
 */
class AnomalyDetector
{
    /**
     * Detect anomalies over parallel label/value arrays.
     *
     * @param  array $labels  category labels (index-aligned with $values)
     * @param  array $values  values; non-numeric entries are ignored
     * @return array|null  {method,k,q1,q3,iqr,median,lower,upper,count,points:[{index,label,value,direction,deviation}]}
     *                     or null when there are too few numeric points / no spread to judge.
     */
    public static function detect(array $labels, array $values): ?array
    {
        // Keep the ORIGINAL index so callers can map a flagged point back to its chart
        // position (marker) or label (strip). Non-numeric values are skipped, not indexed.
        $points = [];
        foreach ($values as $i => $v) {
            if (is_numeric($v)) {
                $points[] = ['index' => $i, 'label' => (string) ($labels[$i] ?? ''), 'value' => (float) $v];
            }
        }

        $minPoints = max(3, (int) config('exment.ai.anomaly_min_points', 5));
        if (count($points) < $minPoints) {
            return null; // too few points for quartiles to be meaningful
        }

        $sorted = array_map(fn ($p) => $p['value'], $points);
        sort($sorted);
        $q1     = self::percentile($sorted, 0.25);
        $q3     = self::percentile($sorted, 0.75);
        $median = self::percentile($sorted, 0.50);
        $iqr    = $q3 - $q1;

        if ($iqr > 0) {
            $k      = (float) config('exment.ai.anomaly_iqr_k', 1.5);
            $lower  = $q1 - $k * $iqr;
            $upper  = $q3 + $k * $iqr;
            $scale  = $iqr;     // deviation is measured in IQR units
            $method = 'iqr';
        } else {
            // Zero IQR: at least half the values are identical, so the quartile fence collapses
            // and a lone extreme point (e.g. [5,5,5,5,100]) would be missed entirely. Fall back
            // to a median-based robust scale — MAD (median absolute deviation), or the mean
            // absolute deviation when MAD is also 0 — so the obvious outlier is still caught.
            // A truly constant series (every value equal) has scale 0 → genuinely nothing to flag.
            $absDev = array_map(fn ($v) => abs($v - $median), $sorted);
            sort($absDev);
            $mad   = self::percentile($absDev, 0.50);
            $scale = $mad > 0 ? $mad * 1.4826 : (array_sum($absDev) / count($absDev)); // MAD→σ est., else mean abs dev
            if ($scale <= 0) {
                return null; // every value identical — no outlier exists
            }
            $k      = (float) config('exment.ai.anomaly_mad_k', 3.5);
            $lower  = $median - $k * $scale;
            $upper  = $median + $k * $scale;
            $method = 'mad';
        }

        // Meaningfulness floor: a point must not ONLY fall outside the IQR fence, but also
        // differ from the median by at least min_rel of the median's magnitude. On tightly
        // clustered data the fence collapses, so a point barely different from the cluster
        // (e.g. 622.7 among {618, 619, 620} — statistically "out" yet only ~0.6% off) would
        // otherwise be flagged and drown out the genuine outlier. This suppresses those trivial
        // flags while keeping real ones (e.g. 594.2 ≈ 4% off). Skipped when the median is ~0
        // (a percentage is meaningless there) so we fall back to pure IQR. Set min_rel=0 to disable.
        $minRel   = (float) config('exment.ai.anomaly_min_rel', 0.02);
        $ref      = abs($median);
        $applyRel = $minRel > 0 && $ref > 1e-9;

        $flagged = [];
        foreach ($points as $p) {
            if ($p['value'] > $upper || $p['value'] < $lower) {
                // Outside the fence but not meaningfully different from the centre → not an anomaly.
                if ($applyRel && abs($p['value'] - $median) < $minRel * $ref) {
                    continue;
                }
                $direction = $p['value'] > $upper ? 'high' : 'low';
                $edge      = $direction === 'high' ? $upper : $lower;
                // Severity = how far past the fence, expressed in the active scale's units
                // ($scale = IQR, or the MAD/mean-abs-dev fallback for a zero-IQR series).
                $flagged[] = [
                    'index'     => $p['index'],
                    'label'     => $p['label'],
                    'value'     => $p['value'],
                    'direction' => $direction,
                    'deviation' => round(abs($p['value'] - $edge) / $scale, 2),
                ];
            }
        }

        // Most severe first; cap so the prompt, strip and chart stay in sync and compact.
        usort($flagged, fn ($a, $b) => $b['deviation'] <=> $a['deviation']);
        $flagged = array_slice($flagged, 0, 5);

        return [
            'method' => $method,
            'k'      => $k,
            'q1'     => $q1,
            'q3'     => $q3,
            'iqr'    => $iqr,
            'median' => $median,
            'lower'  => $lower,
            'upper'  => $upper,
            'count'  => count($flagged),
            'points' => $flagged,
        ];
    }

    /**
     * Linear-interpolated percentile of a SORTED numeric array (0 <= $p <= 1).
     * Matches the "type 7" / Excel PERCENTILE.INC convention.
     */
    public static function percentile(array $sorted, float $p): float
    {
        $n = count($sorted);
        if ($n === 0) {
            return 0.0;
        }
        if ($n === 1) {
            return (float) $sorted[0];
        }
        $rank = $p * ($n - 1);
        $low  = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sorted[$low];
        }
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low);
    }
}
