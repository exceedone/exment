<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckDefine;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Public web answer page for the safety-check mail fallback. No login: the mail
 * link is a Laravel signed URL pinning (event, user), so identity is proven by
 * the HMAC signature — tampering with either id invalidates it. GET only renders
 * (mail scanners prefetch links and must not answer on the user's behalf); POST
 * performs the write via SafetyCheckAction::recordAnswer (channel 'mail').
 * The real gate is the event status: a closed event blocks both verbs, which is
 * also why the signature carries no expiry of its own.
 *
 * Every response carries `Cache-Control: no-store` and `X-Robots-Tag: noindex`
 * (see resolve()/withSafetyHeaders()) — the page renders a person's safety
 * status behind nothing but a signed link (no auth), so it must never be
 * cached by a shared/browser cache or indexed by a search engine.
 */
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
                // The row existed at resolve()'s gate check (currentAnswer) but
                // recordAnswer's own lookup came up empty — e.g. a concurrent
                // delete between the two reads. A safety page must never claim
                // "recorded" when nothing was actually written.
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

    /**
     * Shared gate for both verbs: valid signature -> existing event -> open
     * event -> existing answer row; each failure renders the same blade in an
     * error/closed mode (no redirect: the visitor has nowhere else to go).
     * The success callback receives ($eventValue, $userId, $answerRow).
     *
     * Also the single choke point for the no-store/noindex headers (see class
     * docblock): every branch below, and the callback's own response, returns
     * through withSafetyHeaders() here, so no response can miss them.
     */
    protected function resolve(Request $request, \Closure $callback)
    {
        if (!$request->hasValidSignature()) {
            return $this->withSafetyHeaders(response()->view('exment::safety.answer', [
                'mode' => 'error', 'message' => exmtrans('safety.answer_invalid_link'),
            ], 403));
        }

        // safety tables have no role-group permission; scope would hide the event
        // even though the signature already proves this (event, user) pair.
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
