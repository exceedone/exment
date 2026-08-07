<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Comment mention service.
 * Parses "@user_code" tokens in record comments, notifies mentioned users
 * (navbar and mail), and decorates rendered comment html.
 */
class MentionService
{
    /**
     * Mention token regex. "@" must not follow a word character,
     * so mail addresses in comment (foo@example.com) are not treated as mention.
     */
    public const MENTION_REGEX = '/(?<![a-zA-Z0-9_.\-])@([a-zA-Z0-9_.\-]+)/u';

    /**
     * Max mention user count per comment, to prevent notification bombing.
     */
    public const MAX_MENTION_COUNT = 10;

    /**
     * Accessible user code map cache. key = custom table id, value = [user_code => user custom value]
     *
     * @var array<int|string, \Illuminate\Support\Collection>
     */
    protected static $codeMapCache = [];


    /**
     * Parse mention user codes from comment text. Unique, capped by MAX_MENTION_COUNT.
     *
     * @param string|null $comment
     * @return array<string>
     */
    public static function parseMentionCodes($comment): array
    {
        if (is_nullorempty($comment)) {
            return [];
        }
        if (!preg_match_all(static::MENTION_REGEX, $comment, $matches)) {
            return [];
        }

        return array_slice(array_values(array_unique($matches[1])), 0, static::MAX_MENTION_COUNT);
    }


    /**
     * Resolve mentioned users of the comment.
     * Only users accessible on this table can be mentioned. The comment author is excluded.
     *
     * @param string|null $comment
     * @param CustomTable $custom_table
     * @return array ['users' => \Illuminate\Support\Collection of user custom value, 'denied' => array of user codes]
     */
    public static function resolveMentions($comment, CustomTable $custom_table): array
    {
        $result = ['users' => collect(), 'denied' => []];

        $codes = static::parseMentionCodes($comment);
        if (empty($codes)) {
            return $result;
        }

        $codeMap = static::getAccessibleUserCodeMap($custom_table);
        $loginUserId = \Exment::getUserId();

        // get all users matching codes, to distinguish "denied" (exists but not accessible) from plain text
        $matchedUsers = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->whereIn('value->user_code', $codes)
            ->get();

        foreach ($matchedUsers as $user) {
            // self mention: no notify
            if ($user->id == $loginUserId) {
                continue;
            }
            if ($codeMap->has($user->getValue('user_code'))) {
                $result['users']->push($user);
            } else {
                $result['denied'][] = $user->getValue('user_code');
            }
        }

        return $result;
    }


    /**
     * Notify mentioned users, by navbar(always) and mail(if the user has email).
     * Never throws: comment saving must not fail by notify error.
     *
     * @param \Illuminate\Support\Collection $users user custom values
     * @param CustomValue $custom_value the commented record
     * @param string $comment
     * @return void
     */
    public static function notifyMentioned($users, CustomValue $custom_value, $comment)
    {
        if (is_nullorempty($users) || count($users) == 0) {
            return;
        }

        $custom_table = $custom_value->custom_table;
        $from_user = getModelName(SystemTableName::USER)::find(\Exment::getUserId());
        $from_user_name = isset($from_user) ? $from_user->getValue('user_name') : '';

        // neutralize "${...}" replace format in comment, not to replace by NotifyService::replaceWord
        $excerpt = str_replace('${', '$ {', mb_substr($comment, 0, 200));
        if (mb_strlen($comment) > 200) {
            $excerpt .= '...';
        }

        $prms = [
            'user_name' => $from_user_name,
            'table_view_name' => $custom_table->table_view_name,
            'label' => $custom_value->label,
            'comment' => $excerpt,
            'url' => admin_urls('data', $custom_table->table_name, $custom_value->id),
        ];
        $subject = exmtrans('comment_mention.notify_subject', $prms);
        $body = exmtrans('comment_mention.notify_body', $prms);

        foreach ($users as $user) {
            // notify navbar
            try {
                NotifyService::notifyNavbar([
                    'user' => $user,
                    'custom_value' => $custom_value,
                    'subject' => $subject,
                    'body' => $body,
                ]);
            } catch (\Throwable $ex) {
                \Log::error($ex);
            }

            // notify mail
            try {
                if (!is_nullorempty($user->getValue('email'))) {
                    NotifyService::notifyMail([
                        'user' => NotifyTarget::getModelAsUser($user),
                        'custom_value' => $custom_value,
                        'subject' => $subject,
                        'body' => $body,
                    ]);
                }
            } catch (\Throwable $ex) {
                \Log::error($ex);
            }
        }
    }


    /**
     * Get mention candidate users for autocomplete.
     * Only accessible users on this table, excepting login user. Max 10.
     *
     * @param CustomTable $custom_table
     * @param string|null $q search keyword for user_code and user_name
     * @return array<array<string, mixed>>
     */
    public static function getMentionCandidates(CustomTable $custom_table, $q): array
    {
        $codeMap = static::getAccessibleUserCodeMap($custom_table);
        $loginUserId = \Exment::getUserId();
        $q = mb_strtolower($q ?? '');

        return $codeMap->filter(function ($user) use ($q, $loginUserId) {
            if ($user->id == $loginUserId) {
                return false;
            }
            if ($q === '') {
                return true;
            }
            $code = mb_strtolower($user->getValue('user_code') ?? '');
            $name = mb_strtolower($user->getValue('user_name') ?? '');
            return mb_strpos($code, $q) !== false || mb_strpos($name, $q) !== false;
        })->take(static::MAX_MENTION_COUNT)->map(function ($user) {
            return [
                'id' => $user->id,
                'user_code' => $user->getValue('user_code'),
                'user_name' => $user->getValue('user_name'),
            ];
        })->values()->toArray();
    }


    /**
     * Decorate mention tokens in rendered(escaped) comment html.
     * Call AFTER escaping(replaceBreakEsc): replaces "@user_code" with a mention chip.
     * Unknown codes are kept as plain text.
     *
     * @param string|null $html escaped comment html
     * @param string|null $table_name the commented record's table name
     * @return string
     */
    public static function replaceMentionHtml($html, $table_name)
    {
        if (is_nullorempty($html)) {
            return $html ?? '';
        }
        $custom_table = CustomTable::getEloquent($table_name);
        if (!isset($custom_table)) {
            return $html;
        }

        $codeMap = static::getAccessibleUserCodeMap($custom_table);
        $loginUserId = \Exment::getUserId();

        return preg_replace_callback(static::MENTION_REGEX, function ($matches) use ($codeMap, $loginUserId) {
            $user = $codeMap->get($matches[1]);
            if (!isset($user)) {
                return $matches[0];
            }
            $class = 'comment-mention' . ($user->id == $loginUserId ? ' comment-mention-me' : '');
            $name = $user->getValue('user_name');
            if (is_nullorempty($name)) {
                $name = $matches[1];
            }
            return '<span class="' . $class . '" title="@' . e($matches[1]) . '">@' . e($name) . '</span>';
        }, $html);
    }


    /**
     * Get accessible users of the table, as map keyed by user_code. Cached per request.
     *
     * @param CustomTable $custom_table
     * @return \Illuminate\Support\Collection [user_code => user custom value]
     */
    protected static function getAccessibleUserCodeMap(CustomTable $custom_table)
    {
        if (array_key_exists($custom_table->id, static::$codeMapCache)) {
            return static::$codeMapCache[$custom_table->id];
        }

        $map = collect();
        foreach ($custom_table->getAccessibleUsers() as $user) {
            $code = $user->getValue('user_code');
            if (!is_nullorempty($code)) {
                $map->put($code, $user);
            }
        }

        return static::$codeMapCache[$custom_table->id] = $map;
    }
}
