<?php

namespace Exceedone\Exment\Controllers;

use Validator;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Form\Tools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\DataImportExport;
use Exceedone\Exment\PartialCrudItems\Providers\LoginUserItem;

class LoginUserController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("user.header"), exmtrans("user.header"), exmtrans("user.description"), 'fa-user-plus');
    }
    
    /**
     * Show interface.
     *
     * @param mixed   $id
     * @param Content $content
     * @return Content
     */
    public function show(Request $request, Content $content, $id)
    {
        return redirect(admin_urls('loginuser', $id, 'edit'));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $classname = getModelName(SystemTableName::USER);
        $grid = new Grid(new $classname);
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $grid->column($table->getIndexColumnName('user_code'), exmtrans('user.user_code'));
        $grid->column($table->getIndexColumnName('user_name'), exmtrans('user.user_name'));
        $grid->column($table->getIndexColumnName('email'), exmtrans('user.email'));
        
        $controller = $this;
        $grid->column('login_user_id', exmtrans('user.login_user'))->display(function ($login_user_id) use ($controller) {
            return !is_null($controller->getLoginUser($this)) ? 'YES' : '';
        });

        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
            $actions->disableView();
        });

        
        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\LoginUserAction(
                [
                    'grid' => $grid,
                ]
            ))->importAction(new DataImportExport\Actions\Import\LoginUserAction(
                [
                    'primary_key' => app('request')->input('select_primary_key') ?? null,
                ]
            ));
        $grid->exporter($service);
        
        $grid->tools(function (Grid\Tools $tools) use ($grid, $service) {
            $tools->append(new Tools\ExportImportButton(admin_url('loginuser'), $grid));
            $tools->append($service->getImportModal());

            $tools->batch(function (Grid\Tools\BatchActions $actions) {
                $actions->disableDelete();
            });
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
        $classname = getModelName(SystemTableName::USER);
        $form = new Form(new $classname);
        $form->display('value.user_code', exmtrans('user.user_code'));
        $form->display('value.user_name', exmtrans('user.user_name'));
        $form->display('value.email', exmtrans('user.email'));

        LoginUserItem::getItem()->setAdminFormOptions($form, $id);

        $showLoginInfo = useLoginProvider() && !boolval(config('exment.show_default_login_provider', true));
        if ($showLoginInfo) {
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
     * @param int $id
     *
     * @return \Illuminate\Http\Response
     */
    public function update($id)
    {
        DB::beginTransaction();
        try {
            $result = LoginUserItem::getItem()->saved(null, $id);
            if($result instanceof Response){
                DB::rollback();
                return $result;
            }
            DB::commit();
        } catch (\Swift_TransportException $ex) {
            admin_error('Error', exmtrans('error.mailsend_failed'));
            DB::rollback();
            return back()->withInput();
        } catch (Exception $ex) {
            DB::rollback();
            throw $ex;
        }

        return $this->response();
    }
    
    /**
     * @param Request $request
     */
    public function import(Request $request)
    {
        // create exporter
        $service = (new DataImportExport\DataImportExportService())
            ->exportAction(new DataImportExport\Actions\Export\LoginUserAction)
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

    protected function getLoginUser($user)
    {
        $login_user = $user->login_users()->whereNull('login_provider')->first();
        return $login_user;
    }
}
