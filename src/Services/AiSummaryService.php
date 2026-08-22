<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;

/**
 * The AI summary under a chart box: deterministic key figures + outliers (AnomalyDetector)
 * and a short narrative from an OpenAI-compatible /chat/completions endpoint
 * (config exment.ai.*), cached per box + locale + data.
 *
 * Data-egress gates, all enforced server-side:
 *   1. exment.ai.summary_enabled   site-wide switch
 *   2. exment.ai.blocked_tables    tables whose data must never reach the provider
 *   3. dashboard options.ai_summary  per-dashboard opt-in (default OFF)
 * plus a value-level PII scan before anything leaves the server.
 */
class AiSummaryService
{
    /** @var Client */
    private $client;
    /** @var string */
    private $model;

    public function __construct(?Client $client = null)
    {
        $this->model = (string) config('exment.ai.model', 'openai/gpt-oss-20b');
        $this->client = $client ?? new Client([
            'base_uri' => rtrim((string) config('exment.ai.base_url', 'https://api.groq.com/openai/v1'), '/') . '/',
            'timeout' => (int) config('exment.ai.timeout', 30),
            'headers' => [
                'Authorization' => 'Bearer ' . (string) config('exment.ai.api_key', ''),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    // ---- gates ---------------------------------------------------------------------

    public static function enabled(): bool
    {
        return boolval(config('exment.ai.summary_enabled', true));
    }

    /**
     * @param mixed $table  CustomTable | id | table_name | null
     */
    public static function tableBlocked($table): bool
    {
        $raw = trim((string) config('exment.ai.blocked_tables', ''));
        if ($raw === '' || is_nullorempty($table)) {
            return false;
        }
        if ($table instanceof CustomTable) {
            $name = $table->table_name;
        } else {
            $model = CustomTable::getEloquent($table);
            $name = $model ? $model->table_name : (is_string($table) ? $table : null);
        }
        if (is_nullorempty($name)) {
            return false;
        }
        return in_array($name, array_map('trim', explode(',', $raw)), true);
    }

    /**
     * Whether the summary applies to this box: site switch ON, the box's dashboard opted
     * in, and the box's table not blocked. Used by the strip rendering AND the endpoint.
     */
    public static function enabledForBox($box): bool
    {
        if (!static::enabled() || is_nullorempty($box)) {
            return false;
        }
        if (static::tableBlocked(array_get($box, 'options.target_table_id'))) {
            return false;
        }
        $dashboard = $box->dashboard ?? null;
        return $dashboard ? boolval($dashboard->getOption('ai_summary')) : false;
    }

    // ---- summary -------------------------------------------------------------------

    /**
     * @param string $ref   cache reference of the box + filter state
     * @param array $data   ChartItem::getInsightData(): {title, chart_type, axis_x_label, axis_y_label, labels, values, is_aggregate}
     * @return array{success:bool, text:string, cached:bool, generated_at:string, stats:?array, anomalies:?array, message?:string}
     */
    public function summarize(string $ref, array $data): array
    {
        if (empty($data['labels'])) {
            return $this->failure(exmtrans('dashboard.message.need_setting'));
        }
        $payload = $this->redact($data, $ref);
        if ($payload === null) {
            return $this->failure(exmtrans('dashboard.ai.withheld'));
        }

        $stats = $this->stats($payload['rows']);
        $anomalies = AnomalyDetector::detect(array_column($payload['rows'], 'label'), array_column($payload['rows'], 'value'));

        $ttl = (int) config('exment.ai.summary_cache_ttl', 3600);
        $cacheKey = 'ai_summary_' . md5(implode('|', [
            $ref,
            app()->getLocale(),
            $payload['title'],
            $payload['chart_type'],
            (string) json_encode($payload['rows'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]));
        if ($ttl > 0 && is_string($cached = Cache::get($cacheKey))) {
            return ['success' => true, 'text' => $cached, 'cached' => true, 'generated_at' => '', 'stats' => $stats, 'anomalies' => $anomalies];
        }

        $result = $this->generate($payload, $stats, $anomalies);
        if ($result['success'] && $ttl > 0) {
            Cache::put($cacheKey, $result['text'], $ttl);
        }
        // the deterministic figures are attached even when the narrative failed
        return array_merge($result, ['cached' => false, 'stats' => $stats, 'anomalies' => $anomalies]);
    }

    /**
     * Key figures straight from the rows (exact, free): highest / lowest / average / range.
     */
    private function stats(array $rows): ?array
    {
        $points = [];
        foreach ($rows as $row) {
            if (is_numeric($row['value'])) {
                $points[] = ['label' => $row['label'], 'value' => (float) $row['value']];
            }
        }
        if (empty($points)) {
            return null;
        }
        $highest = $lowest = $points[0];
        $total = 0.0;
        foreach ($points as $p) {
            $total += $p['value'];
            if ($p['value'] > $highest['value']) {
                $highest = $p;
            }
            if ($p['value'] < $lowest['value']) {
                $lowest = $p;
            }
        }
        return [
            'count' => count($points),
            'average' => $total / count($points),
            'range' => $highest['value'] - $lowest['value'],
            'highest' => $highest,
            'lowest' => $lowest,
        ];
    }

    /**
     * Privacy filter before anything leaves the server. Returns null when the whole chart
     * is withheld (PII in the metric values); anonymises category labels that look like PII.
     */
    private function redact(array $data, string $ref): ?array
    {
        $aggregate = !empty($data['is_aggregate']);
        if ($this->containsPii($data['values'] ?? [], !$aggregate)) {
            Log::info('[AiSummary] chart withheld (sensitive values)', ['ref' => $ref]);
            return null;
        }
        $anonymise = $this->containsPii($data['labels'] ?? [], true);
        if ($anonymise) {
            Log::info('[AiSummary] category labels anonymised', ['ref' => $ref]);
        }

        $rows = [];
        foreach (array_values($data['labels']) as $i => $label) {
            $rows[] = ['label' => $anonymise ? '#' . ($i + 1) : (string) $label, 'value' => array_values($data['values'])[$i] ?? null];
        }

        // the box title is free text — scrub emails / long digit runs (phone, card, id)
        $title = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[redacted]', (string) ($data['title'] ?? ''));
        $title = preg_replace_callback('/\d[\d\s\-]{7,17}\d/', function ($m) {
            return strlen(preg_replace('/\D/', '', $m[0])) >= 9 ? '[redacted]' : $m[0];
        }, $title);

        return [
            'title' => $title,
            'chart_type' => (string) ($data['chart_type'] ?? ''),
            'axis_x_label' => $anonymise ? 'Category (anonymised)' : (string) ($data['axis_x_label'] ?? ''),
            'axis_y_label' => (string) ($data['axis_y_label'] ?? ''),
            'rows' => $rows,
            'anonymised' => $anonymise,
            'aggregate' => $aggregate,
        ];
    }

    /**
     * An email in any value always counts; phone / card / id shaped numbers count only when
     * asked ($numbers) and when at least half of the values look like one.
     */
    private function containsPii(array $strings, bool $numbers): bool
    {
        $checked = 0;
        $numeric = 0;
        foreach ($strings as $value) {
            $s = trim((string) $value);
            if ($s === '') {
                continue;
            }
            $checked++;
            if (preg_match('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $s)) {
                return true;
            }
            $digits = strlen(preg_replace('/\D/', '', $s));
            if ($digits >= 9 && $digits <= 19 && $digits >= (int) ceil(mb_strlen($s) * 0.6)) {
                $numeric++;
            }
        }
        return $numbers && $checked > 0 && $numeric >= (int) ceil($checked * 0.5);
    }

    /**
     * One chat completion turning the redacted payload into a short paragraph.
     */
    private function generate(array $payload, ?array $stats, ?array $anomalies): array
    {
        $langMap = ['ja' => 'Japanese', 'en' => 'English', 'vi' => 'Vietnamese'];
        $lang = $langMap[app()->getLocale()] ?? 'the dashboard UI language';

        $maxRows = max(1, (int) config('exment.ai.max_data_rows', 50));
        $rows = $payload['rows'];
        $truncated = count($rows) > $maxRows;
        if ($truncated) {
            // keep the extremes so ranking statements beyond the cap stay grounded
            usort($rows, fn ($a, $b) => (float) ($b['value'] ?? -INF) <=> (float) ($a['value'] ?? -INF));
            $top = (int) ceil($maxRows / 2);
            $rows = array_merge(array_slice($rows, 0, $top), array_slice($rows, -($maxRows - $top)));
        }

        $system = "You are a senior data analyst embedded in a BI dashboard. "
            . "Given ONE chart's real data, write a short, proactive insight for a busy manager.\n"
            . "Rules:\n"
            . "- LANGUAGE: write every sentence in {$lang}; keep the category labels' original spelling, never translate them.\n"
            . "- ONE cohesive paragraph of 3 to 5 sentences (about 40-90 words). No list markers, no heading, no markdown.\n"
            . "- Use ONLY the numbers and labels present in the data. Never invent, round differently or extrapolate a value.\n"
            . "- If the labels are TIME (dates, months, years) you may describe a trend; if they are CATEGORIES, compare across them and never assume an order.\n"
            . "- Cover the highest and lowest points with their exact values, the overall picture, any anomaly listed below, and end with one concrete takeaway.\n"
            . "- Do not speculate about causes that are not visible in the data."
            . $this->dataBlock($payload, $rows, $truncated)
            . $this->statsBlock($stats)
            . $this->anomalyBlock($anomalies);

        try {
            $request = [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => "Generate the insight for this chart now, entirely in {$lang}."],
                ],
                'temperature' => 0.3,
                'max_tokens' => (int) config('exment.ai.summary_max_tokens', 1200),
            ];
            // reasoning models spend completion tokens thinking first; a short summary needs little
            $effort = (string) config('exment.ai.reasoning_effort', '');
            if ($effort !== '') {
                $request['reasoning_effort'] = $effort;
            }
            $response = $this->client->post('chat/completions', ['json' => $request]);
            $body = json_decode((string) $response->getBody(), true);
            $text = trim((string) ($body['choices'][0]['message']['content'] ?? ''));
            if (!empty($body['usage']) && config('exment.ai.log_usage', true)) {
                Log::info('[AI Usage]', ['model' => $this->model, 'user_id' => \Exment::getUserId()] + $body['usage']);
            }
            if ($text === '') {
                return $this->failure(exmtrans('dashboard.ai.no_valid_answer'));
            }
            return ['success' => true, 'text' => $text, 'generated_at' => now()->format('Y-m-d H:i')];
        } catch (GuzzleException $e) {
            // generic message to the user; the upstream detail goes to the log only
            Log::error('[AiSummary] provider error: ' . $e->getMessage());
            return $this->failure($this->errorMessage($e));
        }
    }

    private function dataBlock(array $payload, array $rows, bool $truncated): string
    {
        $block = sprintf(
            "\n\n=== CHART ===\ntitle=\"%s\" | type=%s | x=\"%s\" | y=\"%s\" | points=%d",
            $payload['title'],
            $payload['chart_type'],
            $payload['axis_x_label'],
            $payload['axis_y_label'],
            count($payload['rows'])
        );
        $block .= "\nData (label = x-axis category, value = \"{$payload['axis_y_label']}\"):\n" . json_encode($rows, JSON_UNESCAPED_UNICODE);
        if ($truncated) {
            $block .= sprintf("\n(%d of %d points shown — the highest and lowest by value; never claim a category is absent.)", count($rows), count($payload['rows']));
        }
        if ($payload['anonymised']) {
            $block .= "\n(Category labels are anonymised as #1, #2, ... for privacy — do not guess what they represent.)";
        }
        return $block;
    }

    private function statsBlock(?array $stats): string
    {
        if ($stats === null) {
            return '';
        }
        return sprintf(
            "\nKey figures (AUTHORITATIVE — use exactly these labels and values): highest = \"%s\" at %s; lowest = \"%s\" at %s; average = %s over %d categories.",
            $stats['highest']['label'],
            $this->num($stats['highest']['value']),
            $stats['lowest']['label'],
            $this->num($stats['lowest']['value']),
            $this->num($stats['average']),
            $stats['count']
        );
    }

    private function anomalyBlock(?array $anomalies): string
    {
        if ($anomalies === null) {
            return '';
        }
        $range = $this->num($anomalies['lower']) . '..' . $this->num($anomalies['upper']);
        if (empty($anomalies['points'])) {
            return "\nAnomaly check (deterministic IQR rule): no significant anomaly — no point deviates meaningfully from the expected range [{$range}]. Describe the series as stable; do not fabricate an anomaly.";
        }
        $list = implode('; ', array_map(function ($p) {
            return sprintf('"%s"=%s (%s)', $p['label'], $this->num($p['value']), $p['direction'] === 'high' ? 'unusually high' : 'unusually low');
        }, $anomalies['points']));
        return "\nAnomaly check (deterministic IQR rule, expected range [{$range}]): these points fall OUTSIDE it: {$list}. Call them out with their exact values and direction; suggest what to verify, but do not invent a cause.";
    }

    private function num(float $v): string
    {
        return floor($v) === $v ? (string) (int) $v : rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    private function errorMessage(GuzzleException $e): string
    {
        if ($e instanceof ConnectException) {
            return exmtrans('dashboard.ai.api_error_timeout');
        }
        $status = ($e instanceof RequestException && $e->hasResponse()) ? $e->getResponse()->getStatusCode() : 0;
        if ($status === 429) {
            return exmtrans('dashboard.ai.api_error_rate');
        }
        if ($status === 401 || $status === 403) {
            return exmtrans('dashboard.ai.api_error_auth');
        }
        if ($status === 400) {
            return exmtrans('dashboard.ai.api_error_bad_request');
        }
        return exmtrans('dashboard.ai.api_error_unknown');
    }

    private function failure(string $message): array
    {
        return ['success' => false, 'text' => '', 'cached' => false, 'generated_at' => '', 'stats' => null, 'anomalies' => null, 'message' => $message];
    }
}
