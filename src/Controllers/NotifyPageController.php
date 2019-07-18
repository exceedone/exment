<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Illuminate\Http\Request;
use Exceedone\Exment\Form\Show;
use Exceedone\Exment\Grid\Tools\BatchCheck;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyPage;
use DB;

class NotifyPageController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans('notify_page.header'), exmtrans('notify_page.header'), exmtrans('notify_page.description'), 'fa-bell');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new NotifyPage);
        $grid->column('read_flg', exmtrans('notify_page.read_flg'))->sortable()->display(function ($read_flg) {
            return exmtrans("notify_page.read_flg_options.$read_flg");
        });
        $grid->column('parent_type', exmtrans('notify_page.parent_type'))->sortable()->display(function ($parent_type) {
            return CustomTable::getEloquent($parent_type)->table_view_name;
        });
        $grid->column('notify_subject', exmtrans('notify_page.notify_subject'))->sortable();
        $grid->column('created_at', exmtrans('common.created_at'))->sortable();
       
        $grid->disableCreation();
        $grid->disableExport();

        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $batch) {
                $batch->add(exmtrans('notify_page.all_check'), new BatchCheck());
            });
        });

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
            
            // reference target data
            $linker = (new Linker)
                ->url(admin_url("notify_page/rowdetail/{$actions->row->id}"))
                ->icon('fa-list')
                ->tooltip(exmtrans('notify_page.data_refer'));
            $actions->prepend($linker);
        });
        return $grid;
    }


    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return new Form(new NotifyPage);
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = NotifyPage::findOrFail($id);

        if (!isset($model)) {
            abort(404);
        }

        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        } 

        return new Show($model, function (Show $show) use($model) {
            $show->field('id', exmtrans('common.id'));
            $show->field('target_custom_value', exmtrans('notify_page.target_custom_value'))->as(function($v) use($model){
                return CustomTable::getEloquent(array_get($model, 'parent_type'))
                    ->getValueModel(array_get($model, 'parent_id'))
                    ->getValue(true);
            })->setEscape(false);
            $show->field('notify_subject', exmtrans('notify_page.notify_subject'));
            $show->field('notify_body', exmtrans('notify_page.notify_body'))
                ->as(function ($v) {
                    return  replaceBreak($v);
                })->setEscape(false);

            $show->panel()->tools(function ($tools) {
                $tools->disableEdit();
            });
        });
    }
    
    /**
     * redirect custom values's detail page
     *
     * @param mixed   $id
     */
    public function redirectTargetData(Request $request, $id = null)
    {
        $model = NotifyPage::findOrFail($id);

        if (!isset($model)) {
            abort(404);
        }

        // update read_flg
        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        } 

        $custom_value = getModelName($model->parent_type)::find($model->parent_id);

        // redirect custom value page
        return redirect($custom_value->getUrl());
    }

    /**
     * update read_flg when row checked
     * 
     * @param mixed   $id
     */
    public function rowCheck(Request $request, $id = null)
    {
        if (!isset($id)) {
            abort(404);
        }

        $models = NotifyPage::whereIn('id', explode(',', $id))->where('read_flg', false)->get();
        if (!isset($models) || $models->count() == 0) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('notify_page.message.check_notfound'),
            ]);
        }

        foreach ($models as $model) {
            $model->update(['read_flg' => true]);
        }
        
        return getAjaxResponse([
            'result'  => true,
            'toastr' => exmtrans('notify_page.message.check_succeeded'),
        ]);
    }

}
