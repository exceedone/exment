<?php

namespace Exceedone\Exment\Services\Meili\GlobalSearch;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\MeiliSavedSearch;
use Exceedone\Exment\Model\RoleGroup;
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
            if ($back === null) {
                $qs = $request->query();
                unset($qs['ss'], $qs['back']);
                $back = http_build_query($qs);
            }

            // q = the current keyword: a saved search WITHOUT a keyword (quick filter)
            // applies its filters on top of this keyword.
            $fallback = '?q=' . urlencode((string) $request->input('query', ''))
                . '&back=' . urlencode((string) $back);

            // Clicking the active chip again -> return to the state before applying.
            $toggleUrl = admin_url('search') . '?' . ((string) $back !== ''
                ? (string) $back
                : 'query=' . urlencode((string) $request->input('query', '')));

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
     * List of role groups to choose from when sharing a saved search.
     *
     * @return array<int,array{id:int,name:string}>
     */
    public static function roleGroupOptions(): array
    {
        try {
            return RoleGroup::orderBy('role_group_order')->get()
                ->map(fn ($r) => [
                    'id' => (int) $r->id,
                    'name' => (string) ($r->role_group_view_name ?: $r->role_group_name),
                ])->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * List of organizations to choose from when sharing a saved search. Wrapped
     * in try/catch: if organizations are not enabled, return empty (UI hides it).
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
            return getModelName($table)::query()->get()
                ->map(fn ($o) => ['id' => (int) $o->id, 'name' => (string) $o->label])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
