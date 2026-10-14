<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Illuminate\Http\Request;

/**
 * Feeds the @ picker in comment boxes.
 *
 * Kept apart from the api controllers on purpose: this answers to a browser
 * session on an admin screen, not to a token, and it deliberately returns less
 * than the user table holds - a code and a display name, nothing else. A mention
 * picker that also handed back email addresses would turn every comment box into
 * a directory export.
 */
class MentionController extends AdminControllerBase
{
    /**
     * Users a mention can point at.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    // @phpstan-ignore-next-line
    public function users(Request $request)
    {
        $custom_table = CustomTable::getEloquent(SystemTableName::USER);

        if (!isset($custom_table) || ($code = $custom_table->enableAccess()) !== true) {
            return abortJson(403, ['errors' => [exmtrans('error.permission_deny')]]);
        }

        $q = trim(strval($request->get('q')));
        $count = intval(config('exment.api_default_data_count', 20));

        // The picker opens on "@" with nothing typed yet, so an empty query has to
        // return something; searchValue insists on a term.
        if ($q === '') {
            $values = $custom_table->getValueModel()->newQuery()
                ->orderBy('id')
                ->limit($count)
                ->get();
        } else {
            $values = $custom_table->searchValue($q, [
                'maxCount' => $count,
                'makeHidden' => true,
            ]);
        }

        $results = collect($values)->map(function ($value) {
            return [
                'id' => $value->id,
                'code' => array_get($value->value, 'user_code'),
                'name' => array_get($value->value, 'user_name'),
            ];
        })->filter(function ($row) {
            // a user with no code cannot be written as @something
            return !is_nullorempty($row['code']);
        })->values();

        return response()->json(['data' => $results]);
    }
}
