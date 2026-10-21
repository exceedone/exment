<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\Line\LineSendLogger;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class SafetyCheckSender
{
    /**
     * @param CustomValue $eventValue
     * @param bool $onlyUnanswered
     * @param SafetyCheckSendLock|null $lock the caller's lock of this event, kept alive while sending
     * @return array{target:int,line:int,mail:int}
     */
    public static function send($eventValue, bool $onlyUnanswered = false, ?SafetyCheckSendLock $lock = null): array
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            Log::error('safety check answer table is not installed; nothing sent', [
                'event_id' => $eventValue->id,
            ]);
            return ['target' => 0, 'line' => 0, 'mail' => 0];
        }

        $existingByUser = [];
        foreach ($answerTable->getValueQuery()->where('value->event', $eventValue->id)->get() as $row) {
            $existingByUser[(int) array_get($row->value, 'user')] = $row;
        }

        $userIds = getModelName(SystemTableName::USER)::query()->pluck('id')->all();

        $linkedMap = LineAccountLink::whereIn('user_id', $userIds)
            ->whereNotNull('line_user_id')
            ->pluck('line_user_id', 'user_id');

        foreach ($userIds as $userId) {
            if ($lock) {
                $lock->keep();
            }
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
                Log::error('safety check answer row create failed', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
                    'exception' => $e,
                ]);
                continue;
            }
        }

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

        $mailUsers = collect();
        if (!empty($targets)) {
            $mailUsers = CustomTable::getEloquent(SystemTableName::USER)->getValueQuery()
                ->whereIn('id', array_keys($targets))->get()->keyBy('id');
        }

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
            if ($lock) {
                $lock->keep();
            }
            $sentAny = false;

            $lineUserId = $linkedMap->get($userId);
            if (!is_nullorempty($lineUserId)) {
                try {
                    LineSendJob::dispatch($lineUserId, [$message], [
                        'user_id' => $userId,
                        'message_type' => LineSendLogger::TYPE_FLEX,
                        'parent_id' => $eventValue->id,
                        'parent_type' => SafetyCheckDefine::TABLE_EVENT,
                        'subject' => $title,
                    ], true);
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

            try {
                /** @var \Exceedone\Exment\Model\CustomValue|null $mailUser */
                $mailUser = $mailUsers->get($userId);
                $mailSender = static::buildMailSender($mailUser, $eventValue, $title, $mailBody);
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
                Log::warning('safety check user unreachable: no LINE link and no email', [
                    'user_id' => $userId,
                    'event_id' => $eventValue->id,
                ]);
            }
        }

        if ($onlyUnanswered) {
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
        // the messages are out already: failing to count them must not fail the send
        try {
            static::updateEvent($eventValue, $eventUpdate);
        } catch (\Throwable $e) {
            Log::error('safety check event counts not saved', [
                'event_id' => $eventValue->id,
                'values' => $eventUpdate,
                'exception' => $e,
            ]);
        }

        return $result;
    }

    /**
     * Sets values on the event as it is in the database now, not on the copy the caller read
     * earlier: saving that copy would undo what changed meanwhile (the counts of a send that
     * just finished, a resent_at a 再送 reserved, a 締切).
     *
     * @param CustomValue $eventValue refreshed afterwards
     * @param array<string, mixed> $values
     */
    public static function updateEvent($eventValue, array $values): void
    {
        $saved = \DB::transaction(function () use ($eventValue, $values) {
            $current = $eventValue->newQueryWithoutScopes()->whereKey($eventValue->getKey())->lockForUpdate()->first();
            if (!$current) {
                return false;
            }
            $current->setValue($values)->save();
            return true;
        });
        if ($saved) {
            $eventValue->refresh();
        }
    }

    /**
     * The signed link of the mail answer page. Closing the event (締切) is what ends answering;
     * the expiry keeps a forgotten open event from leaving its links usable for ever.
     * A 再送 mails unanswered users a new link.
     *
     * @param int|string $eventId
     * @param int|string $userId
     */
    public static function answerUrl($eventId, $userId): string
    {
        $parameters = ['event' => $eventId, 'user' => $userId];
        $expireDays = (int) System::safety_check_answer_url_expire_days();
        if ($expireDays <= 0) {
            return URL::signedRoute('exment.safety_answer', $parameters);
        }
        return URL::temporarySignedRoute('exment.safety_answer', now()->addDays($expireDays), $parameters);
    }

    /**
     * @param \Exceedone\Exment\Model\CustomValue|null $userValue
     * @param \Exceedone\Exment\Model\CustomValue $eventValue
     */
    public static function buildMailSender($userValue, $eventValue, string $title, string $body): ?MailSender
    {
        if (!$userValue || is_nullorempty($userValue->getValue('email'))) {
            return null;
        }

        $answerUrl = static::answerUrl($eventValue->id, $userValue->id);

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
