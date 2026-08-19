<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Support\Facades\Log;

/**
 * Sends a safety-check event to every user: pre-creates a `safety_check_answer` row
 * per user (status `not_answered`) and pushes a LINE Flex message to users who linked
 * their LINE account. LINE is the only delivery channel; users without a link keep their
 * `not_answered` row (flagged `unlinked_flg`) for the admin to follow up manually.
 * Also supports re-sending: only users whose answer is still `not_answered` get
 * another push, and any user MISSING a row (create failed at an earlier send) gets
 * one created — 再送 doubles as the recovery path.
 */
class SafetyCheckSender
{
    /**
     * @param CustomValue $eventValue CustomValue of `safety_check_event`.
     * @param bool $onlyUnanswered If true (re-send): only (re-)send to users whose row
     *                             is `not_answered`; rows missing after an earlier
     *                             failed create are still created (recovery).
     * @return array{target:int,line:int}
     */
    public static function send($eventValue, bool $onlyUnanswered = false): array
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);

        // existing answer rows for this event, keyed by user id
        $existingByUser = [];
        foreach ($answerTable->getValueQuery()->where('value->event', $eventValue->id)->get() as $row) {
            $existingByUser[(int) array_get($row->value, 'user')] = $row;
        }

        // only the ids are needed — don't hydrate every user row (full value JSON)
        $userIds = getModelName(SystemTableName::USER)::query()->pluck('id')->all();

        $linkedMap = LineAccountLink::whereIn('user_id', $userIds)
            ->whereNotNull('line_user_id')
            ->pluck('line_user_id', 'user_id');

        // Ensure a row per user on EVERY send, resend included: a user whose row
        // creation failed at the first send (logged below) would otherwise be
        // invisible in 未回答 and unreachable forever — 再送 recreates the missing
        // row (as not_answered) and reaches them, making it the recovery path.
        foreach ($userIds as $userId) {
            $userId = (int) $userId;
            if (isset($existingByUser[$userId])) {
                continue;
            }
            $linked = $linkedMap->has($userId);
            try {
                $answerRow = $answerTable->getValueModel();
                $answerRow->setValue([
                    'event' => $eventValue->id,
                    'user' => $userId,
                    'answer_status' => SafetyCheckDefine::ANSWER_NOT_ANSWERED,
                    'unlinked_flg' => $linked ? 0 : 1,
                ])->save();
                $existingByUser[$userId] = $answerRow;
            } catch (\Throwable $e) {
                // one bad row must not abort the rest of the loop: users after this
                // point still get their answer row. This user gets no row this send,
                // but the next 再送 retries the create.
                Log::error('safety check answer row create failed', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
                    'exception' => $e,
                ]);
                continue;
            }
        }

        // targets for this call: re-send restricts to rows still `not_answered`
        $targets = [];
        foreach ($existingByUser as $userId => $answerRow) {
            if ($onlyUnanswered && array_get($answerRow->value, 'answer_status') !== SafetyCheckDefine::ANSWER_NOT_ANSWERED) {
                continue;
            }
            $targets[$userId] = $answerRow;
        }

        $result = ['target' => count($targets), 'line' => 0];

        $title = ($eventValue->getValue('trigger_type') === SafetyCheckDefine::TRIGGER_DRILL ? exmtrans('safety.drill_prefix') : '')
            . $eventValue->getValue('title');

        $quakeInfo = $eventValue->getValue('quake_info');
        $rowDefs = [
            ['label' => exmtrans('safety.col_triggered_at'), 'value' => $eventValue->getValue('triggered_at')],
            ['label' => exmtrans('safety.col_quake_info'), 'value' => $quakeInfo],
        ];
        $rows = [];
        foreach ($rowDefs as $rowDef) {
            if (is_nullorempty($rowDef['value'])) {
                continue;
            }
            $rows[] = $rowDef;
        }
        $rows[] = ['label' => '', 'value' => exmtrans('safety.flex_note')];

        $buttons = [
            ['label' => exmtrans('safety.status_safe'), 'data' => LineFlexBuilder::safetyPostbackData($eventValue->id, 'safe')],
            ['label' => exmtrans('safety.status_minor_injury'), 'data' => LineFlexBuilder::safetyPostbackData($eventValue->id, 'minor_injury')],
            ['label' => exmtrans('safety.status_need_help'), 'data' => LineFlexBuilder::safetyPostbackData($eventValue->id, 'need_help')],
        ];

        $bubble = LineFlexBuilder::buildBubble($title, $rows, $buttons);
        $message = LineMessagingClient::flex($title, $bubble);

        foreach ($targets as $userId => $answerRow) {
            $lineUserId = $linkedMap->get($userId);
            if (is_nullorempty($lineUserId)) {
                // no LINE link: the row stays `not_answered` with `unlinked_flg` for the admin
                continue;
            }
            // dispatch() (not dispatchAfterResponse) so a configured async queue
            // (QUEUE_CONNECTION=database/redis + worker) really queues the push and
            // LineSendJob's 429/5xx retry can work. On the default sync driver the
            // job runs inline here - the try/catch keeps one user's network failure
            // (Guzzle connect exception) from aborting the rest of the loop.
            try {
                LineSendJob::dispatch($lineUserId, [$message], [
                    'user_id' => $userId,
                    'message_type' => LineSendLogger::TYPE_FLEX,
                    'parent_id' => $eventValue->id,
                    'parent_type' => SafetyCheckDefine::TABLE_EVENT,
                    'subject' => $title,
                ]);
                $result['line']++;
            } catch (\Throwable $e) {
                Log::error('safety check LINE dispatch failed', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
                    'exception' => $e,
                ]);
            }
        }

        if ($onlyUnanswered) {
            // Do NOT overwrite sent_count on resend: $result here covers only the
            // still-unanswered subset, and clobbering it would make the admin page show
            // e.g. 送信数 1/対象 N. target_count IS refreshed to the full row count so a
            // recovery-created row (see above) is included and 回答数 can never exceed it.
            $eventUpdate = [
                'resent_at' => now()->format('Y-m-d H:i:s'),
                'target_count' => count($existingByUser),
            ];
        } else {
            // counts of the initial send: dispatched LINE jobs and the full audience
            $eventUpdate = [
                'sent_count' => $result['line'],
                'target_count' => $result['target'],
            ];
        }
        $eventValue->setValue($eventUpdate)->save();

        return $result;
    }
}
