<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliSavedSearch;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Illuminate\Http\Request;

/**
 * Render the saved-search quickbar (mockup style): pill chips above the results,
 * the applied chip (param ss) highlighted, plus the save modal's share targets.
 */
class SavedSearchBar
{
    /**
     * Wrapped in try/catch so it does not break before migration.
     */
    public static function render(Request $request): string
    {
        try {
            $activeId = (int) $request->input('ss', 0);

            // back = the state BEFORE clicking a chip (the raw query string, minus ss/back).
            // If back is already in the URL (a chip is applied), keep it -> clicking
            // another chip then toggling still returns to the correct original state.
            $back = $request->input('back');
            if (!is_scalar($back)) {
                $qs = $request->query();
                unset($qs['ss'], $qs['back']);
                $back = http_build_query($qs);
            }

            // q = the current keyword: a saved search WITHOUT a keyword (quick filter)
            // applies its filters on top of this keyword.
            $fallback = '?q=' . urlencode(RequestFilters::str($request, 'query'))
                . '&back=' . urlencode((string) $back);

            // Clicking the active chip again -> return to the state before applying.
            $toggleUrl = admin_url('search') . '?' . ((string) $back !== ''
                ? (string) $back
                : 'query=' . urlencode(RequestFilters::str($request, 'query')));

            $savedSearches = MeiliSavedSearch::listForCurrentUser()->map(fn ($r) => [
                'id' => $r->id,
                'name' => $r->name,
                'query' => (string) $r->query,
                'can_manage' => $r->canManage(),
                'apply_url' => (int) $r->id === $activeId
                    ? $toggleUrl
                    : admin_urls('search', 'saved', $r->id, 'apply') . $fallback,
                'active' => (int) $r->id === $activeId,
            ])->all();

            return view('exment::search.saved-quickbar', [
                'savedSearches' => $savedSearches,
                'canShareAll' => self::canShareAll(),
                'roleGroups' => self::roleGroupOptions(),
                'organizations' => self::organizationOptions(),
            ])->render();
        } catch (\Throwable $e) {
            // Pre-migration (table missing) this is expected; still log so real
            // bugs are not silently swallowed.
            \Illuminate\Support\Facades\Log::warning('[Meili] saved-search bar skipped: ' . $e->getMessage());
            return '';
        }
    }

    /**
     * Publishing to every user (and to any group or organization) is reserved to
     * the system permission, like a system view.
     */
    public static function canShareAll(): bool
    {
        try {
            $user = \Exment::user();
            return $user !== null && $user->hasPermission(Permission::SYSTEM);
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * List of role groups to choose from when sharing a saved search: the user's
     * own groups only, unless they hold the system permission.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function roleGroupOptions(): array
    {
        try {
            $query = RoleGroup::orderBy('role_group_order');
            if (!self::canShareAll()) {
                $base = \Exment::user()?->base_user;
                $query->whereIn('id', $base ? $base->belong_role_groups_all()->pluck('id')->all() : []);
            }
            return $query->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => (string) ($r->role_group_view_name ?: $r->role_group_name),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * List of organizations to choose from when sharing a saved search: the user's
     * own organizations (same hierarchy rule as the share itself), unless they hold
     * the system permission. Empty when organizations are not enabled (UI hides it).
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function organizationOptions(): array
    {
        try {
            $table = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
            if (!$table) {
                return [];
            }
            $query = getModelName($table)::query();
            if (!self::canShareAll()) {
                $base = \Exment::user()?->base_user;
                $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                $query->whereIn('id', $base ? (array) $base->getOrganizationIdsForQuery($enum) : []);
            }
            return $query->get()
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->label])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
