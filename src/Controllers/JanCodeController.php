<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\DataScanSubmitRedirect;
use Exceedone\Exment\Model\CustomForm;
use Illuminate\Support\Facades\DB;
use Encore\Admin\Layout\Content;
use Encore\Admin\Layout\Row;
use Encore\Admin\Grid;
use Encore\Admin\Widgets\Grid\Column;

class JanCodeController extends Controller
{
    /**
     * Redirect url scan from the jan code.
     *
     * @param Request $request
     * @param $id
     * @return string
     */
    protected function scanRedirect(Request $request, $id)
    {
        $jan_code = DB::table("jan_codes")
            ->where('jan_code', $id)
            ->whereNotNull('table_id')
            ->first();
        $url = '';
        if ($jan_code) {
            $table_id = $jan_code->table_id;
            $target_id = $jan_code->target_id;
            if ($table_id) {
                $custom_table = CustomTable::getEloquent($table_id);
                if (!$custom_table) {
                    return view('exment::jan-code.error')->render();
                }
                $table_name = $custom_table->table_name;
                if ($target_id) {
                    $form_id = (int)$custom_table->getOption('form_after_read_jan_code');
                    if ($form_id == 0) {
                        $form_suuid = CustomForm::getDefault($custom_table)->suuid;
                    } else {
                        $form_suuid = CustomForm::find($form_id)->suuid;
                    }
                    if ($custom_table->getOption('action_after_read_jan_code') === DataScanSubmitRedirect::CONTINUE_EDITING) {
                        $url = admin_urls('data', $table_name, $target_id, 'edit?formid=' . $form_suuid . '&after-save=1');
                    } else if ($custom_table->getOption('action_after_read_jan_code') === DataScanSubmitRedirect::VIEW) {
                        $url = admin_urls('data', $table_name, $target_id, 'edit?formid=' . $form_suuid . '&after-save=3');
                    } else if ($custom_table->getOption('action_after_read_jan_code') === DataScanSubmitRedirect::LIST) {
                        $url = admin_urls('data', $table_name, $target_id, 'edit?formid=' . $form_suuid);
                    } else if ($custom_table->getOption('action_after_read_jan_code') === DataScanSubmitRedirect::CAMERA) {
                        $url = admin_urls('data', $table_name, $target_id, 'edit?formid=' . $form_suuid . '&redirect-camera=1');
                    } else {
                        $url = admin_urls('data', $table_name, $target_id, 'edit?formid=' . $form_suuid . '&redirect-dashboard=1');
                    }
                }
            }
        } else {
            $table_active_jan_codes = DB::table('custom_tables')
                ->where('options->active_jan_flg', true)
                ->get();
            if (!$table_active_jan_codes->isEmpty()) {
                if (count($table_active_jan_codes) === 1) {
                    $table_id = $table_active_jan_codes[0]->id;
                    $url = $this->generateCreateUrl($id, $table_id);
                } else {
                    $url = admin_urls('jan-code', 'table', $id);
                }
            } else {
                $url = admin_urls();
            }
        }

        return redirect($url);
    }

    /**
     * List table to choose for jancode.
     *
     * @param Request $request
     * @param $id
     */
    protected function listTable(Request $request, $id, Content $content)
    {
        $row = new Row($this->grid($id));
        $content->header(exmtrans("custom_table.jan_code.header"))
            ->headericon('fa-pencil')
            ->description(exmtrans("custom_table.jan_code.description"));

        return $content->row($row);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid($id)
    {
        $grid = new Grid(new CustomTable());
        $grid->column('table_name', exmtrans("custom_table.table_name"))->sortable();
        $grid->column('table_view_name', exmtrans("custom_table.table_view_name"))->sortable();
        $grid->disableExport();
        $grid->disableCreateButton();
        $grid->disableActions();
        $grid->disableRowSelector();
        $table_active_jan_codes = DB::table('custom_tables')
                ->where('options->active_jan_flg', true)
                ->pluck('id')
                ->toArray();
        $grid->model()->whereIn('id', $table_active_jan_codes);

        $grid->rows(function ($row) use ($id) {
            $row->setAttributes([
                'class' => 'janCodeRow',
                'jan-code-id' => $id,
                'id' => $row->model()['id'],
            ]);
        });

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('table_name', exmtrans("custom_table.table_name"));
            $filter->like('table_view_name', exmtrans("custom_table.table_view_name"));
        });

        return $grid;
    }

    /**
     * Assign jancode for table.
     *
     * @param Request $request
     */
    protected function assignJancode(Request $request)
    {
        $table_id = $request->get('table_id');
        $jan_code_id = $request->get('jan_code_id');
        if ($table_id && $jan_code_id) {
            $url = $this->generateCreateUrl($jan_code_id, $table_id);
        } else {
            $url = admin_urls();
        }
        
        return redirect($url);
    }

    /**
     * Generate create record url from jancode.
     *
     * @param $id
     * @param $table_id
     */
    protected function generateCreateUrl($id, $table_id)
    {
        $custom_table = CustomTable::getEloquent($table_id);
        $table_name = $custom_table->table_name;
        $form_id = (int)$custom_table->getOption('form_after_create_jan_code');
        if ($form_id == 0) {
            $form_suuid = CustomForm::getDefault($custom_table)->suuid;
        } else {
            $form_suuid = CustomForm::find($form_id)->suuid;
        }
        if ($custom_table->getOption('action_after_create_jan_code') === DataScanSubmitRedirect::CONTINUE_EDITING) {
            $url = admin_urls('data', $table_name, 'create?formid=' . $form_suuid . '&after-save=1&jan_code=' . $id);
        } else if ($custom_table->getOption('action_after_create_jan_code') === DataScanSubmitRedirect::VIEW) {
            $url = admin_urls('data', $table_name, 'create?formid=' . $form_suuid . '&after-save=3&jan_code=' . $id);
        } else if ($custom_table->getOption('action_after_create_jan_code') === DataScanSubmitRedirect::LIST) {
            $url = admin_urls('data', $table_name, 'create?formid=' . $form_suuid . '&jan_code=' . $id);
        } else if ($custom_table->getOption('action_after_create_jan_code') === DataScanSubmitRedirect::CAMERA) {
            $url = admin_urls('data', $table_name, 'create?formid=' . $form_suuid . '&redirect-camera=1&jan_code=' . $id);
        } else {
            $url = admin_urls('data', $table_name, 'create?formid=' . $form_suuid . '&redirect-dashboard=1&jan_code=' . $id);
        }
       
        return $url;
    }
}
