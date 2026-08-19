<?php

namespace Exceedone\Exment\Services\SafetyCheck;

/**
 * Reads an earthquake bulletin feed and normalizes it for the
 * safety-check (安否確認) feature.
 */
interface EarthquakeFeedInterface
{
    /**
     * Return the most recent earthquake bulletins, NORMALIZED and
     * sorted OLD -> NEW by received_at:
     * [['id' => string,
     *   'time' => \Carbon\Carbon,        // when the quake occurred (display)
     *   'received_at' => \Carbon\Carbon, // when the bulletin was received (feed cursor;
     *                                    // corrections share `time` but not received_at)
     *   'hypocenter' => string,
     *   'max_scale' => int, 'points' => [['pref' => string, 'scale' => int], ...]], ...]
     * Broken items (missing id/time/points) are skipped. Network error -> [] + Log::warning.
     *
     * @param int $limit
     * @return array
     */
    public function fetchRecent(int $limit = 10): array;
}
