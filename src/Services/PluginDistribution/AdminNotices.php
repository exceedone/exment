<?php

namespace Exceedone\Exment\Services\PluginDistribution;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;

/**
 * What the system administrators are told (and only they: the other users
 * see nothing of it, on no page):
 *
 * - a bundled official plugin has a version with a fix for the safety, and
 *   the version installed is older than the first safe one (the release
 *   record, signed: security_min; ISS00421 "5")
 * - the site has features it has not decided on yet (after an update)
 *
 * They are shown at the top of the home of the system administrators, and
 * told once (the same notice is not told twice) by the alerts of Exment (the
 * bell) and, when the site sends mails, by a mail. Nothing is installed by
 * it: an administrator chooses it in Feature management.
 */
class AdminNotices
{
    /** The notices already told (a system setting: a list of keys). */
    public const SENT = 'admin_notices_sent';

    /**
     * The versions with a fix for the safety that the site should take.
     *
     * @return array<int, array{key: string, name: string, label: string, version: string, installed: string, note: string}>
     */
    public static function security(): array
    {
        $list = [];
        try {
            $rows = BundledPlugins::rows();
        } catch (\Throwable $e) {
            return [];
        }
        foreach ($rows as $row) {
            if (empty($row['security'])) {
                continue;
            }
            $list[] = [
                'key' => 'security:' . $row['uuid'] . ':' . $row['version'],
                'name' => strval($row['name']),
                'label' => strval($row['label']),
                'version' => strval($row['version']),
                'installed' => strval($row['installed_version']),
                'note' => strval($row['security_note'] ?? ''),
            ];
        }
        return $list;
    }

    /**
     * The features the site has not decided on yet, after an update (a new
     * site has decided nothing: it is not told of them).
     *
     * @return string[] the names of the features
     */
    public static function newFeatures(): array
    {
        $settings = Features::settings();
        if (!isset($settings[Features::SINCE])) {
            return [];
        }
        $names = [];
        foreach (array_keys(Features::definitions()) as $key) {
            $key = strval($key);
            if (Features::isNew($key) && !Features::locked($key)) {
                $names[] = Features::label($key);
            }
        }
        return $names;
    }

    /**
     * The keys of what is to be told now.
     *
     * @return array<string, array{subject: string, body: string}>
     */
    public static function pending(): array
    {
        $notices = [];
        foreach (static::security() as $item) {
            $text = strval(exmtrans('plugin_distribution.notice_security', ['name' => $item['label'], 'version' => $item['version']]));
            $notices[$item['key']] = [
                'subject' => $text,
                'body' => $text . ($item['note'] !== '' ? "\n\n" . $item['note'] : '') . "\n\n" . admin_url('feature'),
            ];
        }
        $features = static::newFeatures();
        if (count($features) > 0) {
            sort($features);
            $text = strval(exmtrans('plugin_distribution.notice_features', ['names' => implode(', ', $features)]));
            $notices['features:' . md5(implode("\n", $features))] = [
                'subject' => strval(exmtrans('plugin_distribution.notice_features_subject')),
                'body' => $text . "\n\n" . admin_url('feature'),
            ];
        }
        $sent = static::sent();
        return array_filter($notices, function ($key) use ($sent) {
            return !in_array($key, $sent, true);
        }, ARRAY_FILTER_USE_KEY);
    }

    /**
     * @return string[]
     */
    protected static function sent(): array
    {
        $value = System::admin_notices_sent();
        $value = json_decode(strval(json_encode($value ?? [])), true);
        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    /**
     * The system administrators (System::system_admin_users).
     *
     * @return array<int, mixed> the users (custom values)
     */
    public static function administrators(): array
    {
        $users = [];
        foreach ((array)System::system_admin_users() as $id) {
            $user = getModelName(SystemTableName::USER)::find($id);
            if (isset($user)) {
                $users[] = $user;
            }
        }
        return $users;
    }

    /**
     * Tell what is to be told, once (by the schedule of Exment).
     *
     * @return int the notices told
     */
    public static function deliver(): int
    {
        $pending = static::pending();
        if (count($pending) === 0) {
            return 0;
        }
        $users = static::administrators();
        foreach ($pending as $notice) {
            foreach ($users as $user) {
                try {
                    $navbar = new NotifyNavbar();
                    $navbar->fill([
                        'notify_id' => 0,
                        'target_user_id' => $user->id,
                        'notify_subject' => mb_substr($notice['subject'], 0, 200),
                        'notify_body' => mb_substr($notice['body'], 0, 2000),
                        'trigger_user_id' => null,
                    ]);
                    $navbar->save();
                } catch (\Throwable $e) {
                    \Log::warning('[Exment] the notice to the administrators: ' . $e->getMessage());
                }
                try {
                    if (!is_null(\Exceedone\Exment\Services\NotifyService::getAddress($user))) {
                        \Exceedone\Exment\Services\NotifyService::notifyMail([
                            'to' => $user,
                            'subject' => $notice['subject'],
                            'body' => $notice['body'],
                        ]);
                    }
                } catch (\Throwable $e) {
                    \Log::warning('[Exment] the mail of the notice to the administrators: ' . $e->getMessage());
                }
            }
        }
        System::admin_notices_sent(array_values(array_unique(array_merge(static::sent(), array_keys($pending)))));
        return count($pending);
    }
}
