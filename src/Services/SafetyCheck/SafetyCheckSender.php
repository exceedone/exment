<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SafetyCheckSender
{
    /**
     * @param CustomValue $eventValue CustomValue of `safety_check_event`.
     * @param bool $onlyUnanswered If true (re-send): only (re-)send to users whose row
     *                             is `not_answered`; rows missing after an earlier
     *                             failed create are still created (recovery).
     * @return array{target:int,line:int,mail:int}
     */
    public static function send($eventValue, bool $onlyUnanswered = false): array
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            // Half-installed environment (see SafetyWatchCommand::handle, which only
            // checks the event table): fail loudly in the log instead of a fatal.
            Log::error('safety check answer table is not installed; nothing sent', [
                'event_id' => $eventValue->id,
            ]);
            return ['target' => 0, 'line' => 0, 'mail' => 0];
        }

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

        $result = ['target' => count($targets), 'line' => 0, 'mail' => 0];

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

        // every target with an email gets the mail (LINE-linked included), so the
        // user rows (email column) are needed for the whole target list
        $mailUsers = collect();
        if (!empty($targets)) {
            $mailUsers = CustomTable::getEloquent(SystemTableName::USER)->getValueQuery()
                ->whereIn('id', array_keys($targets))->get()->keyBy('id');
        }

        // mail body = the same info lines the Flex bubble shows (minus the
        // LINE-only comment note, label '')
        $bodyLines = [];
        foreach ($rows as $rowDef) {
            if ($rowDef['label'] === '') {
                continue;
            }
            $bodyLines[] = $rowDef['label'] . ': ' . $rowDef['value'];
        }
        $mailBody = implode("\n", $bodyLines);

        $notified = 0;
        foreach ($targets as $userId => $answerRow) {
            $sentAny = false;

            $lineUserId = $linkedMap->get($userId);
            if (!is_nullorempty($lineUserId)) {
                // dispatch() (not dispatchAfterResponse) so a configured async queue
                // (QUEUE_CONNECTION=database/redis + worker) really queues the push and
                // LineSendJob's 429/5xx retry can work. On the default sync driver the
                // job runs inline here - the try/catch keeps one user's network failure
                // (Guzzle connect exception) or API rejection (LineSendFailedException,
                // e.g. expired token) from aborting the rest of the loop, and such a
                // user is NOT counted as reached over LINE. On an async queue the
                // outcome is not known yet: 'line' counts dispatched pushes there.
                try {
                    LineSendJob::dispatch($lineUserId, [$message], [
                        'user_id' => $userId,
                        'message_type' => LineSendLogger::TYPE_FLEX,
                        'parent_id' => $eventValue->id,
                        'parent_type' => SafetyCheckDefine::TABLE_EVENT,
                        'subject' => $title,
                    ], true); // throwOnFailure: caught per user right below
                    $result['line']++;
                    $sentAny = true;
                } catch (\Throwable $e) {
                    Log::error('safety check LINE dispatch failed', [
                        'user_id' => $userId,
                        'event_id' => $eventValue->id,
                        'exception' => $e,
                    ]);
                }
            }

            // mail carries the signed web-answer URL; null = user has no email
            try {
                $mailSender = static::buildMailSender($mailUsers->get($userId), $eventValue, $title, $mailBody);
                if ($mailSender) {
                    $mailSender->send();
                    $result['mail']++;
                    $sentAny = true;
                }
            } catch (\Throwable $e) {
                Log::error('safety check mail send failed', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
                    'exception' => $e,
                ]);
            }

            if ($sentAny) {
                $notified++;
            } elseif (is_nullorempty($lineUserId)) {
                // neither LINE nor email: unreachable — the admin still sees the
                // gap via unlinked_flg on the row
                Log::warning('safety check user unreachable: no LINE link and no email', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
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
            $eventUpdate = [
                'sent_count' => $notified,
                'target_count' => $result['target'],
            ];
        }
        $eventValue->setValue($eventUpdate)->save();

        return $result;
    }

    public static function buildMailSender($userValue, $eventValue, string $title, string $body): ?MailSender
    {
        if (!$userValue || is_nullorempty($userValue->getValue('email'))) {
            return null;
        }

        $answerUrl = URL::signedRoute('exment.safety_answer', [
            'event' => $eventValue->id,
            'user'  => $userValue->id,
        ]);

        return MailSender::make(MailKeyName::SAFETY_CHECK_MAIL, $userValue)
            ->user($userValue)
            ->custom_value($eventValue)
            ->finalUser(!is_null(\Exment::getUserId()))
            ->prms([
                'safety_title' => $title,
                'safety_body'  => $body,
                'answer_url'   => $answerUrl,
            ]);
    }
}
