<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Reads the P2P地震情報 (P2PQuake) history API (code 551 = earthquake bulletin)
 * and normalizes it into the shape defined by EarthquakeFeedInterface.
 *
 * @see https://www.p2pquake.net/develop/json_api_v2/
 */
class P2pQuakeFeed implements EarthquakeFeedInterface
{
    /** Fallback when config exment.safety_check.feed_url is absent (e.g. stale published config). */
    protected const ENDPOINT = 'https://api.p2pquake.net/v2/history';

    protected const TIME_FORMAT = 'Y/m/d H:i:s';

    /** P2PQuake times carry no offset in the string and are always JST. */
    protected const FEED_TIMEZONE = 'Asia/Tokyo';

    /**
     * {@inheritDoc}
     */
    public function fetchRecent(int $limit = 10): array
    {
        try {
            $response = Http::get($this->endpoint(), [
                'codes' => 551,
                'limit' => $limit,
            ]);

            if ($response->failed()) {
                throw new \RuntimeException(
                    'p2pquake feed request failed with status ' . $response->status()
                );
            }

            $body = $response->json();
            if (!is_array($body)) {
                throw new \RuntimeException('p2pquake feed response is not a JSON array');
            }

            $items = [];
            foreach ($body as $raw) {
                if (!is_array($raw)) {
                    continue;
                }

                $parsed = $this->parseItem($raw);
                if ($parsed !== null) {
                    $items[] = $parsed;
                }
            }

            usort($items, function (array $a, array $b) {
                return $a['received_at'] <=> $b['received_at'];
            });

            return $items;
        } catch (Throwable $e) {
            Log::warning('safety check feed error', ['exception' => $e]);
            return [];
        }
    }

    /**
     * The feed endpoint, from config exment.safety_check.feed_url
     * (.env EXMENT_SAFETY_CHECK_FEED_URL) with a hardcoded fallback.
     *
     * @return string
     */
    protected function endpoint(): string
    {
        $url = config('exment.safety_check.feed_url');
        return is_nullorempty($url) ? static::ENDPOINT : $url;
    }

    /**
     * Normalize a single feed item. Returns null when the item is broken
     * (missing id, unparsable time, or no points).
     *
     * @param array $item
     * @return array|null
     */
    protected function parseItem(array $item): ?array
    {
        $id = $item['id'] ?? null;
        if (!is_string($id) || $id === '') {
            return null;
        }

        $time = $this->parseTime($item);
        if (!$time) {
            return null;
        }

        // Bulletin receive time (top-level `time`, milliseconds kept). Corrections that
        // upgrade the max scale share earthquake.time with the first prompt report, so
        // the watcher's cursor runs on this field, never on the occurred time.
        $receivedAt = $this->parseReceivedAt($item) ?? $time->copy();

        $rawPoints = $item['points'] ?? [];
        if (!is_array($rawPoints) || empty($rawPoints)) {
            return null;
        }

        $points = [];
        foreach ($rawPoints as $point) {
            if (!is_array($point)) {
                continue;
            }

            $points[] = [
                'pref' => (string) ($point['pref'] ?? ''),
                'scale' => (int) ($point['scale'] ?? -1),
            ];
        }

        if (empty($points)) {
            return null;
        }

        return [
            'id' => $id,
            'time' => $time,
            'received_at' => $receivedAt,
            'hypocenter' => $item['earthquake']['hypocenter']['name'] ?? '',
            'max_scale' => (int) ($item['earthquake']['maxScale'] ?? -1),
            'points' => $points,
        ];
    }

    /**
     * Parse the top-level `time` (when the P2P server received the bulletin),
     * keeping the fractional-seconds part. Returns null when absent/unparsable
     * (caller falls back to the occurred time).
     *
     * @param array $item
     * @return Carbon|null
     */
    protected function parseReceivedAt(array $item): ?Carbon
    {
        $raw = $item['time'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // with milliseconds first (e.g. "2026/08/11 09:00:00.550"), then without
        $time = $this->tryParseTime($raw, static::TIME_FORMAT . '.v');
        if ($time) {
            return $time;
        }

        return $this->tryParseTime(preg_replace('/\.\d+$/', '', $raw));
    }

    /**
     * Parse the bulletin time. Primary source is `earthquake.time`
     * (format Y/m/d H:i:s). Falls back to the top-level `time` field with
     * its fractional-seconds part stripped.
     *
     * @param array $item
     * @return Carbon|null
     */
    protected function parseTime(array $item): ?Carbon
    {
        $raw = $item['earthquake']['time'] ?? null;
        if (is_string($raw) && $raw !== '') {
            $time = $this->tryParseTime($raw);
            if ($time) {
                return $time;
            }
        }

        $raw = $item['time'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        // Strip a trailing fractional-seconds part, e.g. "2026/08/11 09:00:00.550".
        $raw = preg_replace('/\.\d+$/', '', $raw);

        return $this->tryParseTime($raw);
    }

    /**
     * @param string $raw
     * @param string|null $format defaults to TIME_FORMAT
     * @return Carbon|null
     */
    protected function tryParseTime(string $raw, ?string $format = null): ?Carbon
    {
        try {
            // Parse as JST, then convert to the app timezone so every downstream
            // format()/comparison (feed cursor, max_bulletin_age guard, event title)
            // works on app-tz values.
            return Carbon::createFromFormat($format ?? static::TIME_FORMAT, $raw, static::FEED_TIMEZONE)
                ->setTimezone(config('app.timezone'));
        } catch (Throwable $e) {
            return null;
        }
    }
}
