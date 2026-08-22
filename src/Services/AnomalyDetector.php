<?php

namespace Exceedone\Exment\Services;

/**
 * Deterministic outlier detection (Tukey's IQR fences) for the AI summary: the numbers
 * the strip shows and the LLM is told about are exact and computed here, never guessed.
 *
 * A value outside [Q1 - k·IQR, Q3 + k·IQR] is an outlier; the fences double as the
 * "expected range". Tunables: exment.ai.anomaly_iqr_k, anomaly_min_points,
 * anomaly_min_rel, anomaly_mad_k.
 */
class AnomalyDetector
{
    /**
     * @param array $labels  category labels (index-aligned with $values)
     * @param array $values  values; non-numeric entries are ignored
     * @return array|null {method,lower,upper,median,count,points:[{index,label,value,direction,deviation}]}
     *                    or null when there are too few numeric points / no spread to judge
     */
    public static function detect(array $labels, array $values): ?array
    {
        $points = [];
        foreach ($values as $i => $v) {
            if (is_numeric($v)) {
                $points[] = ['index' => $i, 'label' => (string) ($labels[$i] ?? ''), 'value' => (float) $v];
            }
        }

        $minPoints = max(3, (int) config('exment.ai.anomaly_min_points', 5));
        if (count($points) < $minPoints) {
            return null;
        }

        $sorted = array_map(fn ($p) => $p['value'], $points);
        sort($sorted);
        $q1 = self::percentile($sorted, 0.25);
        $q3 = self::percentile($sorted, 0.75);
        $median = self::percentile($sorted, 0.50);
        $iqr = $q3 - $q1;

        if ($iqr > 0) {
            $k = (float) config('exment.ai.anomaly_iqr_k', 1.5);
            $lower = $q1 - $k * $iqr;
            $upper = $q3 + $k * $iqr;
            $scale = $iqr;
            $method = 'iqr';
        } else {
            // Zero IQR (at least half the values identical): the quartile fence collapses and a
            // lone extreme point would be missed. Fall back to a median-based robust scale.
            $absDev = array_map(fn ($v) => abs($v - $median), $sorted);
            sort($absDev);
            $mad = self::percentile($absDev, 0.50);
            $scale = $mad > 0 ? $mad * 1.4826 : (array_sum($absDev) / count($absDev));
            if ($scale <= 0) {
                return null; // every value identical
            }
            $k = (float) config('exment.ai.anomaly_mad_k', 3.5);
            $lower = $median - $k * $scale;
            $upper = $median + $k * $scale;
            $method = 'mad';
        }

        // Meaningfulness floor: a flagged point must also differ from the median by min_rel
        // of its magnitude, so tightly clustered data is not over-flagged.
        $minRel = (float) config('exment.ai.anomaly_min_rel', 0.02);
        $ref = abs($median);
        $applyRel = $minRel > 0 && $ref > 1e-9;

        $flagged = [];
        foreach ($points as $p) {
            if ($p['value'] > $upper || $p['value'] < $lower) {
                if ($applyRel && abs($p['value'] - $median) < $minRel * $ref) {
                    continue;
                }
                $direction = $p['value'] > $upper ? 'high' : 'low';
                $edge = $direction === 'high' ? $upper : $lower;
                $flagged[] = [
                    'index' => $p['index'],
                    'label' => $p['label'],
                    'value' => $p['value'],
                    'direction' => $direction,
                    'deviation' => round(abs($p['value'] - $edge) / $scale, 2),
                ];
            }
        }

        usort($flagged, fn ($a, $b) => $b['deviation'] <=> $a['deviation']);
        $flagged = array_slice($flagged, 0, 5);

        return [
            'method' => $method,
            'lower' => $lower,
            'upper' => $upper,
            'median' => $median,
            'count' => count($flagged),
            'points' => $flagged,
        ];
    }

    /**
     * Linear-interpolated percentile of a SORTED numeric array (Excel PERCENTILE.INC).
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
        $low = (int) floor($rank);
        $high = (int) ceil($rank);
        if ($low === $high) {
            return (float) $sorted[$low];
        }
        return $sorted[$low] + ($sorted[$high] - $sorted[$low]) * ($rank - $low);
    }
}
