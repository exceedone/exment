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
            // without the versions kept to go back to (a listing of the disk: S3 on a SaaS)
            $rows = BundledPlugins::rows(false);
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
     * The features the site has not decided on yet, that came with an update.
     * Not told of:
     * - the features a new site had when it was installed ("_known")
     * - the features of a plugin installed after the site got Feature
     *   management ("_since"): the administrator installed it, and its
     *   features are on the same page
     *
     * @return string[] the names of the features
     */
    public static function newFeatures(): array
    {
        return array_values(array_map(function ($key) {
            return Features::label($key);
        }, static::newFeatureKeys()));
    }

    /**
     * The keys of newFeatures().
     *
     * @return string[]
     */
    public static function newFeatureKeys(): array
    {
        $settings = Features::settings();
        if (!isset($settings[Features::SINCE])) {
            return [];
        }
        $known = is_array($settings[Features::KNOWN] ?? null) ? array_map('strval', $settings[Features::KNOWN]) : [];
        try {
            $since = \Carbon\Carbon::parse(strval($settings[Features::SINCE]));
        } catch (\Throwable $e) {
            $since = null;
        }
        $names = [];
        foreach (Features::definitions() as $key => $definition) {
            $key = strval($key);
            // a feature that is on from the start (as Tuning: "default") is not new to tell of
            if (!empty($definition['default']) || in_array($key, $known, true)) {
                continue;
            }
            if (!Features::isNew($key) || Features::locked($key)) {
                continue;
            }
            // already on (a feature a plugin had before, "existing"): nothing to decide,
            // and the notice would say it starts off
            if (Features::setting($key)['enabled']) {
                continue;
            }
            $plugin = Features::pluginOf($key);
            if (isset($plugin, $plugin->created_at, $since) && !\Carbon\Carbon::parse(strval($plugin->created_at))->lt($since)) {
                continue;
            }
            $names[] = $key;
        }
        return $names;
    }

    /**
     * The new features told of are known now: the administrator closed the notice, or
     * opened Feature management. They are not told of again.
     *
     * @param string[] $keys
     */
    public static function markKnown(array $keys): void
    {
        if (count($keys) === 0) {
            return;
        }
        $settings = Features::settings();
        $known = is_array($settings[Features::KNOWN] ?? null) ? array_map('strval', $settings[Features::KNOWN]) : [];
        $merged = array_values(array_unique(array_merge($known, array_map('strval', $keys))));
        if ($merged === $known) {
            return;
        }
        $settings[Features::KNOWN] = $merged;
        System::features($settings);
        System::clearCache();
    }

    /**
     * The keys of what is to be told now.
     *
     * @return array<string, array{subject: string, body: string, mail: bool}>
     */
    public static function pending(): array
    {
        $notices = [];
        foreach (static::security() as $item) {
            $text = strval(exmtrans('plugin_distribution.notice_security', ['name' => $item['label'], 'version' => $item['version']]));
            $notices[$item['key']] = [
                // a short subject (the bell and the mail), the sentence in the body
                'subject' => strval(exmtrans('plugin_distribution.notice_security_subject', ['name' => $item['label']])),
                'body' => $text . ($item['note'] !== '' ? "\n\n" . $item['note'] : '') . "\n\n" . admin_url('feature'),
                // a security fix is also mailed
                'mail' => true,
            ];
        }
        $features = static::newFeatures();
        if (count($features) > 0) {
            sort($features);
            $text = strval(exmtrans('plugin_distribution.notice_features', ['names' => implode(', ', $features)]));
            $notices['features:' . md5(implode("\n", $features))] = [
                'subject' => strval(exmtrans('plugin_distribution.notice_features_subject')),
                'body' => $text . "\n\n" . admin_url('feature'),
                // the bell and the home only: a mail for each new feature would be too many
                'mail' => false,
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
                if (!$notice['mail']) {
                    continue;
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
