<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\MeiliSavedSearch;
use Exceedone\Exment\Services\Meili\GlobalSearch\SavedSearchBar;
use Exceedone\Exment\Services\Meili\GlobalSearch\RequestFilters;
use Exceedone\Exment\Services\Meili\SavedSearchService;
use Illuminate\Http\Request;

class MeiliSavedSearchController extends AdminControllerBase
{
    /**
     * Save the current search conditions.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function store(Request $request)
    {
        $user = \Exment::user();
        if (!$user) {
            abort(403);
        }

        $name = trim(RequestFilters::str($request, 'name'));
        if ($name === '' || mb_strlen($name) > 100) {
            return response()->json(['message' => exmtrans('search.saved_name_invalid')], 422);
        }

        $shareType = RequestFilters::str($request, 'share_type', MeiliSavedSearch::SHARE_PERSONAL);
        if (!in_array($shareType, [
            MeiliSavedSearch::SHARE_PERSONAL,
            MeiliSavedSearch::SHARE_ALL,
            MeiliSavedSearch::SHARE_ROLE_GROUP,
            MeiliSavedSearch::SHARE_ORGANIZATION,
        ], true)) {
            $shareType = MeiliSavedSearch::SHARE_PERSONAL;
        }

        // with_query=0 -> save only the conditions (quick filter); applying keeps the current keyword.
        $withQuery = RequestFilters::str($request, 'with_query', '1') !== '0';

        $saved = MeiliSavedSearch::create([
            'name' => $name,
            'owner_user_id' => (int) $user->getUserId(),
            'query' => $withQuery ? RequestFilters::str($request, 'query') : '',
            'filters' => SavedSearchService::filtersFromInput($request->all()),
            'share_type' => $shareType,
            'share_targets' => self::validShareTargets($shareType, (array) $request->input('share_targets', [])),
        ]);

        return response()->json(['id' => $saved->id, 'name' => $saved->name]);
    }

    /**
     * Keep only share targets that actually exist for the share type (the same
     * lists the share modal offers). Personal/all shares carry no targets.
     *
     * @param array<int,mixed> $targets
     * @return array<int,int>
     */
    protected static function validShareTargets(string $shareType, array $targets): array
    {
        switch ($shareType) {
            case MeiliSavedSearch::SHARE_ROLE_GROUP:
                $valid = array_column(SavedSearchBar::roleGroupOptions(), 'id');
                break;
            case MeiliSavedSearch::SHARE_ORGANIZATION:
                $valid = array_column(SavedSearchBar::organizationOptions(), 'id');
                break;
            default:
                return [];
        }

        $targets = array_map('intval', array_filter($targets, 'is_scalar'));

        return array_values(array_intersect($targets, $valid));
    }

    /**
     * Apply a saved search: sanitize -> redirect to the search screen.
     *
     * @param string|int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function apply(Request $request, $id)
    {
        $saved = MeiliSavedSearch::find($id);
        if (!$saved || !MeiliSavedSearch::listForCurrentUser()->contains('id', (int) $id)) {
            abort(404);
        }

        $result = SavedSearchService::sanitize((array) ($saved->filters ?? []));

        // A saved search with a keyword -> replace the keyword too (a complete bookmark).
        $query = (string) $saved->query;
        if ($query === '') {
            $query = RequestFilters::str($request, 'q');
        }

        // ss = the applied id -> the quickbar highlights the correct chip.
        $params = ['query' => $query, 'ss' => $saved->id] + $result['params'];

        // Keep the state before applying
        $back = RequestFilters::str($request, 'back');
        if ($back !== '') {
            $params['back'] = $back;
        }

        if (!empty($result['dropped'])) {
            admin_toastr(exmtrans('search.saved_dropped'), 'warning');
        }

        return redirect(admin_url('search') . '?' . http_build_query($params));
    }

    /**
     * Delete a saved search (owner or admin).
     *
     * @param string|int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy(Request $request, $id)
    {
        $saved = MeiliSavedSearch::find($id);
        if (!$saved) {
            abort(404);
        }
        if (!$saved->canManage()) {
            abort(403);
        }

        $saved->delete();

        return response()->json(['result' => true]);
    }
}
