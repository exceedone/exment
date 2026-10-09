<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SafetyCheckAnswerController extends Controller
{
    public function form(Request $request)
    {
        return $this->resolve($request, function ($eventValue, $userId, $answerRow) use ($request) {
            return response()->view('exment::safety.answer', [
                'mode'      => 'form',
                'event'     => $eventValue,
                'answerRow' => $answerRow,
                'action'    => $request->fullUrl(),
            ]);
        });
    }

    public function submit(Request $request)
    {
        return $this->resolve($request, function ($eventValue, $userId, $answerRow) use ($request) {
            $status = $request->get('st');
            if (!in_array($status, SafetyCheckDefine::ANSWER_STATUSES, true)) {
                return response()->view('exment::safety.answer', [
                    'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_status'),
                ], 422);
            }

            $comment = trim((string) $request->get('comment'));
            $recorded = SafetyCheckAction::recordAnswer($eventValue->id, $userId, $status, 'mail', $comment === '' ? null : $comment);
            if (!$recorded) {
                return response()->view('exment::safety.answer', [
                    'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_link'),
                ], 404);
            }

            return response()->view('exment::safety.answer', [
                'mode'   => 'done',
                'status' => $status,
            ]);
        });
    }

    protected function resolve(Request $request, \Closure $callback)
    {
        if (!$request->hasValidSignature()) {
            return $this->withSafetyHeaders(response()->view('exment::safety.answer', [
                'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_link'),
            ], 403));
        }

        $eventValue = getModelName(SafetyCheckDefine::TABLE_EVENT)::withoutGlobalScope(CustomValueModelScope::class)
            ->find($request->get('event'));
        if (!$eventValue) {
            return $this->withSafetyHeaders(response()->view('exment::safety.answer', [
                'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_link'),
            ], 404));
        }

        if ($eventValue->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED) {
            return $this->withSafetyHeaders(response()->view('exment::safety.answer', [
                'mode' => 'closed',
            ]));
        }

        $userId = (int) $request->get('user');
        $answerRow = SafetyCheckAction::currentAnswer($eventValue->id, $userId);
        if (!$answerRow) {
            return $this->withSafetyHeaders(response()->view('exment::safety.answer', [
                'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_link'),
            ], 404));
        }

        return $this->withSafetyHeaders($callback($eventValue, $userId, $answerRow));
    }

    /**
     * @param \Illuminate\Http\Response $response
     * @return \Illuminate\Http\Response
     */
    protected function withSafetyHeaders($response)
    {
        return $response->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Robots-Tag'  => 'noindex',
        ]);
    }
}
