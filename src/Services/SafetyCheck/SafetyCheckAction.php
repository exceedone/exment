<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\Line\LineActingUser;
use Illuminate\Support\Facades\Log;

class SafetyCheckAction
{
    public static function handle(array $data, ?string $lineUserId): string
    {
        if (empty($lineUserId)) {
            return exmtrans('line.user_unidentified');
        }
        $eventId = array_get($data, 'event');
        $status  = array_get($data, 'st');
        if (is_nullorempty($eventId) || !in_array($status, SafetyCheckDefine::ANSWER_STATUSES, true)) {
            return exmtrans('line.invalid_action_data');
        }

        $userId = LineActingUser::userId($lineUserId);
        if ($userId === null) {
            return exmtrans('line.account_not_linked');
        }

        $loginUser = LineActingUser::loginUser($userId);
        if (!$loginUser) {
            return exmtrans('line.login_not_activated');
        }
        return LineActingUser::runAs($loginUser, function () use ($eventId, $status, $userId) {
            $eventValue = getModelName(SafetyCheckDefine::TABLE_EVENT)::withoutGlobalScope(CustomValueModelScope::class)
                ->find($eventId);
            if (!$eventValue) {
                return exmtrans('line.record_not_found');
            }
            if ($eventValue->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED) {
                return exmtrans('safety.answer_closed');
            }

            if (!static::recordAnswer($eventId, (int) $userId, $status, 'line')) {
                return exmtrans('line.record_not_found');
            }
            return exmtrans('safety.answer_done', ['status' => exmtrans('safety.status_' . $status)]);
        });
    }

    public static function attachComment(?string $lineUserId, string $text): bool
    {
        $userId = LineActingUser::userId($lineUserId);
        if ($userId === null) {
            return false;
        }

        $loginUser = LineActingUser::loginUser($userId);
        if (!$loginUser) {
            return false;
        }

        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$eventTable || !$answerTable) {
            return false;
        }

        try {
            return LineActingUser::runAs($loginUser, function () use ($eventTable, $answerTable, $userId, $text) {
                $indexStatus = CustomColumn::getEloquent('event_status', $eventTable)->getIndexColumnName();
                $eventIds = $eventTable->getValueModel()
                    ->withoutGlobalScope(CustomValueModelScope::class)
                    ->where($indexStatus, SafetyCheckDefine::EVENT_OPEN)
                    ->orderBy('id', 'desc')
                    ->pluck('id');

                return \DB::transaction(function () use ($answerTable, $eventIds, $userId, $text) {
                    $answerRow = null;
                    foreach ($eventIds as $eventId) {
                        $row = static::findAnswerRow($answerTable, $eventId, $userId);
                        if ($row && static::isCommentWindowOpen($row)) {
                            $answerRow = $row;
                            break;
                        }
                    }
                    if (!$answerRow) {
                        return false;
                    }

                    $old = (string) $answerRow->getValue('comment');
                    $new = ($old === '' ? '' : $old . "\n") . '[' . now()->format('m/d H:i') . '] ' . $text;
                    $answerRow->setValue(['comment' => $new])->save();

                    return true;
                });
            });
        } catch (\Throwable $e) {
            Log::warning('safety comment attach failed', ['exception' => $e]);
            return false;
        }
    }

    private static function findAnswerRow($answerTable, $eventId, $userId, bool $lock = true)
    {
        $indexEvent = CustomColumn::getEloquent('event', $answerTable)->getIndexColumnName();
        $indexUser  = CustomColumn::getEloquent('user', $answerTable)->getIndexColumnName();

        $query = $answerTable->getValueModel()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->where($indexEvent, (string) $eventId)
            ->where($indexUser, (string) $userId);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    public static function recordAnswer($eventId, int $userId, string $status, string $channel, ?string $comment = null): bool
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            return false;
        }

        return \DB::transaction(function () use ($answerTable, $eventId, $userId, $status, $channel, $comment) {
            $answerRow = static::findAnswerRow($answerTable, $eventId, $userId);
            if (!$answerRow) {
                return false;
            }

            $value = [
                'answer_status' => $status,
                'answered_at'   => now()->format('Y-m-d H:i:s'),
                'channel'       => $channel,
            ];
            if (!is_nullorempty($comment)) {
                $old = (string) $answerRow->getValue('comment');
                $value['comment'] = ($old === '' ? '' : $old . "\n") . '[' . now()->format('m/d H:i') . '] ' . $comment;
            }
            $answerRow->setValue($value)->save();

            return true;
        });
    }

    public static function currentAnswer($eventId, int $userId)
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            return null;
        }
        return static::findAnswerRow($answerTable, $eventId, $userId, false);
    }

    private static function isCommentWindowOpen($answerRow): bool
    {
        if ($answerRow->getValue('answer_status') === SafetyCheckDefine::ANSWER_NOT_ANSWERED) {
            return false;
        }
        $window = SafetyCheckDefine::intSetting('safety_check_comment_window_minutes');
        return $answerRow->updated_at !== null
            && $answerRow->updated_at->gte(now()->subMinutes($window));
    }
}
