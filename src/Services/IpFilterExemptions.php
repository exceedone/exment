<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Model\System;

/**
 * The users the IP filter of the web pages does not refuse (ISS02915): chosen as users,
 * organizations (with the organizations below them) or role groups. Only the web pages: the API,
 * the API keys and MCP keep their own filters. Without a choice (the default) nothing changes.
 *
 * From an address out of the filter, while there are exemptions, the login page is shown, and a
 * user who is not exempt is logged out at once; a user who is exempt is recorded once a session.
 */
class IpFilterExemptions
{
    /**
     * @return array{users: array<int, int>, organizations: array<int, int>, role_groups: array<int, int>}
     */
    public static function settings(): array
    {
        $ids = function ($value): array {
            if (is_string($value)) {
                $value = explode(',', $value);
            }
            return array_values(array_unique(array_filter(array_map(function ($id) {
                return is_numeric($id) ? intval($id) : 0;
            }, (array) ($value ?? [])))));
        };
        return [
            'users' => $ids(System::web_ip_filter_exempt_users()),
            'organizations' => $ids(System::web_ip_filter_exempt_organizations()),
            'role_groups' => $ids(System::web_ip_filter_exempt_role_groups()),
        ];
    }

    public static function configured(): bool
    {
        foreach (static::settings() as $ids) {
            if (count($ids) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a login user may use the web pages from an address out of the filter.
     *
     * @param mixed $user a login user
     */
    public static function isExempt($user): bool
    {
        if (!is_object($user) || !method_exists($user, 'getUserId')) {
            return false;
        }
        $settings = static::settings();
        $userId = intval($user->getUserId());
        if (in_array($userId, $settings['users'], true)) {
            return true;
        }
        // an organization: its users and the users of the organizations below it
        if (count($settings['organizations']) > 0 && boolval(System::organization_available())) {
            $orgs = array_map(function ($id) {
                return intval($id);
            }, AuthUserOrgHelper::getOrganizationIdsForQuery(JoinedOrgFilterType::ONLY_DOWNER, $userId));
            if (count(array_intersect($orgs, $settings['organizations'])) > 0) {
                return true;
            }
        }
        if (count($settings['role_groups']) > 0) {
            return count(array_intersect(Features::roleGroupIds($user), $settings['role_groups'])) > 0;
        }
        return false;
    }
}
