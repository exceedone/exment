<?php

namespace Exceedone\Exment\Services\SafetyCheck;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\Define;
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
     * While sending, look at the event this often: a 締切 stops the send, and a first send
     * writes how far it got.
     */
    public const CHECK_EVERY_SECONDS = 30;

    /**
     * Upper bound of safety_check_answer_url_expire_days, also enforced by its form field.
     */
    public const ANSWER_URL_MAX_EXPIRE_DAYS = 3650;

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

        // e.g. a 再送 that was let through just before a 締切
        if (static::isClosed($eventValue)) {
            Log::info('safety check not sent: the event is closed', ['event_id' => $eventValue->id]);
            return ['target' => 0, 'line' => 0, 'mail' => 0];
        }
        $checkedAt = now()->getTimestamp();
        $closed = false;

        $existingByUser = [];
        foreach ($answerTable->getValueQuery()->where('value->event', $eventValue->id)->get() as $row) {
            $existingByUser[(int) array_get($row->value, 'user')] = $row;
        }

        // who is asked: the users of the 送信対象 settings (everyone when they are empty)
        $userIds = static::targetUserIds();
        $isTarget = array_fill_keys($userIds, true);

        $linkedMap = LineAccountLink::whereIn('user_id', $userIds)
            ->whereNotNull('line_user_id')
            ->pluck('line_user_id', 'user_id');

        foreach ($userIds as $userId) {
            if ($lock) {
                $lock->keep();
            }
            // a 締切 meanwhile stops the send: nobody else is asked about a closed event
            if (static::isDue($checkedAt) && static::isClosed($eventValue)) {
                $closed = true;
                break;
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
            // e.g. a user excluded after the first send: the row stays, nothing more is sent
            if (!isset($isTarget[$userId])) {
                continue;
            }
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
        if (!$closed && !$onlyUnanswered) {
            static::writeProgress($eventValue, ['sent_count' => 0, 'target_count' => $result['target']]);
        }
        foreach ($targets as $userId => $answerRow) {
            if ($lock) {
                $lock->keep();
            }
            if (!$closed && static::isDue($checkedAt)) {
                $closed = static::isClosed($eventValue);
                if (!$closed && !$onlyUnanswered) {
                    static::writeProgress($eventValue, ['sent_count' => $notified]);
                }
            }
            if ($closed) {
                break;
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

        if ($closed) {
            Log::info('safety check send stopped: the event was closed meanwhile', [
                'event_id' => $eventValue->id,
                'notified' => $notified,
                'target' => $result['target'],
            ]);
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
     * The ids of the users a safety check is sent to, by the 送信対象 settings:
     * all users, of the organizations (and their child organizations) when set,
     * shown by the view when set, without the excluded users.
     * The manual send and the earthquake auto send both use it.
     *
     * @return array<int, int>
     */
    public static function targetUserIds(): array
    {
        $dbTable = getDBTableName(SystemTableName::USER);
        $query = getModelName(SystemTableName::USER)::query();

        $orgIds = static::targetOrganizationIds();
        if (!is_null($orgIds)) {
            $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
            $query->whereIn("$dbTable.id", function ($sub) use ($pivot, $orgIds) {
                $sub->select('child_id')->from($pivot)->whereIn('parent_id', $orgIds);
            });
        }

        $view = static::targetView();
        if ($view) {
            $view->filterModel($query);
        }

        $excluded = static::excludedUserIds();
        if (count($excluded) > 0) {
            $query->whereNotIn("$dbTable.id", $excluded);
        }

        $ids = [];
        foreach ($query->pluck("$dbTable.id")->all() as $id) {
            $ids[(int) $id] = (int) $id;
        }
        return array_values($ids);
    }

    /**
     * The ids of the target organizations with their child organizations, or null
     * when every user is a target: nothing set, organizations not used, or none of
     * the set organizations left (sending to more people is safer than to nobody).
     *
     * @return array<int, int>|null
     */
    public static function targetOrganizationIds(): ?array
    {
        $ids = static::settingIds(System::safety_check_target_organizations());
        if (count($ids) === 0 || !System::organization_available()) {
            return null;
        }

        $result = [];
        foreach (getModelName(SystemTableName::ORGANIZATION)::query()->whereIn('id', $ids)->get() as $organization) {
            // the organization and its children ("upper" gives the children)
            /** @var mixed $organization the organization model (OrganizationTrait) */
            $organizationIds = $organization->getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_UPPER);
            foreach ((array) $organizationIds as $id) {
                $result[(int) $id] = (int) $id;
            }
        }
        if (count($result) === 0) {
            Log::warning('safety check target organizations not found; sending to every user', ['organization_ids' => $ids]);
            return null;
        }
        return array_values($result);
    }

    /**
     * The view of the user table whose users are the targets, or null when not set
     * (or the view is gone: then the view does not narrow the targets).
     */
    public static function targetView(): ?CustomView
    {
        // an empty select is saved as 0
        $viewId = intval(System::safety_check_target_view());
        if ($viewId <= 0) {
            return null;
        }
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        /** @var CustomView|null $view */
        $view = CustomView::query()->where('id', $viewId)->first();
        if (!$view || !$userTable || (int) $view->custom_table_id !== (int) $userTable->id || $view->view_kind_type == ViewKindType::AGGREGATE) {
            Log::warning('safety check target view not found; not filtering by a view', ['view_id' => $viewId]);
            return null;
        }
        return $view;
    }

    /**
     * The views that can be chosen as 送信対象のビュー: the shared views of the user table
     * that list users (not the aggregate ones).
     *
     * @return array<int|string, string> id => name
     */
    public static function targetViewOptions(): array
    {
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        if (!$userTable) {
            return [];
        }
        $options = [];
        foreach (CustomView::query()
            ->where('custom_table_id', $userTable->id)
            ->where('view_type', ViewType::SYSTEM)
            ->where('view_kind_type', '<>', ViewKindType::AGGREGATE)
            ->orderBy('id')
            ->get() as $view) {
            /** @var CustomView $view */
            $options[$view->id] = strval($view->view_view_name);
        }
        return $options;
    }

    /**
     * @return array<int, int>
     */
    public static function excludedUserIds(): array
    {
        return static::settingIds(System::safety_check_excluded_users());
    }

    /**
     * For the 新規送信 form: how many users are asked, and by which settings.
     *
     * @return array{count:int, settings:string}
     */
    public static function targetSummary(): array
    {
        $settings = [];
        $orgIds = static::settingIds(System::safety_check_target_organizations());
        if (count($orgIds) > 0 && System::organization_available()) {
            $settings[] = exmtrans('safety.target_by_organizations', ['count' => count($orgIds)]);
        }
        $view = static::targetView();
        if ($view) {
            $settings[] = exmtrans('safety.target_by_view', ['name' => $view->view_view_name]);
        }
        $excluded = static::excludedUserIds();
        if (count($excluded) > 0) {
            $settings[] = exmtrans('safety.target_excluded', ['count' => count($excluded)]);
        }
        return [
            'count' => count(static::targetUserIds()),
            'settings' => count($settings) > 0 ? implode(exmtrans('common.separate_word'), $settings) : exmtrans('safety.target_all_users'),
        ];
    }

    /**
     * @param mixed $value the value of an array setting
     * @return array<int, int>
     */
    protected static function settingIds($value): array
    {
        $values = is_array($value) ? $value : stringToArray($value);
        $ids = [];
        foreach ($values as $id) {
            if (is_numeric($id)) {
                $ids[(int) $id] = (int) $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Whether CHECK_EVERY_SECONDS have passed since $checkedAt; if so, restarts the wait.
     *
     * @phpstan-impure reads the clock
     */
    protected static function isDue(int &$checkedAt): bool
    {
        $now = now()->getTimestamp();
        if ($now - $checkedAt < static::CHECK_EVERY_SECONDS) {
            return false;
        }
        $checkedAt = $now;
        return true;
    }

    /**
     * Read from the database, as a 締切 may come after the caller read the event.
     * When the read fails the send goes on: it matters more.
     *
     * @phpstan-impure the answer changes once somebody closes the event
     * @param CustomValue $eventValue
     */
    protected static function isClosed($eventValue): bool
    {
        try {
            $current = $eventValue->newQueryWithoutScopes()->whereKey($eventValue->getKey())->first();
        } catch (\Throwable $e) {
            Log::warning('safety check event status unreadable, sending on', ['event_id' => $eventValue->id, 'exception' => $e]);
            return false;
        }
        return !$current || $current->trashed() || $current->getValue('event_status') === SafetyCheckDefine::EVENT_CLOSED;
    }

    /**
     * How far a first send got, written while it runs: a count that stops moving tells a send that
     * died from one still going. A plain JSON update, not a model save, so no revision or
     * notification for every step; the final counts are saved properly by updateEvent().
     *
     * @param CustomValue $eventValue
     * @param array<string, int> $values
     */
    protected static function writeProgress($eventValue, array $values): void
    {
        $update = [];
        foreach ($values as $key => $value) {
            $update['value->' . $key] = $value;
        }
        try {
            \DB::table(getDBTableName($eventValue->custom_table))->where('id', $eventValue->id)->update($update);
        } catch (\Throwable $e) {
            Log::warning('safety check send progress not saved', ['event_id' => $eventValue->id, 'exception' => $e]);
        }
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
        $expireDays = System::safety_check_answer_url_expire_days();
        // a field left empty is saved as null: that is not a choice of "no expiry"
        $expireDays = is_null($expireDays) ? (int) array_get(Define::SYSTEM_SETTING_NAME_VALUE, 'safety_check_answer_url_expire_days.default') : (int) $expireDays;
        // a huge value would overflow the timestamp and expire every link at once
        $expireDays = min($expireDays, static::ANSWER_URL_MAX_EXPIRE_DAYS);
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
