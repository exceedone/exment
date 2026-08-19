<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Services\AiChatService;
use Exceedone\Exment\Enums\DashboardBoxType;

/**
 * AI insight controller — serves the proactive "🧠 AI summary" strip under a chart box.
 *
 * POST /admin/webapi/ai-insight - deterministic stats/anomalies + a short LLM narration
 *
 * Security: routes sit under [adminweb, admin] middleware, so the user is always
 * authenticated; per-request the endpoint re-checks the site-wide AI switch, the
 * dashboard's 「AI要約」 opt-in and the blocked-tables list (hidden UI is never the
 * only gate).
 */
class AiChatController extends AdminControllerBase
{
    /**
     * Proactive AI insight for a chart box — powers the "🧠 AI summary" strip that sits
     * under a chart. Lazy: the strip fetches this only when the user expands it, so a
     * dashboard load never triggers an LLM call.
     *
     * Request body (JSON):
     *   suuid    string   dashboard box suuid of the chart (required)
     *   df_* / bf_* / dfa  the box's current filter params (same as the box AJAX), so the
     *                      insight is computed on exactly the rows the chart shows
     *
     * Response 200: {success, insight, generated_at, cached}  (soft failures carry success:false + message)
     * Response 404: {success:false, message}  — chart box not found
     * Response 422/429: validation / quota
     *
     * A request is usually served from the service cache and is FREE — only a real LLM
     * call (cache miss) counts against the per-user rate limit. The cache is keyed by the
     * data itself, so a changed chart regenerates by itself.
     */
    public function insight(Request $request): JsonResponse
    {
        $suuid = trim((string) $request->input('suuid', ''));
        if ($suuid === '') {
            return response()->json(['success' => false, 'message' => 'suuid is required.'], 422);
        }
        $box = DashboardBox::findByUuid($suuid);
        if (!$box || $box->dashboard_box_type !== DashboardBoxType::CHART) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.ai_chat.error_generic')], 404);
        }

        // Same rule as the strip rendering (site-wide switch + per-dashboard 「AI要約」
        // opt-in + blocked tables): the strip is absent when any is off, but the endpoint
        // must refuse too — hidden UI is never the only gate.
        if (!AiChatService::summaryEnabledForBox($box)) {
            return response()->json(['success' => false, 'message' => 'AI summary is disabled.'], 404);
        }

        // Reuse the chart's own data pipeline (same numbers the chart draws).
        $item = $box->dashboard_box_item;
        $data = method_exists($item, 'getInsightData') ? $item->getInsightData() : null;
        if (is_nullorempty($data) || empty($data['labels'])) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.message.need_setting')], 200);
        }

        $userId = (string) (\Exment::getUserId() ?: $request->ip());

        // --- Rate limit (rolling 1h). Pre-CHECKED here; the slot is consumed only after a
        // fresh LLM call below, via an atomic increment (a read-before/put-after pattern would
        // clobber counts made by other requests while the slow LLM call is in flight). ---
        $limit   = (int) config('exment.ai.rate_limit', 30);
        $rateKey = 'ai_ratec_' . md5($userId);
        if ($this->rateSlotFull($rateKey, $limit, 3600)) {
            return response()->json([
                'success' => false,
                'message' => exmtrans('dashboard.ai_chat.error_rate_limit', ['limit' => $limit]),
            ], 429);
        }


        $viewId = (int) array_get($box, 'options.target_view_id');
        $result = app(AiChatService::class)->insight($suuid, $data, $viewId);

        // A cache hit is free; only a real LLM call (fresh) spends a rate-limit slot.
        if (($result['success'] ?? false) && ($result['cached'] ?? false) === false) {
            Cache::increment($rateKey);
        }

        // Soft failures (LLM/timeout) return 200 so the strip shows the message inline.
        return response()->json($result, 200);
    }

    /**
     * Rolling-window rate-limit CHECK: anchors the window and reports whether the
     * limit is reached, WITHOUT consuming a slot. For endpoints that only pay on a fresh
     * LLM call (insight) — they check here and Cache::increment() themselves afterwards.
     */
    private function rateSlotFull(string $key, int $limit, int $window): bool
    {
        Cache::add($key, 0, $window); // first request anchors the rolling window
        return (int) Cache::get($key, 0) >= $limit;
    }

}
