<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Illuminate\Http\Request;
use Exceedone\Exment\Form\Show;
use Exceedone\Exment\Grid\Tools\BatchCheck;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar;

class NotifyNavbarController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans('notify_navbar.header'), exmtrans('notify_navbar.header'), exmtrans('notify_navbar.description'), 'fa-bell');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new NotifyNavbar);
        $grid->column('read_flg', exmtrans('notify_navbar.read_flg'))->sortable()->displayEscape(function ($read_flg) {
            return exmtrans("notify_navbar.read_flg_options.$read_flg");
        });
        $grid->column('parent_type', exmtrans('notify_navbar.parent_type'))->sortable()->displayEscape(function ($parent_type) {
            if (is_null($parent_type)) {
                return null;
            }
            return CustomTable::getEloquent($parent_type)->table_view_name;
        });
        $grid->column('notify_subject', exmtrans('notify_navbar.notify_subject'))->sortable();
        $grid->column('created_at', exmtrans('common.created_at'))->sortable();
       
        $grid->disableCreation();
        $grid->disableExport();

        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $batch) {
                $batch->add(exmtrans('notify_navbar.all_check'), new BatchCheck());
            });
        });

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
            
            if (isset($actions->row->parent_id)) {
                // reference target data
                $linker = (new Linker)
                    ->url(admin_url("notify_navbar/rowdetail/{$actions->row->id}"))
                    ->icon('fa-list')
                    ->tooltip(exmtrans('notify_navbar.data_refer'));
                $actions->prepend($linker);
            }
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
        return new Form(new NotifyNavbar);
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = NotifyNavbar::findOrFail($id);

        if (!isset($model)) {
            abort(404);
        }

        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        }

        $custom_value = null;
        if (!is_null($parent_type = array_get($model, 'parent_type'))) {
            $custom_value = CustomTable::getEloquent($parent_type)
                ->getValueModel(array_get($model, 'parent_id'));
        }

        return new Show($model, function (Show $show) use ($id, $model, $custom_value) {
            $show->field('parent_type', exmtrans('notify_navbar.parent_type'))->as(function ($parent_type) {
                if (is_null($parent_type)) {
                    return null;
                }
                return CustomTable::getEloquent($parent_type)->table_view_name;
            });

            if (isset($custom_value)) {
                $show->field('target_custom_value', exmtrans('notify_navbar.target_custom_value'))->as(function ($v) use ($model, $custom_value) {
                    return $custom_value->getLabel();
                })->setEscape(false);
            }
            $show->field('notify_subject', exmtrans('notify_navbar.notify_subject'));
            $show->field('notify_body', exmtrans('notify_navbar.notify_body'))
                ->as(function ($v) {
                    return  replaceBreak($v, false);
                })->setEscape(false);

            $show->panel()->tools(function ($tools) use ($id, $custom_value) {
                $tools->disableEdit();
                
                if ($custom_value) {
                    $tools->append(view('exment::tools.button', [
                        'href' => admin_url("notify_navbar/rowdetail/{$id}"),
                        'label' => exmtrans('notify_navbar.data_refer'),
                        'icon' => 'fa-list',
                        'btn_class' => 'btn-purple',
                    ]));
                }
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
        $model = NotifyNavbar::findOrFail($id);

        if (!isset($model)) {
            abort(404);
        }

        // update read_flg
        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        }

        $custom_value = getModelName($model->parent_type)::find($model->parent_id);

        if (!isset($custom_value)) {
            return back();
        }

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

        $models = NotifyNavbar::whereIn('id', explode(',', $id))->where('read_flg', false)->get();
        if (!isset($models) || $models->count() == 0) {
            return getAjaxResponse([
                'result'  => false,
                'toastr' => exmtrans('notify_navbar.message.check_notfound'),
            ]);
        }

        foreach ($models as $model) {
            $model->update(['read_flg' => true]);
        }
        
        return getAjaxResponse([
            'result'  => true,
            'toastr' => exmtrans('notify_navbar.message.check_succeeded'),
        ]);
    }
}
