<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Services\WebhookService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * Endpoint an outside system posts events to.
 *
 * Deliberately not behind the admin login: a monitoring tool cannot hold a
 * browser session, and asking one to do an OAuth dance is why these
 * integrations never get built. It authenticates with a per-table shared
 * secret instead, and a table has to opt in before the endpoint will answer
 * for it at all - an unconfigured table is indistinguishable from one that
 * does not exist.
 *
 *   POST /admin/webhook/monitor_alert
 *   X-Exment-Token: <the table's secret>
 *   {"alert": {"name": "...", ...}}
 */
class WebhookController extends Controller
{
    /**
     * @param Request $request
     * @param string $tableKey
     * @return \Illuminate\Http\JsonResponse
     */
    public function receive(Request $request, string $tableKey)
    {
        $table = CustomTable::getEloquent($tableKey);
        $service = isset($table) ? new WebhookService($table) : null;

        // an unknown table and one that has not opted in answer the same way,
        // so probing cannot map which tables exist
        if (!isset($service) || !$service->enabled()) {
            return response()->json(['status' => 'error', 'message' => 'not found'], 404);
        }

        $token = $request->header('X-Exment-Token') ?: $request->input('token');
        if (!$service->authenticate(is_string($token) ? $token : null)) {
            // a rejected attempt is worth more to an auditor than an accepted
            // one, so it is logged too - against nobody, since the caller
            // proved nothing about who it is
            $this->log($request, $tableKey, null, ['status' => 'unauthorized']);
            return response()->json(['status' => 'error', 'message' => 'unauthorized'], 401);
        }

        $payload = $request->json()->all();
        if (empty($payload)) {
            $payload = $request->all();
        }
        if (empty($payload)) {
            return response()->json(['status' => 'error', 'message' => 'empty payload'], 422);
        }

        // attribute the writes to the service account the table names, so the
        // operation log says which integration did this rather than nobody
        $userId = $service->serviceUserId();
        if ($userId) {
            \Auth::guard(Define::AUTHENTICATE_KEY_WEB)->onceUsingId($userId);
        }

        try {
            $result = $service->receive($payload);
        } catch (\Throwable $e) {
            \Log::error('exment webhook failed: ' . $e->getMessage(), ['table' => $tableKey]);
            $this->log($request, $tableKey, $userId, ['status' => 'error']);
            return response()->json(['status' => 'error', 'message' => 'could not store the event'], 500);
        }

        $this->log($request, $tableKey, $userId, $result);

        return response()->json($result, array_get($result, 'status') == 'ok' ? 200 : 202);
    }

    /**
     * Record the call in the operation log.
     *
     * The log middleware only covers the admin web routes, and an integration
     * that can open and close tickets without leaving a trace is the first
     * thing an auditor asks about. Never throws: a failure to write the audit
     * row must not fail the event it was auditing.
     *
     * @param Request $request
     * @param string $tableKey
     * @param int|null $userId
     * @param array<string, mixed> $result
     * @return void
     */
    protected function log(Request $request, string $tableKey, $userId, array $result)
    {
        try {
            OperationLog::create([
                'user_id' => $userId ?: 0,
                'path' => substr('admin/webhook/' . $tableKey, 0, 255),
                'method' => $request->method(),
                'ip' => $request->getClientIp(),
                'input' => json_encode($request->all(), JSON_UNESCAPED_UNICODE),
                'event_type' => array_get($result, 'created') ? 'create' : 'update',
                'resource_type' => $tableKey,
                'resource_id' => array_get($result, 'id'),
                'after_json' => $result,
                'request_id' => Str::uuid()->toString(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('exment webhook could not write its audit row: ' . $e->getMessage());
        }
    }
}
