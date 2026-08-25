<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Services\Line\LineActingUser;
use Illuminate\Support\Facades\Log;

/**
 * Handles safety-check answer postbacks from LINE (act=safety): records the tapped
 * status (safe / minor_injury / need_help) onto the matching safety_check_answer row.
 * Runs under LineActingUser::runAs, so the custom-value save carries the same
 * authority as a logged-in action.
 */
class SafetyCheckAction
{
    /**
     * Executes the safety-check answer from a postback. Returns the reply message for LINE.
     */
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
            // The two safety_check_* tables get no role-group permission from the installer,
            // so CustomValueModelScope would force `id < 0` (record_not_found) for every
            // regular user. Identity is already proven (signed webhook -> LineAccountLink ->
            // this user) and findAnswerRow only ever touches the user's own (event, user)
            // row, so these internal lookups bypass the permission scope.
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

    /**
     * Attaches a free-text LINE message as a timestamped comment on the sender's answer row
     * for the current open safety_check_event (most recent first). Used for follow-up details
     * ("足を怪我しました" etc.) sent as plain text rather than through the Flex buttons.
     *
     * A message is accepted as a comment only while the user's "comment window" is open:
     * they must have pressed an answer button (answer_status != not_answered) and their
     * answer row's updated_at must be within safety_check_comment_window_minutes (each
     * button press or attached comment saves the row, which refreshes updated_at and thereby
     * extends the window). Outside the window, plain text falls back to the caller's default
     * reply so a lingering open event doesn't swallow every message the user sends.
     *
     * Returns true only if a comment was actually attached; false leaves the caller free to
     * fall back to its own default reply (e.g. line.invalid_command).
     */
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

        // Resolve both tables up front: this runs for EVERY plain-text message from every
        // linked user, so a deploy-before-migrate window (tables not yet installed) must
        // degrade gracefully to the caller's default reply, not fatal the whole webhook.
        $eventTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_EVENT);
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$eventTable || !$answerTable) {
            return false;
        }

        try {
            return LineActingUser::runAs($loginUser, function () use ($eventTable, $answerTable, $userId, $text) {
                // Most recent open event first: a user who is party to more than one open event
                // (unlikely, but not impossible) has their comment attached to the newest one.
                // withoutGlobalScope: see handle() — regular users have no permission on the
                // safety tables, and this only reads event ids to locate the user's own row.
                $indexStatus = CustomColumn::getEloquent('event_status', $eventTable)->getIndexColumnName();
                $eventIds = $eventTable->getValueModel()
                    ->withoutGlobalScope(CustomValueModelScope::class)
                    ->where($indexStatus, SafetyCheckDefine::EVENT_OPEN)
                    ->orderBy('id', 'desc')
                    ->pluck('id');

                // transaction + lockForUpdate (in findAnswerRow): the comment append is a
                // read-modify-write of the whole value JSON — serialize with button taps
                // and other comments on the same row.
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
            // A comment-attach failure must degrade to the caller's default reply
            // (line.invalid_command), never break the shared webhook text path.
            Log::warning('safety comment attach failed', ['exception' => $e]);
            return false;
        }
    }

    /**
     * Looks up the safety_check_answer row for ($eventId, $userId), via the generated
     * index columns (event/user are index_enabled) — same querying convention as
     * LineSendLogger. Values on SelectTable-backed columns are persisted as strings
     * (see SelectTable::saving()), hence the string casts. Pass `$lock = false` for a
     * read-only lookup outside a transaction (see currentAnswer).
     */
    private static function findAnswerRow($answerTable, $eventId, $userId, bool $lock = true)
    {
        $indexEvent = CustomColumn::getEloquent('event', $answerTable)->getIndexColumnName();
        $indexUser  = CustomColumn::getEloquent('user', $answerTable)->getIndexColumnName();

        // withoutGlobalScope: see handle() — the caller has already pinned $userId to the
        // LINE-verified user, so this can only ever return that user's own row.
        // lockForUpdate: the locked callers run inside a transaction and rewrite the whole
        // value JSON, so the row lock serializes concurrent writers; currentAnswer reads
        // without a lock.
        $query = $answerTable->getValueModel()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->where($indexEvent, (string) $eventId)
            ->where($indexUser, (string) $userId);
        if ($lock) {
            $query->lockForUpdate();
        }
        return $query->first();
    }

    /**
     * Records an answer onto the (event, user) row — the single write path shared
     * by the LINE postback (channel 'line') and the mail-fallback web page
     * (channel 'mail'). Status validity and event-open checks are the CALLER's
     * job; this only performs the locked read-modify-write. A comment given here
     * is appended in the SAME save as the status (one lock cycle, not two).
     * Returns false when the row does not exist.
     *
     * SECURITY: findAnswerRow bypasses CustomValueModelScope, so this can read/write
     * ANY user's row. The caller MUST pin $userId to a verified identity — an
     * authenticated user, or an id proven by a cryptographic check such as the
     * signed answer URL — never a client-supplied value taken at face value.
     */
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

    /**
     * The user's answer row for an event, WITHOUT locking — read-only display
     * (e.g. preselecting the web answer form). Null when missing.
     *
     * SECURITY: findAnswerRow bypasses CustomValueModelScope, so this can read
     * ANY user's row. The caller MUST pin $userId to a verified identity — an
     * authenticated user, or an id proven by a cryptographic check such as the
     * signed answer URL — never a client-supplied value taken at face value.
     */
    public static function currentAnswer($eventId, int $userId)
    {
        $answerTable = CustomTable::getEloquent(SafetyCheckDefine::TABLE_ANSWER);
        if (!$answerTable) {
            return null;
        }
        return static::findAnswerRow($answerTable, $eventId, $userId, false);
    }

    /**
     * The comment window for an answer row is open while the user has pressed an answer
     * button (answer_status != not_answered) AND the row was last saved within
     * safety_check_comment_window_minutes. Button presses and attached comments both save
     * the row (refreshing updated_at), so each interaction extends the window.
     */
    private static function isCommentWindowOpen($answerRow): bool
    {
        if ($answerRow->getValue('answer_status') === SafetyCheckDefine::ANSWER_NOT_ANSWERED) {
            return false;
        }
        // intSetting: an emptied admin-UI field is stored as 0, which would keep the
        // window permanently closed — fall back to the Define default instead.
        $window = SafetyCheckDefine::intSetting('safety_check_comment_window_minutes');
        return $answerRow->updated_at !== null
            && $answerRow->updated_at->gte(now()->subMinutes($window));
    }
}
