<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;

/**
 * Who may use the API (ISS02915): the users, organizations (with the organizations below them) and
 * role groups chosen in the API setting, and the system administrators. Nothing chosen (the
 * default): every user, as before. The same for the OAuth access tokens, the API keys, MCP and the
 * API of the plugins; the web API of the pages (webapi) is the pages, not the API.
 */
class ApiUsers
{
    /**
     * @return array{users: array<int, int>, organizations: array<int, int>, role_groups: array<int, int>}
     */
    public static function settings(): array
    {
        return [
            'users' => static::ids(System::api_allowed_users()),
            'organizations' => static::ids(System::api_allowed_organizations()),
            'role_groups' => static::ids(System::api_allowed_role_groups()),
        ];
    }

    /**
     * @param mixed $value a setting of the type array
     * @return array<int, int>
     */
    public static function ids($value): array
    {
        if (is_string($value)) {
            $value = explode(',', $value);
        }
        return array_values(array_unique(array_filter(array_map(function ($id) {
            return is_numeric($id) ? intval($id) : 0;
        }, (array) ($value ?? [])))));
    }

    /**
     * Whether only the chosen users may use the API.
     */
    public static function limited(): bool
    {
        foreach (static::settings() as $ids) {
            if (count($ids) > 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a login user may use the API.
     *
     * @param mixed $user a login user
     * @param bool|null $systemAdmin whether the user has the permission of the system (null: asked
     *                               of the user, the login user of the request)
     */
    public static function allows($user, ?bool $systemAdmin = null): bool
    {
        if (!static::limited()) {
            return true;
        }
        if (!($user instanceof LoginUser)) {
            return false;
        }
        $settings = static::settings();
        $userId = intval($user->getUserId());
        if (in_array($userId, $settings['users'], true)) {
            return true;
        }
        if ($systemAdmin ?? (in_array(strval($userId), array_map('strval', (array) (System::system_admin_users() ?? [])), true) || $user->hasPermission(Permission::SYSTEM))) {
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
