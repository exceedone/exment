<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Enums\DashboardBoxType;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/**
 * AI summary service — the engine behind the per-chart "🧠 AI summary" strip.
 *
 * Given ONE chart box's data (ChartItem::getInsightData), it computes deterministic key
 * figures + outliers (AnomalyDetector), optionally grounds the narrative in a scope
 * benchmark (ChartItem::getBenchmarkData), and asks an OpenAI-compatible
 * /chat/completions endpoint (config exment.ai.*) for a short natural-language insight,
 * cached per box + locale + data hash.
 *
 * Privacy: data-egress gates (site switch, per-dashboard opt-in, blocked tables) plus a
 * value-level PII scan / anonymisation before anything leaves the server.
 */
class AiChatService
{
    private Client $client;
    private string $model;

    // =========================================================================
    // AI data-egress gates (security switches)
    //
    // Three layers, ALL enforced server-side (hiding UI is never the only gate):
    //   1. exment.ai.insight_enabled  — site-wide kill switch for the AI summary strip
    //      (the 🧠 insight strip).
    //   2. exment.ai.blocked_tables   — per-DATA boundary: tables whose rows/labels
    //      must never reach the LLM, whatever the feature switches say.
    //   3. dashboard options.ai_summary — per-dashboard OPT-IN (setting form switch
    //      「AI要約」, default OFF): the summary strip exists only where an admin
    //      deliberately enabled it, so confidential dashboards are safe by default.
    // =========================================================================

    /**
     * Site-wide switch for the proactive AI summary strip.
     *
     * @return bool
     */
    public static function insightEnabled(): bool
    {
        return boolval(config('exment.ai.insight_enabled', true));
    }

    /**
     * Whether a table's data is barred from every AI feature
     * (config exment.ai.blocked_tables — comma-separated table_name list).
     *
     * @param  mixed $table  CustomTable | table id | table_name | null
     * @return bool  true = blocked. Unresolvable/absent table = not blocked (no data claim).
     */
    public static function tableBlocked($table): bool
    {
        $raw = trim((string) config('exment.ai.blocked_tables', ''));
        if ($raw === '') {
            return false;
        }
        $name = null;
        if ($table instanceof CustomTable) {
            $name = $table->table_name;
        } elseif (!is_nullorempty($table)) {
            $t = CustomTable::getEloquent($table);
            // an unknown identifier still matches by literal name, so a config entry
            // can never be silently ignored because of a lookup hiccup
            $name = $t ? $t->table_name : (is_string($table) ? $table : null);
        }
        if (is_nullorempty($name)) {
            return false;
        }
        $blocked = array_filter(array_map('trim', explode(',', $raw)));
        return in_array($name, $blocked, true);
    }

    /**
     * DATA-level gate for one dashboard box: false when its target table is in
     * blocked_tables. Deliberately independent of the feature switches — chat and the
     * summary strip combine this with their own switch.
     *
     * @param  mixed $box  DashboardBox | null
     * @return bool
     */
    public static function aiAllowedForBox($box): bool
    {
        if (is_nullorempty($box)) {
            return true;
        }
        return !static::tableBlocked(array_get($box, 'options.target_table_id'));
    }

    /**
     * Whether the AI summary strip applies to this box:
     * site-wide switch ON, the box's dashboard explicitly opted IN (options.ai_summary,
     * the 「AI要約」 form switch — default OFF), and the table is not AI-blocked.
     * Used by both the strip rendering and the insight endpoint, so the UI and the
     * server can never disagree.
     *
     * @param  mixed $box  DashboardBox | null
     * @return bool
     */
    public static function summaryEnabledForBox($box): bool
    {
        if (!static::insightEnabled() || !static::aiAllowedForBox($box)) {
            return false;
        }
        $dashboard = is_nullorempty($box) ? null : ($box->dashboard ?? null);
        // opt-in: no dashboard (or switch off) = no summary
        return $dashboard ? boolval($dashboard->getOption('ai_summary')) : false;
    }

    public function __construct()
    {
        // config('exment.ai.*') = any OpenAI-compatible endpoint (api_key / base_url /
        // model). Groq by default; Gemini/OpenAI work by pointing the same three at them —
        // /chat/completions endpoint.
        $apiKey      = (string) config('exment.ai.api_key', '');
        $baseUrl     = (string) config('exment.ai.base_url', 'https://api.groq.com/openai/v1');
        $this->model = (string) config('exment.ai.model', 'openai/gpt-oss-20b');

        $baseUrl = rtrim($baseUrl, '/') . '/';
        $timeout = (int) config('exment.ai.timeout', 30);

        $this->client = new Client([
            'base_uri' => $baseUrl,
            'timeout'  => $timeout,
            'headers'  => [
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
        ]);
    }

    /**
     * Proactively generate a natural-language INSIGHT for a chart's data — the engine
     * behind the chart box's "🧠 AI summary" strip: push, not pull — no user question, just a
     * concise data summary (trend / highest-lowest / anomaly / short takeaway).
     *
     * The result is cached (ai.insight_cache_ttl) keyed by box + locale + a hash of the data,
     * so a dashboard reload does NOT re-hit the LLM until the data changes. The data passes
     * {@see redactInsightData} before anything leaves the server.
     *
     * @param  string $cacheRef  box suuid (cache key + redaction log ref)
     * @param  array  $rawData   ChartItem::getInsightData() output
     * @param  int    $viewId    source view id (0 when unknown)
     * @return array{success:bool,insight:string,cached:bool,generated_at:string,message?:string}
     */
    public function insight(string $cacheRef, array $rawData, int $viewId = 0): array
    {
        if (is_nullorempty($rawData) || empty($rawData['labels'])) {
            return ['success' => false, 'insight' => '', 'cached' => false, 'generated_at' => '', 'message' => exmtrans('dashboard.message.need_setting')];
        }

        // Redact before anything leaves the server.
        $payload = $this->redactInsightData($rawData, $viewId, $cacheRef);
        if ($payload === null) {
            return ['success' => false, 'insight' => '', 'cached' => false, 'generated_at' => '', 'message' => exmtrans('dashboard.ai_insight.withheld')];
        }

        $ttl      = (int) config('exment.ai.insight_cache_ttl', 3600);
        // Key on the FULL series + title + chart type, so any data/label/type change (even
        // beyond the prompt-cap) regenerates. JSON_INVALID_UTF8_SUBSTITUTE guarantees a
        // non-false result — otherwise a bad byte makes json_encode return false, the key
        // degenerates, and stale insights are served across boxes. NOT keyed per user: the
        // rows are the box's own (filtered) data, identical for everyone who can open the
        // dashboard (permission is checked before the cache is read), so one generation
        // serves every viewer.
        $cacheKey = 'ai_insight_' . md5(implode('|', [
            $cacheRef,
            app()->getLocale(),   // language depends on UI locale — key must vary by it
            (string) $payload['title'],
            (string) $payload['chart_type'],
            (string) json_encode($payload['rows_full'] ?? $payload['rows'], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
        ]));

        // Deterministic "key figures" and anomalies computed from the SAME redacted rows the
        // LLM sees. They are exact and free (no LLM), so they are attached on EVERY path rather
        // than cached — even an old cache entry (created before this existed) gets correct
        // tiles + outlier markers.
        $stats     = $this->computeInsightStats($payload);
        $anomalies = $this->computeAnomalies($payload);

        if ($ttl > 0) {
            $cached = Cache::get($cacheKey);
            if (is_array($cached)) {
                $cached['cached']    = true;
                $cached['stats']     = $stats;
                $cached['anomalies'] = $anomalies;
                return $cached;
            }
        }

        // Scope benchmark: when the dashboard filter narrows the chart, the proactive insight
        // can position the scope against its parent level and the overall mean instead of
        // narrating in a vacuum. Runs only here, below the cache short-circuit.
        $benchmark = $this->buildBenchmarkBlock($cacheRef);
        $result = $this->generateInsight($payload, $benchmark);

        if ($result['success'] && $ttl > 0) {
            Cache::put($cacheKey, $result, $ttl);
        }
        $result['cached']    = false;
        $result['stats']     = $stats;
        $result['anomalies'] = $anomalies;
        return $result;
    }

    /**
     * Compute the deterministic key figures (highest / lowest / average / spread) shown as
     * scannable stat tiles above the insight prose. Straight from the redacted rows — no LLM,
     * so the numbers are exact and free. Returns null when no numeric row exists (nothing to
     * summarise), which the frontend treats as "prose only".
     *
     * @param  array $payload  redacted chart payload from redactInsightData()
     * @return array|null  {metric, count, total, average, range, highest:{label,value}, lowest:{label,value}, redacted}
     */
    private function computeInsightStats(array $payload): ?array
    {
        $points = [];
        // Full series (not the prompt-capped 'rows') so the tiles match the chart exactly.
        foreach (($payload['rows_full'] ?? $payload['rows'] ?? []) as $row) {
            $value = $row['value'] ?? null;
            if (is_numeric($value)) {
                $points[] = ['label' => (string) ($row['label'] ?? ''), 'value' => (float) $value];
            }
        }
        if (count($points) < 1) {
            return null;
        }

        $highest = $lowest = $points[0];
        $total = 0.0;
        foreach ($points as $p) {
            $total += $p['value'];
            if ($p['value'] > $highest['value']) { $highest = $p; }
            if ($p['value'] < $lowest['value'])  { $lowest = $p; }
        }
        $count = count($points);

        return [
            'metric'   => $payload['axis_y_label'] ?? '',
            'count'    => $count,
            'total'    => $total,
            'average'  => $total / $count,
            'range'    => $highest['value'] - $lowest['value'],
            'highest'  => $highest,
            'lowest'   => $lowest,
            'redacted' => !empty($payload['x_redacted']),
        ];
    }

    /**
     * Detect anomalies with a deterministic statistical rule (Tukey's IQR fences),
     * computed from the SAME redacted rows the LLM sees — so the flagged points are exact
     * and free (no LLM), mirroring {@see computeInsightStats}. This is the engine behind the
     * Power-BI-style "expected range": a point outside [Q1 - k·IQR, Q3 + k·IQR] is an outlier,
     * and those fence bounds double as the normal band the frontend shows.
     *
     * Returns null when there are too few numeric points (< ai.anomaly_min_points) to trust
     * quartiles, or when the series has no spread (IQR = 0) — better to claim nothing than to
     * flag noise on a tiny or flat series.
     *
     * @param  array $payload  redacted chart payload from redactInsightData()
     * @return array|null  {method,k,q1,q3,iqr,median,lower,upper,count,points:[{index,label,value,direction,deviation}]}
     */
    private function computeAnomalies(array $payload): ?array
    {
        // Delegate to the shared detector so the outliers flagged here (insight text + strip)
        // are identical to the ones ChartItem marks on the chart. Use the FULL series (not the
        // prompt-capped 'rows') so a point beyond max_data_rows is still detected and its index
        // maps to the chart's data index.
        $source = $payload['rows_full'] ?? $payload['rows'] ?? [];
        $labels = array_column($source, 'label');
        $values = array_column($source, 'value');
        return AnomalyDetector::detect($labels, $values);
    }

    /**
     * Format a number for a prompt line: integer when whole, else up to 2 decimals,
     * trailing zeros trimmed. Keeps the anomaly facts compact and unambiguous.
     */
    private function formatNum(float $v): string
    {
        if (floor($v) === $v) {
            return (string) (int) $v;
        }
        return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    }

    /**
     * Build the compact "detected anomalies" fact line appended to the focused-chart prompt
     * block, so the LLM EXPLAINS the exact outliers we found (grounded, not eyeballed) — or
     * states the series is stable when none fall outside the expected range. Returns '' when
     * anomalies could not be computed (too few points), leaving the generic guidance to stand.
     */
    private function buildAnomalyPromptBlock(?array $anomalies): string
    {
        if ($anomalies === null) {
            return '';
        }
        $range = sprintf('%s..%s', $this->formatNum($anomalies['lower']), $this->formatNum($anomalies['upper']));

        if (empty($anomalies['points'])) {
            // "no significant" (not "every point within range"): a point may sit outside the
            // fence yet be suppressed by the min_rel meaningfulness floor — claiming literal
            // containment would be false and the model could be caught contradicting the rows.
            return sprintf(
                "\nAnomaly check (deterministic IQR rule): no significant anomaly — no point deviates meaningfully from the expected range [%s]. Describe the series as stable and do not fabricate an anomaly.",
                $range
            );
        }

        $list = implode('; ', array_map(function ($p) {
            return sprintf('"%s"=%s (%s)', $p['label'], $this->formatNum($p['value']), $p['direction'] === 'high' ? 'unusually high' : 'unusually low');
        }, $anomalies['points']));

        return sprintf(
            "\nAnomaly check (deterministic IQR rule, expected range [%s]): these points fall OUTSIDE it: %s. Call them out explicitly with their exact values and say whether they are high or low; suggest what a manager should verify, but do NOT invent a specific cause you cannot see in the data.",
            $range,
            $list
        );
    }

    /**
     * Scope-benchmark prompt block ("how does the current selection compare upward?").
     *
     * Delegates the math to ChartItem::getBenchmarkData() — per-record means of the chart's
     * SUM measure at the current / parent / overall scopes, deterministic SQL — and renders
     * them as AUTHORITATIVE numbers with usage instructions. '' when there is nothing to
     * benchmark (no dashboard filter, non-SUM measure, …) so unfiltered charts cost nothing.
     * No category labels are sent — only dim display names and aggregate numbers, so there
     * is nothing to redact. Gated by config exment.ai.scope_benchmark.
     */
    private function buildBenchmarkBlock(?string $suuid): string
    {
        if (!$suuid || !config('exment.ai.scope_benchmark', true)) {
            return '';
        }
        try {
            $box = DashboardBox::findByUuid($suuid);
            if (!$box || $box->dashboard_box_type !== DashboardBoxType::CHART) {
                return '';
            }
            $item = $box->dashboard_box_item;
            if (!method_exists($item, 'getBenchmarkData')) {
                return '';
            }
            $b = $item->getBenchmarkData();
            if (is_nullorempty($b) || empty($b['current']) || empty($b['overall'])) {
                return '';
            }

            $block = "\n\n=== SCOPE BENCHMARK (deterministic; AUTHORITATIVE) ===";
            $block .= sprintf(
                "\nThe chart is narrowed by the dashboard filter (%s). Per-record mean of \"%s\": current selection = %s (over %d records)",
                implode(', ', $b['filtered_by']),
                $b['measure_label'],
                $this->formatNum((float) $b['current']['mean']),
                (int) $b['current']['count']
            );
            if (!empty($b['parent'])) {
                $block .= sprintf(
                    '; one level up (same filters without "%s") = %s (%d records)',
                    (string) ($b['dropped'] ?? ''),
                    $this->formatNum((float) $b['parent']['mean']),
                    (int) $b['parent']['count']
                );
            }
            $block .= sprintf(
                '; entire dataset (no dashboard filter) = %s (%d records).',
                $this->formatNum((float) $b['overall']['mean']),
                (int) $b['overall']['count']
            );
            $block .= "\nWhen comparing the current selection to its parent level or to the overall average, use EXACTLY these numbers. If asked to compare against a scope NOT listed here (another sibling, a different level), say that number is not available in this chart's context — do NOT estimate it.";
            return $block;
        } catch (\Throwable $e) {
            Log::debug('[AiChatService] scope benchmark failed: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Single LLM completion that turns the redacted chart payload into a short bullet
     * insight. Tools are disabled (this never creates a chart); temperature is low for
     * stable, repeatable summaries.
     *
     * @param  array $payload  redacted chart payload from redactInsightData()
     * @return array{success:bool,insight:string,generated_at:string,message?:string}
     */
    private function generateInsight(array $payload, string $benchmark = ''): array
    {
        // Narrative language follows the admin UI locale (ja/en/vi) so a Japanese instance
        // gets Japanese insights, a Vietnamese one gets Vietnamese, etc. — instead of a
        // hardcoded default. Category labels are still quoted verbatim (never translated).
        $langMap = ['ja' => 'Japanese', 'en' => 'English', 'vi' => 'Vietnamese'];
        $lang = $langMap[app()->getLocale()] ?? 'the dashboard UI language';

        $system = "You are a senior data analyst embedded in a BI dashboard. "
            . "Given ONE chart's real data, write a short, proactive insight for a busy manager — "
            . "no one asked a question; you are volunteering what matters.\n"
            . "Rules:\n"
            . "- LANGUAGE (critical): Write EVERY sentence of the insight in {$lang}, even when the chart's labels or title are in a different language. Do NOT switch to the labels' language. Only the category label tokens themselves keep their original spelling (do not translate them); all surrounding prose must be {$lang}.\n"
            . "- Write it as ONE cohesive short paragraph of flowing prose — about 3 to 5 sentences (roughly 40-90 words). "
            . "It MUST NOT be a bulleted or numbered list: no \"•\", \"-\", \"*\" or \"1.\" markers, no line breaks between sentences, "
            . "no heading, no preamble, no markdown (no **bold**, *, #, or tables).\n"
            . "- Use ONLY the numbers and category labels present in the data below. NEVER invent a value, "
            . "round it differently, or extrapolate a number that is not in the rows. Quote each label exactly.\n"
            . "- Identify the x-axis kind FIRST. If the labels are TIME (dates, months, years, quarters) you may "
            . "describe a time trend. If they are CATEGORIES (names, products, subjects, statuses, etc.) then "
            . "COMPARE across categories instead — do NOT mention 'per month/day', growth rates, or any time "
            . "period, and do NOT assume the categories have an order.\n"
            . "- Cover, grounded strictly in the numbers: the highest and lowest points (with their exact values), "
            . "the overall picture (spread/comparison — or a time trend only when the x-axis is time), any clear "
            . "anomaly, and end with one short, concrete takeaway.\n"
            . "- Do not speculate about causes that are not visible in the data below.\n"
            . "- Be concise and factual."
            . $this->buildFocusedChartBlock($payload)
            . $benchmark;

        $messages = [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => "Generate the insight for this chart now. Write the entire response in {$lang}."],
        ];

        try {
            $request = [
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.3,
                'max_tokens'  => (int) config('exment.ai.insight_max_tokens', 1200),
            ];
            // Reasoning models (gpt-oss, o-series, ...) spend completion tokens on hidden
            // reasoning BEFORE the answer: at 700 tokens the reply came back EMPTY (finish_reason
            // "length", 698 reasoning tokens, 0 content). Ask them for a light reasoning pass —
            // a 3-5 sentence summary needs none — and give the answer a bigger budget.
            $effort = (string) config('exment.ai.reasoning_effort', '');
            if ($effort !== '') {
                $request['reasoning_effort'] = $effort;
            }
            $response = $this->client->post('chat/completions', ['json' => $request]);

            $body  = json_decode((string) $response->getBody(), true);
            $reply = trim($body['choices'][0]['message']['content'] ?? '');

            if (!empty($body['usage'])) {
                $this->logUsage($body['usage'], 'insight', $payload['view_id'] ?? null);
            }

            if ($reply === '') {
                return ['success' => false, 'insight' => '', 'generated_at' => '', 'message' => exmtrans('dashboard.ai_chat.no_valid_answer')];
            }

            return [
                'success'      => true,
                'insight'      => $reply,
                'generated_at' => now()->format('Y-m-d H:i'),
            ];
        } catch (GuzzleException $e) {
            [$errorCode, $userMsg, $status] = $this->mapApiError($e);
            Log::error(sprintf('[AiChatService] insight LLM error [%s/%d]: %s', $errorCode, $status, $e->getMessage()));
            return ['success' => false, 'insight' => '', 'generated_at' => '', 'message' => $userMsg];
        }
    }

    /**
     * Map a Guzzle exception to a stable [error_code, user_message, http_status] triple.
     * User messages are intentionally generic (no upstream detail leaks to the client);
     * the full upstream error is written to the log instead.
     *
     * @return array{0:string,1:string,2:int}
     */
    private function mapApiError(GuzzleException $e): array
    {
        // Network failure / timeout — no HTTP response available.
        if ($e instanceof ConnectException) {
            return ['timeout', exmtrans('dashboard.ai_chat.api_error_timeout'), 504];
        }

        $upstream = ($e instanceof RequestException && $e->hasResponse())
            ? $e->getResponse()->getStatusCode()
            : 0;

        switch (true) {
            case $upstream === 429:
                return ['rate_limit', exmtrans('dashboard.ai_chat.api_error_rate'), 429];
            case $upstream === 401 || $upstream === 403:
                return ['auth', exmtrans('dashboard.ai_chat.api_error_auth'), 502];
            case $upstream === 400:
                return ['bad_request', exmtrans('dashboard.ai_chat.api_error_bad_request'), 502];
            case $upstream >= 500:
                return ['upstream', exmtrans('dashboard.ai_chat.api_error_upstream'), 502];
            default:
                return ['unknown', exmtrans('dashboard.ai_chat.api_error_unknown'), 502];
        }
    }

    /**
     * Apply the privacy filter to a raw getInsightData() array and trim it to a compact,
     * LLM-ready payload. Used by the
     * proactive chart AI-summary strip ({@see insight}) so both enforce the SAME redaction
     * rules before any data leaves the server.
     *
     * @param  array  $data    getInsightData() output {title, chart_type, axis_x_label, axis_y_label, labels, values, is_aggregate}
     * @param  int    $viewId  source view id (for chart-suggestion context; 0 when unknown)
     * @param  string $logRef  identifier written to the redaction logs (suuid), never values
     * @return array|null      compact payload, or null when the whole chart is withheld
     */
    private function redactInsightData(array $data, int $viewId, string $logRef): ?array
    {
        // Privacy is decided by DATA, not by column names: a table an admin marks in
        // exment.ai.blocked_tables never reaches this point (aiAllowedForBox), and the
        // scans below look at the actual values (a column-NAME word list cannot see a
        // Japanese column name, so none is used).
        //
        // VALUE-level scan on the metric (Y): a column can hold PII in its values
        // (emails / phone / id numbers). For list(default) views the Y values are raw
        // per-record data, so scan them fully; for aggregate views Y is a computed
        // measure, so only the unambiguous email signal trips (a large legitimate sum
        // must not be mistaken for an id).
        if ($this->valuesContainPii($data['values'] ?? [], !empty($data['is_aggregate']))) {
            Log::info('[AiChatService] chart withheld from LLM (sensitive Y values)', [
                'ref'    => $logRef,
                'column' => $data['axis_y_label'] ?? '',
            ]);
            return null;
        }

        // If the category (x) labels look like PII (emails / phone / id numbers), anonymise
        // them but keep the values so trend/anomaly analysis still works without exposing
        // the raw categories.
        $xRedacted = $this->labelsContainPii($data['labels']);
        if ($xRedacted) {
            // column NAME only (never the values) so the logs never leak PII
            Log::info('[AiChatService] chart X-axis labels anonymised before LLM', [
                'ref'    => $logRef,
                'reason' => 'label-values',
                'column' => $data['axis_x_label'] ?? '',
            ]);
        }

        // rows_full = EVERY point, used for the deterministic figures/anomalies so they match
        // exactly what the chart draws (ChartItem detects over the full series too). rows =
        // capped copy that goes into the LLM prompt as a token guard. Keeping them separate is
        // what stops the "chart marks point #55 but the strip says stable" divergence.
        $rowsFull = [];
        foreach ($data['labels'] as $i => $label) {
            $rowsFull[] = [
                'label' => $xRedacted ? ('#' . ($i + 1)) : $label,
                'value' => $data['values'][$i] ?? null,
            ];
        }

        $maxRows   = max(1, (int) config('exment.ai.max_data_rows', 50));
        $truncated = count($rowsFull) > $maxRows;
        $sampled   = false;
        if (!$truncated) {
            $rows = $rowsFull;
        } elseif (!empty($data['is_aggregate'])) {
            // Category (aggregate) charts: a FIRST-N cut silently drops exactly the categories
            // users ask about ("top 10 / worst school"). Sample the extremes instead — highest
            // half + lowest half by value — so ranking questions beyond the cap stay grounded.
            // Time/list series keep the chronological head below (order carries meaning there).
            $byValue = $rowsFull;
            usort($byValue, function ($a, $b) {
                $av = is_numeric($a['value'] ?? null) ? (float) $a['value'] : -INF;
                $bv = is_numeric($b['value'] ?? null) ? (float) $b['value'] : -INF;
                return $bv <=> $av;
            });
            $topN    = (int) ceil($maxRows / 2);
            $botN    = $maxRows - $topN;
            $rows    = array_merge(
                array_slice($byValue, 0, $topN),
                $botN > 0 ? array_slice($byValue, -$botN) : []
            );
            $sampled = true;
        } else {
            $rows = array_slice($rowsFull, 0, $maxRows);
        }

        // Light PII scrub on the free-text box title before it reaches the LLM: it isn't a
        // validated column, so it can carry an email / phone / long id the axis redaction misses.
        $title = (string) ($data['title'] ?? '');
        $title = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[redacted]', $title);
        // Phone/card/long-id shapes only: require >= 9 actual DIGITS inside the candidate,
        // so an innocent year range ("2015 - 2024", 8 digits) survives while real numbers
        // (09x-xxxx-xxxx, 16-digit cards) are still scrubbed.
        $title = preg_replace_callback('/\d[\d\s\-]{7,17}\d/', function ($m) {
            return strlen(preg_replace('/\D/', '', $m[0])) >= 9 ? '[redacted]' : $m[0];
        }, $title);

        return [
            'view_id'      => $viewId,
            'title'        => $title,
            'chart_type'   => $data['chart_type'] ?? '',
            'axis_x_label' => $xRedacted ? 'Category (anonymised)' : ($data['axis_x_label'] ?? ''),
            'axis_y_label' => $data['axis_y_label'] ?? '',
            'record_count' => count($data['labels']),
            'rows'         => $rows,       // capped — for the LLM prompt (token guard)
            'rows_full'    => $rowsFull,   // full — for deterministic stats/anomalies (match the chart)
            'truncated'    => $truncated,
            'sampled'      => $sampled,    // true = rows are the highest/lowest sample, not first-N
            'x_redacted'   => $xRedacted,
        ];
    }

    // =========================================================================
    // Prompt builders
    // =========================================================================

    /**
     * Render the focused chart's real data as a compact prompt block.
     */
    private function buildFocusedChartBlock(array $chart): string
    {
        $block = sprintf(
            "\n\n=== FOCUSED CHART ===\nview_id=%d | title=\"%s\" | type=%s | x=\"%s\" | y=\"%s\" | points=%d",
            $chart['view_id'] ?? 0,
            $chart['title'] ?? '',
            $chart['chart_type'] ?? '',
            $chart['axis_x_label'] ?? '',
            $chart['axis_y_label'] ?? '',
            $chart['record_count'] ?? 0
        );

        $rowsJson = json_encode($chart['rows'] ?? [], JSON_UNESCAPED_UNICODE);
        $block .= "\nData (each row: label = x-axis category, value = y-axis \"{$chart['axis_y_label']}\"):\n{$rowsJson}";

        if (!empty($chart['truncated'])) {
            $block .= !empty($chart['sampled'])
                ? sprintf(
                    "\n(%d of %d points shown — sampled as the HIGHEST and LOWEST by value; mid-range points are omitted, so never claim a category is absent from the data.)",
                    count($chart['rows'] ?? []),
                    $chart['record_count'] ?? 0
                )
                : sprintf("\n(Showing first %d of %d points.)", count($chart['rows'] ?? []), $chart['record_count'] ?? 0);
        }
        if (!empty($chart['x_redacted'])) {
            $block .= "\n(Category labels are anonymised as #1, #2, ... for privacy — do not guess what they represent.)";
        }
        $block .= "\nBase any trend/anomaly analysis on these actual numbers.";

        // Ground the model in the EXACT highest/lowest/average we computed deterministically,
        // so it states them verbatim instead of re-deriving (and mis-attributing) which
        // category is the max/min — the classic "622.7 belongs to English, not Chemistry" slip.
        $block .= $this->buildKeyFiguresBlock($chart);

        // Ground the model in the exact outliers we detected deterministically (IQR),
        // so both the chat answer and the proactive insight explain the SAME points a
        // user sees flagged on the chart — instead of eyeballing anomalies from the rows.
        $block .= $this->buildAnomalyPromptBlock($this->computeAnomalies($chart));

        return $block;
    }

    /**
     * Build the "key figures" grounding line: the exact highest / lowest / average we computed
     * in {@see computeInsightStats}, injected as AUTHORITATIVE facts. Without this the model
     * re-scans the rows itself and regularly pins the max/min on the wrong category (or a
     * translated label), e.g. reporting the highest score under "Chemistry" when it is actually
     * "English". Returns '' when there is no numeric data to summarise.
     */
    private function buildKeyFiguresBlock(array $chart): string
    {
        $stats = $this->computeInsightStats($chart);
        if ($stats === null) {
            return '';
        }

        return sprintf(
            "\nKey figures (AUTHORITATIVE — when you name the highest or lowest, use EXACTLY these category labels and values; never attribute the highest/lowest value to a different category, and keep the labels' original spelling): highest = \"%s\" at %s; lowest = \"%s\" at %s; average = %s over %d categories.",
            $stats['highest']['label'] ?? '',
            $this->formatNum((float) ($stats['highest']['value'] ?? 0)),
            $stats['lowest']['label'] ?? '',
            $this->formatNum((float) ($stats['lowest']['value'] ?? 0)),
            $this->formatNum((float) ($stats['average'] ?? 0)),
            (int) ($stats['count'] ?? 0)
        );
    }

    /**
     * PII signals in a list of strings, shared by the X-label and Y-value scans:
     *   email   — any value containing an e-mail address (an unambiguous leak)
     *   checked — non-empty values looked at
     *   numeric — values that are MOSTLY a 9–19 digit number (phone / card / long id)
     *
     * @param array $strings
     * @return array{email: bool, checked: int, numeric: int}
     */
    private function piiSignals(array $strings): array
    {
        $emailRe = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/';
        $checked = 0;
        $numeric = 0;
        foreach ($strings as $value) {
            $s = trim((string) $value);
            if ($s === '') {
                continue;
            }
            $checked++;
            if (preg_match($emailRe, $s)) {
                return ['email' => true, 'checked' => $checked, 'numeric' => $numeric];
            }
            $digits = preg_replace('/\D/', '', $s);
            $len = strlen($digits);
            if ($len >= 9 && $len <= 19 && $len >= (int) ceil(mb_strlen($s) * 0.6)) {
                $numeric++;
            }
        }
        return ['email' => false, 'checked' => $checked, 'numeric' => $numeric];
    }

    /**
     * Value-level PII scan of the category (X) labels: an email, or at least half of the
     * non-empty labels looking like phone / card / id numbers (one stray numeric category
     * must not trigger it, a column full of them must). Used to anonymise the X axis so raw
     * PII values never reach the LLM even when the column NAME was innocuous.
     *
     * @param array $labels x-axis category labels
     */
    private function labelsContainPii(array $labels): bool
    {
        $sig = $this->piiSignals($labels);
        return $sig['email'] || ($sig['checked'] > 0 && $sig['numeric'] >= (int) ceil($sig['checked'] * 0.5));
    }

    /**
     * Value-level PII scan of the metric (Y) values. An email in ANY value always trips.
     * Phone / card / long-id shaped numbers only trip for raw per-record (list-view)
     * charts: for aggregate views the Y value is a computed measure (sum/count/avg) where a
     * large legitimate number must NOT be mistaken for an identifier.
     *
     * @param array $values    the metric values
     * @param bool  $aggregate true when values are aggregated measures (email-only scan)
     */
    private function valuesContainPii(array $values, bool $aggregate): bool
    {
        $sig = $this->piiSignals($values);
        if ($sig['email']) {
            return true;
        }
        if ($aggregate) {
            return false;
        }
        return $sig['checked'] > 0 && $sig['numeric'] >= (int) ceil($sig['checked'] * 0.5);
    }

    /**
     * Log token usage for cost monitoring (exment.ai.log_usage).
     */
    private function logUsage(array $usage, string $contextType, ?int $contextId): void
    {
        $total = (int) ($usage['total_tokens'] ?? 0);

        if (config('exment.ai.log_usage', true)) {
            Log::info('[AI Usage]', [
                'model'             => $this->model,
                'context_type'      => $contextType,
                'context_id'        => $contextId,
                'user_id'           => \Exment::getUserId(),
                'prompt_tokens'     => $usage['prompt_tokens']     ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $total,
                'ts'                => now()->toIso8601String(),
            ]);
        }

    }
}
