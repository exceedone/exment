<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\DataQrRedirect;
use Exceedone\Exment\Model\CustomForm;

class QrCodeController extends Controller
{
    /**
     * Redirect url scan from the qr code.
     *
     * @param Request $request
     * @param $table_name
     * @param $id
     */
    public function scanRedirect(Request $request, $table_name, $id)
    {
        $custom_table = CustomTable::getEloquent($table_name);
        if (!$custom_table) {
            return view('exment::qr-code.error')->render();
        }
        $form_id = (int)$custom_table->getOption('form_after_read');
        if ($form_id == 0) {
            $form_suuid = CustomForm::getDefault($custom_table)->suuid;
        } else {
            $form_suuid = CustomForm::find($form_id)->suuid;
        }
        if ($custom_table->getOption('action_after_read') === DataQrRedirect::CONTINUE_EDITING) {
            $url = admin_urls('data', $custom_table->table_name, $id, 'edit?formid=' . $form_suuid . '&after-save=1');
        } else if ($custom_table->getOption('action_after_read') === DataQrRedirect::VIEW) {
            $url = admin_urls('data', $custom_table->table_name, $id, 'edit?formid=' . $form_suuid . '&after-save=3');
        } else if ($custom_table->getOption('action_after_read') === DataQrRedirect::LIST) {
            $url = admin_urls('data', $custom_table->table_name, $id, 'edit?formid=' . $form_suuid);
        } else if ($custom_table->getOption('action_after_read') === DataQrRedirect::CAMERA) {
            $url = admin_urls('data', $custom_table->table_name, $id, 'edit?formid=' . $form_suuid . '&redirect-camera=1');
        } else {
            $url = admin_urls('data', $custom_table->table_name, $id, 'edit?formid=' . $form_suuid . '&redirect-dashboard=1');
        }

        return redirect($url);
    }
}
