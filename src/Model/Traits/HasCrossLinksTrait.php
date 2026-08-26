<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CrossItemLink;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

trait HasCrossLinksTrait
{
    // @phpstan-ignore-next-line
    public function crossLinksFrom($relationType = null)
    {
        $links = CrossItemLink::getLinksFrom($this->getMorphTypeName(), $this->id);

        if (isset($relationType)) {
            return $links->where('relation_type', $relationType)->values();
        }

        return $links;
    }

    // @phpstan-ignore-next-line
    public function crossLinksTo($relationType = null)
    {
        $links = CrossItemLink::getLinksTo($this->getMorphTypeName(), $this->id);

        if (isset($relationType)) {
            return $links->where('relation_type', $relationType)->values();
        }

        return $links;
    }

    // @phpstan-ignore-next-line
    public function linkTo($target, $relationType, $meta = [])
    {
        if (is_object($target)) {
            $targetType = method_exists($target, 'getMorphTypeName') ? $target->getMorphTypeName() : $this->getTargetMorphTypeName($target);
            $targetId = method_exists($target, 'getKey') ? $target->getKey() : $target->id;
        } else {
            $targetType = $this->getMorphTypeName();
            $targetId = $target;
        }

        return CrossItemLink::linkRecords(
            $this->getMorphTypeName(),
            $this->id,
            $targetType,
            $targetId,
            $relationType,
            $meta
        );
    }

    // @phpstan-ignore-next-line
    public function getMorphTypeName()
    {
        if ($this instanceof CustomValue) {
            $tableName = $this->table_name ?? $this->custom_table_name ?? array_get($this->custom_table, 'table_name');

            if (!is_nullorempty($tableName)) {
                return "custom_value_{$tableName}";
            }
        }

        return class_basename(static::class);
    }

    // @phpstan-ignore-next-line
    protected function getTargetMorphTypeName($target)
    {
        if ($target instanceof CustomValue) {
            $tableName = $target->table_name ?? $target->custom_table_name ?? array_get($target->custom_table, 'table_name');

            if (!is_nullorempty($tableName)) {
                return "custom_value_{$tableName}";
            }
        }

        return class_basename($target);
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

    /**
     * Which column holds the key a comment would name a record by.
     *
     * The table's 見出し列 setting, because that is already the answer to "what do
     * people call this record" - it is what every select box and every link label
     * shows. Falling back to the first column would be wrong here: column order is
     * whatever the table happened to be built in, and on a table extended after the
     * fact the first column is often something like 種別.
     *
     * @param CustomTable $custom_table
     * @return \Exceedone\Exment\Model\CustomColumn|null
     */
    // @phpstan-ignore-next-line
    protected static function getMentionKeyColumn($custom_table)
    {
        $labels = $custom_table->getLabelColumns();

        if ($labels instanceof \Illuminate\Support\Collection) {
            $first = $labels->first();
            if (isset($first)) {
                $column = \Exceedone\Exment\Model\CustomColumn::getEloquent(array_get($first->options, 'table_label_id'));
                if (isset($column)) {
                    return $column;
                }
            }
        }

        // no 見出し列 set: the best remaining guess is the first searchable text
        // column, which is where an identifier normally lives
        return $custom_table->custom_columns_cache
            ->filter(function ($custom_column) {
                return in_array($custom_column->column_type, [ColumnType::TEXT, ColumnType::AUTO_NUMBER])
                    && boolval($custom_column->index_enabled);
            })
            ->first();
    }

    /**
     * A comment that names another record links the two.
     *
     * Backlog says so on its own issue form: "この課題のコメントで言及された課題も、
     * 自動で「関連課題」として追加されます". Somebody writing "same cause as PORTAL-12"
     * has already done the thinking; making them open the issue again and fill in
     * 関連課題 by hand is the part that gets skipped, which is how a backlog ends up
     * full of duplicates nobody knew were duplicates.
     *
     * Runs on the comment record, links the record the comment is attached to.
     *
     * @return int number of links written
     */
    // @phpstan-ignore-next-line
    public function linkRecordsMentionedInComment()
    {
        $custom_table = $this->custom_table;
        if (!isset($custom_table) || !isMatchString($custom_table->table_name, SystemTableName::COMMENT)) {
            return 0;
        }

        if (is_nullorempty($this->parent_id) || is_nullorempty($this->parent_type)) {
            return 0;
        }

        $parent_table = CustomTable::getEloquent($this->parent_type);
        if (!isset($parent_table)) {
            return 0;
        }

        $relationType = $parent_table->getOption('comment_link_relation');
        if (is_nullorempty($relationType)) {
            return 0;
        }

        $text = strip_tags(strval(array_get($this->value, 'comment_detail')));
        if (trim($text) === '') {
            return 0;
        }

        $parent = $parent_table->getValueModel($this->parent_id);
        if (!isset($parent)) {
            return 0;
        }

        $labelColumn = static::getMentionKeyColumn($parent_table);
        $morph = "custom_value_{$parent_table->table_name}";

        // "#123" is the record id, "PORTAL-12" is whatever the table shows as its
        // label. Both forms appear in real comments, so both are read.
        preg_match_all('/#(\d+)|([A-Za-z][A-Za-z0-9_]{0,15}-\d+)/u', $text, $matches, PREG_SET_ORDER);

        $written = 0;
        $seen = [];

        foreach ($matches as $match) {
            $target = null;

            if (!is_nullorempty(array_get($match, 1))) {
                $target = $parent_table->getValueModel(intval($match[1]));
            } elseif (!is_nullorempty(array_get($match, 2)) && isset($labelColumn)) {
                $target = $parent_table->getValueModel()->newQuery()
                    ->where($labelColumn->getQueryKey(), $match[2])
                    ->first();
            }

            if (!isset($target) || intval($target->id) === intval($parent->id)) {
                continue;
            }
            if (in_array($target->id, $seen)) {
                continue;
            }
            $seen[] = $target->id;

            CrossItemLink::linkRecords($morph, $parent->id, $morph, $target->id, $relationType, [
                'source_column' => 'comment',
                'comment_id' => $this->id,
            ]);
            $written++;
        }

        return $written;
    }

    /**
     * Mirror select-table columns marked with "cross_link_relation" into cross_item_links.
     *
     * A select-table column records the pick one way only: fill 関連課題 on issue A
     * and issue B still shows nothing. cross_item_links is read from both ends
     * (DefaultShow walks crossLinksFrom and crossLinksTo), so copying the column
     * into it is what makes the other record show the link back.
     *
     * Writing into cross_item_links rather than into the other record's column is
     * also what keeps this from looping: cross_item_links is a plain table, not a
     * custom value, so no second save event fires.
     *
     * @return int number of links written
     */
    // @phpstan-ignore-next-line
    public function syncCrossLinksFromColumns()
    {
        $custom_table = $this->custom_table;
        if (!isset($custom_table) || is_nullorempty($this->id)) {
            return 0;
        }

        $written = 0;
        $fromType = $this->getMorphTypeName();

        foreach ($custom_table->custom_columns_cache as $custom_column) {
            $relationType = array_get($custom_column->options, 'cross_link_relation');
            if (is_nullorempty($relationType)) {
                continue;
            }
            if (!isMatchString($custom_column->column_type, ColumnType::SELECT_TABLE)) {
                continue;
            }

            $target_table = $custom_column->select_target_table;
            if (!isset($target_table)) {
                continue;
            }

            $columnName = $custom_column->column_name;
            $toType = "custom_value_{$target_table->table_name}";

            $ids = collect(array_wrap(array_get($this->value, $columnName)))
                ->filter(function ($id) {
                    return !is_nullorempty($id);
                })
                ->map(function ($id) {
                    return intval($id);
                })
                // a self-referencing column can point at the record itself; a link
                // from a record to itself would render as its own related issue
                ->reject(function ($id) use ($toType, $fromType) {
                    return $toType === $fromType && $id === intval($this->id);
                })
                ->unique()
                ->values();

            // Drop what this column wrote last time, so clearing the column clears
            // the links. Scoped by source_column, otherwise this would also delete
            // links written by the migration importer or the webhook.
            CrossItemLink::where('from_type', $fromType)
                ->where('from_id', $this->id)
                ->where('to_type', $toType)
                ->where('relation_type', $relationType)
                ->get()
                ->filter(function ($link) use ($columnName) {
                    return array_get($link->meta_json, 'source_column') === $columnName;
                })
                ->each(function ($link) use ($ids) {
                    if (!$ids->contains(intval($link->to_id))) {
                        $link->delete();
                    }
                });

            foreach ($ids as $id) {
                CrossItemLink::linkRecords($fromType, $this->id, $toType, $id, $relationType, [
                    'source_column' => $columnName,
                ]);
                $written++;
            }
        }

        return $written;
    }
}
