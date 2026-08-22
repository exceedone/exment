<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Exceedone\Exment\Enums\DashboardBoxType;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Services\AiSummaryService;

/**
 * POST webapi/ai-summary — the AI summary strip under a chart box.
 *
 * Called only when a user expands the strip (never on page load). The query string
 * carries the same df_* / bf_* / ct params as the box's own AJAX, so the summary is
 * computed on exactly the rows the chart shows. Cached results are free; only a real
 * provider call counts against the per-user hourly rate limit.
 */
class AiSummaryController extends AdminControllerBase
{
    public function summary(Request $request): JsonResponse
    {
        $suuid = trim((string) $request->input('suuid', ''));
        $box = $suuid === '' ? null : DashboardBox::findBySuuid($suuid);
        if (!$box || $box->dashboard_box_type !== DashboardBoxType::CHART) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.ai.error_generic')], 404);
        }
        // the strip is absent when any gate is off, but hidden UI is never the only gate
        if (!AiSummaryService::enabledForBox($box)) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.ai.disabled')], 404);
        }

        $item = $box->dashboard_box_item;
        $data = $item ? $item->getInsightData() : null;
        if (empty($data) || empty($data['labels'])) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.message.need_setting')]);
        }

        $limit = (int) config('exment.ai.rate_limit', 30);
        $rateKey = 'ai_summary_rate_' . md5((string) (\Exment::getUserId() ?: $request->ip()));
        Cache::add($rateKey, 0, 3600); // anchors the rolling window
        if ((int) Cache::get($rateKey, 0) >= $limit) {
            return response()->json(['success' => false, 'message' => exmtrans('dashboard.ai.error_rate_limit', ['limit' => $limit])], 429);
        }

        // built directly: the container would inject a bare Guzzle client into the optional parameter
        $result = (new AiSummaryService())->summarize($suuid . '|' . $item->filterFingerprint(), $data);
        if ($result['success'] && !$result['cached']) {
            Cache::increment($rateKey);
        }
        return response()->json($result);
    }
}
