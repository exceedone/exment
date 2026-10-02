<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

trait HasCommentMentionTrait
{
    /**
     * The comment body as it stood in the database before the save now under
     * way, or null when it has not been taken yet.
     *
     * @var string|null
     */
    protected $mention_previous_text = null;

    /**
     * Take down the comment body as it stands in the database, before this
     * save overwrites it.
     *
     * Called from the saving event, because by the time the mention notifier
     * runs Eloquent's originals have already been synced twice over - once by
     * savedValue() and once at the end of the save - and both of them hold the
     * new text. The old one only exists this early.
     *
     * A record being created has no previous body, and an empty string is the
     * right answer there: every name in it is news. A second call inside the
     * same save is savedValue() writing computed columns back, and must not
     * replace what was taken the first time.
     *
     * @return void
     */
    public function rememberPreviousCommentText()
    {
        if ($this->mention_previous_text !== null) {
            return;
        }

        $custom_table = $this->custom_table;
        if (!isset($custom_table) || !isMatchString($custom_table->table_name, SystemTableName::COMMENT)) {
            return;
        }

        $this->mention_previous_text = $this->exists ? static::commentTextOf($this->getRawOriginal('value')) : '';
    }

    /**
     * The comment body out of a stored value, however it was handed over.
     *
     * @param mixed $value the value column, as a JSON string or as an array
     * @return string
     */
    protected static function commentTextOf($value): string
    {
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        if (!is_array($value)) {
            return '';
        }

        return strip_tags(strval(array_get($value, 'comment_detail')));
    }

    /**
     * Notify everybody written as "@code" in a comment.
     *
     * Backlog's own comment box says 「＠を入力してメンバーに通知」, and that is the
     * whole point: a comment nobody is told about is a comment nobody reads. The
     * table's own notification settings only reach fixed recipients, which is the
     * wrong shape here - the person who needs to see this comment is whoever the
     * writer just named, and they are different every time.
     *
     * Matches on ユーザーコード rather than ユーザー名, because a name contains spaces
     * and cannot be read back out of free text without guessing where it ends.
     *
     * @param bool $isCreate false when an existing comment was edited, in
     *                       which case only names the previous text did not
     *                       already carry are news to anybody
     * @return int number of people notified
     */
    // @phpstan-ignore-next-line
    public function notifyMentionedUsersInComment($isCreate = true)
    {
        $custom_table = $this->custom_table;
        if (!isset($custom_table) || !isMatchString($custom_table->table_name, SystemTableName::COMMENT)) {
            return 0;
        }

        // read before any way out of this method, because reading is what
        // clears it - leaving it behind would make it the answer to the next
        // save of this same record, which is a different save entirely
        $previous = $this->takePreviousCommentText();
        if ($previous === null) {
            // nothing was taken down at the start of this save, so this is the
            // second saved event of the same save - savedValue() writing a
            // computed column back - and the names were dealt with already
            return 0;
        }

        if (is_nullorempty($this->parent_id) || is_nullorempty($this->parent_type)) {
            return 0;
        }

        $text = strip_tags(strval(array_get($this->value, 'comment_detail')));
        if (trim($text) === '') {
            return 0;
        }

        $codes = static::extractMentionCodes($text);
        if (!$isCreate) {
            // Fixing a typo in a comment must not tell everybody a second
            // time. Only a name the previous text did not carry is new
            // information, so the two texts are compared rather than the
            // current one simply re-sent.
            $codes = array_values(array_diff($codes, static::extractMentionCodes($previous)));
        }
        if (empty($codes)) {
            return 0;
        }

        $parent_table = CustomTable::getEloquent($this->parent_type);
        if (!isset($parent_table)) {
            return 0;
        }
        $parent = $parent_table->getValueModel($this->parent_id);
        if (!isset($parent)) {
            return 0;
        }

        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        if (!isset($userTable)) {
            return 0;
        }
        $codeColumn = \Exceedone\Exment\Model\CustomColumn::getEloquent('user_code', $userTable);
        if (!isset($codeColumn)) {
            return 0;
        }

        $users = $userTable->getValueModel()->newQuery()
            ->whereIn($codeColumn->getQueryKey(), $codes)
            ->get();

        $writer = \Exment::getUserId();
        $subject = exmtrans('custom_value.message.mention_notify', ['label' => $parent->label]);
        $body = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $text)), 0, 400, '…');

        $notified = 0;
        foreach ($users as $user) {
            // telling somebody they mentioned themselves is noise
            if (!is_nullorempty($writer) && intval($user->id) === intval($writer)) {
                continue;
            }

            try {
                \Exceedone\Exment\Notifications\NavbarSender::make(-1, $subject, $body, [])
                    ->custom_value($parent)
                    ->custom_table($parent_table)
                    ->user($user->id)
                    ->send();
                $notified++;
            } catch (\Throwable $ex) {
                // a failed notification must not cost the comment
                \Log::warning($ex);
            }
        }

        return $notified;
    }

    /**
     * Every "@code" written in a piece of comment text.
     *
     * @param string $text
     * @return array<int, string>
     */
    protected static function extractMentionCodes(string $text): array
    {
        // full-width ＠ too: a Japanese keyboard produces it without warning
        preg_match_all('/[@＠]([A-Za-z0-9_.\-]{1,64})/u', $text, $matches);

        return array_values(array_unique(array_filter((array)array_get($matches, 1))));
    }

    /**
     * The comment body as it stood before this save, read once.
     *
     * Reading it clears it, and null afterwards means this save has already
     * had its names dealt with. savedValue() writes computed columns back by
     * saving the record a second time, which fires the saved event again; the
     * second pass finds nothing taken down and says nothing, instead of
     * telling everybody twice.
     *
     * @return string|null null when there is nothing to say
     */
    protected function takePreviousCommentText()
    {
        $text = $this->mention_previous_text;
        $this->mention_previous_text = null;

        return $text;
    }

    /**
     * Throw away the snapshot without reading it.
     *
     * Called from the saved event so that a save which never reached the
     * notifier - disable_saved_event, or a save with nothing dirty, which
     * fires no updated event - cannot leave its snapshot behind for the next
     * save of the same instance to compare against.
     *
     * @return void
     */
    public function forgetPreviousCommentText()
    {
        $this->mention_previous_text = null;
    }
}
