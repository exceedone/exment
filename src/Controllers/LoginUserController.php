<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Auth\Permission as Checker;
use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Form\Tools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\PartialCrudItems\Providers\LoginUserItem;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class LoginUserController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("user.header"), exmtrans("user.header"), exmtrans("user.description"), 'fa-user-plus');
    }

    /**
     * Show interface.
     *
     * @param Request $request
     * @param Content $content
     * @param $id
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $id)
    {
        return redirect(admin_urls('loginuser', $id, 'edit'));
    }

    /**
     * Edit interface.
     *
     * @param Request $request
     * @param Content $content
     * @param mixed $id
     * @return Content
     */
    public function edit(Request $request, Content $content, $id)
    {
        $this->denyUneditableUser($id);
        return parent::edit($request, $content, $id);
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $classname = getModelName(SystemTableName::USER);
        $grid = new Grid(new $classname());
        $table = CustomTable::getEloquent(SystemTableName::USER);

        foreach (['user_code', 'user_name', 'email'] as $key) {
            $column = CustomColumn::getEloquent($key, $table);
            if (!$column) {
                continue;
            }
            $grid->column($column->getQueryKey(), exmtrans('user.' . $key));
        }

        $controller = $this;
        $grid->column('login_user_id', exmtrans('user.login_user'))->display(function ($login_user_id) use ($controller) {
            return !is_null($controller->getLoginUser($this)) ? 'YES' : '';
        });

        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
            $actions->disableView();
            // only administrator can change administrator's login (see denyUneditableUser)
            if ($actions->row instanceof CustomValue && method_exists($actions->row, 'disabled_edit_trait') && $actions->row->disabled_edit_trait()) {
                $actions->disableEdit();
            }
        });

        $grid->filter(function ($filter) use ($table) {
            foreach (['user_code', 'user_name', 'email'] as $key) {
                $column = CustomColumn::getEloquent($key, $table);
                if (!$column) {
                    continue;
                }
                $filter->like($column->getQueryKey(), exmtrans('user.' . $key));
            }
        });

        // create exporter
        $service = $this->getImportExportService($grid);
        $grid->exporter($service);

        $grid->tools(function (Grid\Tools $tools) use ($grid) {
            $button = new Tools\ExportImportButton(admin_url('loginuser'), $grid, false, true);
            $button->setBaseKey('common');

            /** @phpstan-ignore-next-line append() expects ExmentAdminCore\Admin\Grid\Tools\AbstractTool|string, Exceedone\Exment\Form\Tools\ExportImportButton given */
            $tools->append($button);
            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
        });


        return $grid;
    }

    /**
     * get import modal
     */
    // @phpstan-ignore-next-line
    public function importModal(Request $request)
    {
        $service = $this->getImportExportService();
        return $service->getImportModal();
    }

    // @phpstan-ignore-next-line
    protected function getImportExportService($grid = null)
    {
        // create exporter
        return (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\LoginUserAction(
                [
                    'grid' => $grid,
                ]
            ))->importAction(new DataImportExport\Actions\Import\LoginUserAction(
                [
                    'primary_key' => app('request')->input('select_primary_key') ?? null,
                ]
            ));
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    // @phpstan-ignore-next-line
    protected function form($id = null)
    {
        $classname = getModelName(SystemTableName::USER);
        $form = new Form(new $classname());
        $form->display('value.user_code', exmtrans('user.user_code'));
        $form->display('value.user_name', exmtrans('user.user_name'));
        $form->display('value.email', exmtrans('user.email'));

        LoginUserItem::getItem()->setAdminFormOptions($form, $id);

        if (!LoginSetting::isUseDefaultLoginForm()) {
            $form->disableSubmit();
        }
        $form->disableReset();
        $form->tools(function (Form\Tools $tools) {
            $tools->disableDelete();
        });
        return $form;
    }

    /**
     * Update the specified resource in storage.
     *
     * @param $id
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|\Illuminate\Http\Response|\Illuminate\Routing\Redirector|Response
     * @throws \Throwable
     */
    // @phpstan-ignore-next-line
    public function update($id)
    {
        $this->denyUneditableUser($id);

        DB::beginTransaction();
        try {
            $result = LoginUserItem::getItem()->saved(null, $id);
            if ($result instanceof Response) {
                DB::rollback();
                return $result;
            }
            DB::commit();
        } catch (TransportExceptionInterface $ex) {
            \Log::error($ex);
            admin_error('Error', exmtrans('error.mailsend_failed'));
            DB::rollback();
            return back()->withInput(get_flash_input());
        } catch (\Exception $ex) {
            DB::rollback();
            throw $ex;
        }

        return $this->response();
    }

    /**
     * @param Request $request
     */
    // @phpstan-ignore-next-line
    public function import(Request $request)
    {
        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\LoginUserAction())
            ->importAction(
                new DataImportExport\Actions\Import\LoginUserAction(
                    [
                    'primary_key' => app('request')->input('select_primary_key') ?? null,
                ]
                )
            )->format($request->file('custom_table_file'));
        $result = $service->import($request);

        return getAjaxResponse($result);
    }

    // @phpstan-ignore-next-line
    protected function response()
    {
        $message = trans('admin.update_succeeded');
        $request = request();
        // ajax but not pjax
        if ($request->ajax() && !$request->pjax()) {
            return response()->json([
                'status'  => true,
                'message' => $message,
            ]);
        }

        admin_toastr($message);
        $url = admin_url('loginuser');
        return redirect($url);
    }

    /**
     * Only an administrator can change the login of an administrator
     * (UserTrait::disabled_edit_trait). The list hides the Edit button of
     * that row; the edit page and the save refuse it. The user is read
     * without the record scope: LoginUserItem finds the login by its user
     * id, whether the scope shows that user or not.
     *
     * @param mixed $id
     * @return void
     */
    protected function denyUneditableUser($id)
    {
        $user = getModelName(SystemTableName::USER)::withoutGlobalScopes()->find($id);
        if (isset($user) && $user->disabled_edit_trait()) {
            Checker::error();
        }
    }

    // @phpstan-ignore-next-line
    protected function getLoginUser($user)
    {
        $login_user = $user->login_users()->whereNull('login_provider')->first();
        return $login_user;
    }
}
