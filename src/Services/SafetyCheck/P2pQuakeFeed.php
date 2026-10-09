<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class P2pQuakeFeed implements EarthquakeFeedInterface
{
    protected const ENDPOINT = 'https://api.p2pquake.net/v2/history';

    protected const TIME_FORMAT = 'Y/m/d H:i:s';

    protected const FEED_TIMEZONE = 'Asia/Tokyo';

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
     * @return string
     */
    protected function endpoint(): string
    {
        $url = config('exment.safety_check.feed_url');
        return is_nullorempty($url) ? static::ENDPOINT : $url;
    }

    /**
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
     * @param array $item
     * @return Carbon|null
     */
    protected function parseReceivedAt(array $item): ?Carbon
    {
        $raw = $item['time'] ?? null;
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $time = $this->tryParseTime($raw, static::TIME_FORMAT . '.v');
        if ($time) {
            return $time;
        }

        return $this->tryParseTime(preg_replace('/\.\d+$/', '', $raw));
    }

    /**
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

        $raw = preg_replace('/\.\d+$/', '', $raw);

        return $this->tryParseTime($raw);
    }

    /**
     * @param string $raw
     * @param string|null $format
     * @return Carbon|null
     */
    protected function tryParseTime(string $raw, ?string $format = null): ?Carbon
    {
        try {
            return Carbon::createFromFormat($format ?? static::TIME_FORMAT, $raw, static::FEED_TIMEZONE)
                ->setTimezone(config('app.timezone'));
        } catch (Throwable $e) {
            return null;
        }
    }
}
