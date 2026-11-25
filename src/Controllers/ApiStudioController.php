<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Services\Studio\ScreenDefinition;
use Illuminate\Http\Request;

/**
 * The screens made in the Studio, for the phone app and other clients (ISS00231 phase 3).
 *
 * GET studio/screens               the screens of the tables whose data the user may open (?table= to narrow)
 * GET studio/screens/{id}          one screen as "exment.screen" version 1 (ScreenDefinition)
 *
 * The data of a screen is read and written through the data API (data/{table}).
 */
class ApiStudioController extends AdminControllerBase
{
    use ApiTrait;

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function screens(Request $request)
    {
        $table = $request->get('table');
        return response()->json(['data' => ScreenDefinition::screens(is_string($table) && $table !== '' ? $table : null)]);
    }

    /**
     * @param string $id the suuid of the form
     * @return \Illuminate\Http\JsonResponse|\Symfony\Component\HttpFoundation\Response
     */
    public function screen(Request $request, $id)
    {
        $form = CustomForm::findBySuuid($id);
        if (!($form instanceof CustomForm) || !isset($form->custom_table)) {
            return abortJson(404, ErrorCode::DATA_NOT_FOUND());
        }
        if (!$form->custom_table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            return abortJson(403, ErrorCode::PERMISSION_DENY());
        }
        return response()->json(ScreenDefinition::of($form));
    }
}
