<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Http\Request;
use Encore\Admin\Show;
use Exceedone\Exment\Form\Tools\SwalMenuButton;
use Exceedone\Exment\Grid\Tools\BatchCheck;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar;

class NotifyNavbarController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
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
        $grid = new Grid(new NotifyNavbar());
        $grid->column('read_flg', exmtrans('notify_navbar.read_flg'))->sortable()->display(function ($read_flg) {
            return exmtrans("notify_navbar.read_flg_options.$read_flg");
        });
        $grid->column('parent_type', exmtrans('notify_navbar.parent_type'))->sortable()->display(function ($parent_type) {
            if (is_null($parent_type) || is_null($custom_table = CustomTable::getEloquent($parent_type))) {
                return null;
            }
            return $custom_table->table_view_name;
        });
        $grid->column('notify_subject', exmtrans('notify_navbar.notify_subject'))->sortable();
        $grid->column('created_at', exmtrans('common.created_at'))->sortable();

        $grid->disableCreation();
        $grid->disableExport();

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append(new SwalMenuButton($this->getMenuList()));
            $tools->batch(function (Grid\Tools\BatchActions $batch) {
                $batch->add(exmtrans('notify_navbar.all_check'), new BatchCheck());
            });
        });

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();

            if (isset($actions->row->parent_id)) {
                // reference target data
                $linker = (new Linker())
                    ->url(admin_url("notify_navbar/rowdetail/{$actions->row->id}"))
                    ->icon('fa-list')
                    ->tooltip(exmtrans('notify_navbar.data_refer'));
                $actions->prepend($linker);
            }
        });

        $grid->filter(function ($filter) {
            $filter->disableIdFilter();

            $options = [
                '' => 'All',
                0 => exmtrans("notify_navbar.read_flg_options.0"),
                1 => exmtrans("notify_navbar.read_flg_options.1"),
            ];
            $filter->equal('read_flg', exmtrans("notify_navbar.read_flg"))->radio($options);

            $filter->equal('parent_type', exmtrans("notify_navbar.parent_type"))->select(function ($val) {
                return CustomTable::filterList()->pluck('table_view_name', 'table_view_name');
            });

            $filter->like('notify_subject', exmtrans("notify_navbar.notify_subject"));
        });

        return $grid;
    }


    /**
     * create batch processing menu list.
     *
     * @return array
     */
    protected function getMenuList(): array
    {
        $menulist = [];
        $menulist[] = [
            'url' => admin_url('notify_navbar/batchAll/read'),
            'label' => exmtrans('notify_navbar.read_all'),
            'title' => exmtrans('notify_navbar.batch_all'),
            'text' => exmtrans('notify_navbar.confirm_text.read_all'),
            'method' => 'post',
            'confirm' => trans('admin.confirm'),
            'cancel' => trans('admin.cancel'),
        ];
        $menulist[] = [
            'url' => admin_url('notify_navbar/batchAll/unread'),
            'label' => exmtrans('notify_navbar.unread_all'),
            'title' => exmtrans('notify_navbar.batch_all'),
            'text' => exmtrans('notify_navbar.confirm_text.unread_all'),
            'method' => 'post',
            'confirm' => trans('admin.confirm'),
            'cancel' => trans('admin.cancel'),
        ];
        $menulist[] = [
            'url' => admin_url('notify_navbar/batchAll/delete'),
            'label' => exmtrans('notify_navbar.delete_all'),
            'title' => exmtrans('notify_navbar.batch_all'),
            'text' => exmtrans('notify_navbar.confirm_text.delete_all'),
            'method' => 'post',
            'confirm' => trans('admin.confirm'),
            'cancel' => trans('admin.cancel'),
        ];
        return $menulist;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return new Form(new NotifyNavbar());
    }

    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = NotifyNavbar::find($id);

        if (!isset($model)) {
            abort(404);
        }

        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        }

        $custom_value = null;
        /** @var CustomValue|null $custom_table */
        $custom_table = null;
        if (!is_null($parent_type = array_get($model, 'parent_type'))) {
            if (!is_null($custom_table = CustomTable::getEloquent($parent_type))) {
                $custom_value = $custom_table->getValueModel(array_get($model, 'parent_id'));
            }
        }

        return new Show($model, function (Show $show) use ($id, $parent_type, $custom_value, $custom_table) {
            if (isset($parent_type)) {
                $show->field('parent_type', exmtrans('notify_navbar.parent_type'))->as(function ($parent_type) use ($custom_table) {
                    if (is_null($parent_type) || is_null($custom_table)) {
                        return null;
                    }
                    return $custom_table->table_view_name;
                });
            }

            if (isset($custom_value)) {
                $show->field('target_custom_value', exmtrans('notify_navbar.target_custom_value'))->as(function ($v) use ($custom_value) {
                    return $custom_value->getLabel();
                })->setEscape(false);
            }
            $show->field('notify_subject', exmtrans('notify_navbar.notify_subject'));
            $show->field('notify_body', exmtrans('notify_navbar.notify_body'))
                ->as(function ($v) {
                    return  html_clean(replaceBreak($v, false));
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
     * batch processing all notifications
     *
     * @param Request   $request
     * @param String    $type
     *
     * @return \Symfony\Component\HttpFoundation\Response Response for ajax json
     */
    public function batchAll(Request $request, String $type): \Symfony\Component\HttpFoundation\Response
    {
        \DB::beginTransaction();
        try {
            switch ($type) {
                case 'read':
                    NotifyNavbar::query()->update(['read_flg' => true]);
                    break;
                case 'unread':
                    NotifyNavbar::query()->update(['read_flg' => false]);
                    break;
                case 'delete':
                    NotifyNavbar::query()->delete();
                    break;
                default:
                    throw new \Exception();
            }

            \DB::commit();

            return getAjaxResponse([
                'result'  => true,
                'toastr' => exmtrans('notify_navbar.message.'.$type.'_succeeded'),
            ]);
        } catch (\Exception $e) {
            \DB::rollback();
        }

        return getAjaxResponse([
            'result'  => false,
            'swal' => exmtrans('common.error'),
            'swaltext' => exmtrans('notify_navbar.message.batch_error'),
        ]);
    }

    /**
     * redirect custom values's detail page
     *
     * @param mixed   $id
     */
    public function redirectTargetData(Request $request, $id = null)
    {
        $model = NotifyNavbar::find($id);

        if (!isset($model)) {
            abort(404);
        }

        // update read_flg
        if ($model->read_flg == 0) {
            $model->update(['read_flg' => true]);
        }

        $custom_table = getModelName($model->parent_type);

        if (is_nullorempty($custom_table)) {
            return back();
        }

        $custom_value = $custom_table::find($model->parent_id);

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
        if (is_nullorempty($models)) {
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
