<?php

namespace Exceedone\Exment\Services\Dashboard;

/**
 * Colors a dashboard editor painted on a chart box by hand (right-click a bar / slice /
 * series, the way Excel's Fill does), stored in the box option `chart_colors`:
 *
 *   {"points": {"国語": "#ff0000"}, "series": {"1年": "#4472c4", "": "#70ad47"}}
 *
 * `points` colors one category of a single-series chart, keyed by the category's text, so
 * the color stays on it through a re-sort, a filter or a switch of type. `series` colors a
 * series of a multi-series chart by its name; the key '' is the one series of a single-series
 * chart drawn in one color (line / area / radar / gauge). Anything not painted keeps the
 * palette color it gets today.
 */
final class ChartColors
{
    public const POINT = 'points';
    public const SERIES = 'series';
    /** series key of the one series of a single-series chart */
    public const SINGLE = '';

    /** @var array<string, array<string, string>> */
    private $colors;

    private function __construct(array $colors)
    {
        $this->colors = $colors;
    }

    /**
     * From the stored option; unknown kinds and anything but a hex color are dropped.
     *
     * @param mixed $raw
     */
    public static function fromOption($raw): self
    {
        $colors = [];
        foreach ([self::POINT, self::SERIES] as $kind) {
            foreach ((array) (is_array($raw) ? ($raw[$kind] ?? []) : []) as $key => $color) {
                $color = self::normalize($color);
                if ($color !== null) {
                    $colors[$kind][(string) $key] = $color;
                }
            }
        }
        return new self($colors);
    }

    /**
     * "#RGB" / "#RRGGBB" in lower case, else null.
     *
     * @param mixed $color
     */
    public static function normalize($color): ?string
    {
        return is_string($color) && preg_match('/\A#(?:[0-9a-f]{3}|[0-9a-f]{6})\z/i', trim($color)) ? strtolower(trim($color)) : null;
    }

    public function isEmpty(): bool
    {
        return empty($this->colors);
    }

    public function get(string $kind, $key): ?string
    {
        return $this->colors[$kind][(string) $key] ?? null;
    }

    /**
     * One color per name, index-aligned: the painted one, else the palette's, cycled by
     * position — what the chart shows today for anything not painted.
     *
     * @param iterable $names  category texts (POINT) or series names (SERIES), in drawing order
     * @param string[] $palette
     * @return string[]
     */
    public function colorsFor(string $kind, iterable $names, array $palette): array
    {
        $out = [];
        $i = 0;
        foreach ($names as $name) {
            $out[] = $this->get($kind, is_scalar($name) ? (string) $name : '') ?? $palette[$i % count($palette)];
            $i++;
        }
        return $out;
    }

    /**
     * The stored option with one color set (null / invalid color = back to the palette).
     *
     * @return array<string, array<string, string>>
     */
    public function with(string $kind, string $key, $color): array
    {
        $colors = $this->colors;
        $color = self::normalize($color);
        if ($color === null) {
            unset($colors[$kind][$key]);
            if (empty($colors[$kind])) {
                unset($colors[$kind]);
            }
        } else {
            $colors[$kind][$key] = $color;
        }
        return $colors;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public function toArray(): array
    {
        return $this->colors;
    }
}
