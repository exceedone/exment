<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;

trait HasCommentMentionTrait
{
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
     * @return int number of people notified
     */
    // @phpstan-ignore-next-line
    public function notifyMentionedUsersInComment()
    {
        $custom_table = $this->custom_table;
        if (!isset($custom_table) || !isMatchString($custom_table->table_name, SystemTableName::COMMENT)) {
            return 0;
        }

        if (is_nullorempty($this->parent_id) || is_nullorempty($this->parent_type)) {
            return 0;
        }

        $text = strip_tags(strval(array_get($this->value, 'comment_detail')));
        if (trim($text) === '') {
            return 0;
        }

        // full-width ＠ too: a Japanese keyboard produces it without warning
        preg_match_all('/[@＠]([A-Za-z0-9_.\-]{1,64})/u', $text, $matches);
        $codes = array_values(array_unique(array_filter((array)array_get($matches, 1))));
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
}
