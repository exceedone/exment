<?php

namespace Exceedone\Exment\Controllers;

use Validator;
use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Controllers\HasResourceActions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;

class LoginUserController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        $this->setPageInfo(exmtrans("user.header"), exmtrans("user.header"), exmtrans("user.description"));
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
        $table = CustomTable::findByName(SystemTableName::USER);
        $grid->column($table->getIndexColumnName('user_code'), exmtrans('user.user_code'));
        $grid->column($table->getIndexColumnName('user_name'), exmtrans('user.user_name'));
        $grid->column($table->getIndexColumnName('email'), exmtrans('user.email'));
        
        $controller = $this;
        $grid->column('login_user_id', exmtrans('user.login_user'))->display(function ($login_user_id) use($controller) {
            return !is_null($controller->getLoginUser($this)) ? 'YES' : '';
        });

        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableDelete();
            $actions->disableView();
        });

        $grid->tools(function (Grid\Tools $tools) {
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

        $login_user = $this->getLoginUser($classname::find($id));
        $has_loginuser = !is_null($login_user);
        $showLoginInfo = useLoginProvider() && !boolval(config('exment.show_default_login_provider', true));

        if (!$showLoginInfo) {
            $form->header(exmtrans('user.login'))->hr();
            $form->checkboxone('use_loginuser', exmtrans('user.use_loginuser'))->option(['1' => exmtrans('common.yes') ])
                    ->help(exmtrans('user.help.use_loginuser'))
                    ->default($has_loginuser)
                    ->attribute(['data-filtertrigger' => true]);

            if ($has_loginuser) {
                $form->checkboxone('reset_password', exmtrans('user.reset_password'))->option(['1' => exmtrans('common.yes')])
                            ->default(!$has_loginuser)
                            ->help(exmtrans('user.help.reset_password'))
                            ->attribute(['data-filter' => json_encode(['key' => 'use_loginuser', 'value' => '1'])]);
            } else {
                $form->hidden('reset_password')->default("1");
            }

            $form->checkboxone('create_password_auto', exmtrans('user.create_password_auto'))->option(['1' => exmtrans('common.yes')])
                ->default(!$has_loginuser)
                ->help(exmtrans('user.help.create_password_auto'))
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'use_loginuser', 'value' => '1']
                    , ['key' => 'reset_password', 'value' => "1"]
                    ])]);

            $form->password('password', exmtrans('user.password'))->default('')
                    ->help(exmtrans('user.help.password'))
                    ->attribute(['data-filter' => json_encode([
                        ['key' => 'use_loginuser', 'value' => '1']
                        , ['key' => 'reset_password', 'value' => "1"]
                        , ['key' => 'create_password_auto', 'nullValue' => true]
                        ])]);

            $form->password('password_confirmation', exmtrans('user.password_confirmation'))->default('')
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'use_loginuser', 'value' => '1']
                    , ['key' => 'reset_password', 'value' => "1"]
                    , ['key' => 'create_password_auto', 'nullValue' => true]
                    ])]);

            $form->checkboxone('send_password', exmtrans('user.send_password'))->option(['1' => exmtrans('common.yes')])
                ->default(1)
                ->help(exmtrans('user.help.send_password'))
                ->attribute(['data-filter' => json_encode([
                    ['key' => 'create_password_auto', 'nullValue' => true]
                    ])]);

        }else{
            $form->disableSubmit();
        }

        $form->disableReset();
        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
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
        $data = Input::all();
        $user = getModelName(SystemTableName::USER)::findOrFail($id);

        DB::beginTransaction();
        try {
            // get login user
            $user = getModelName(SystemTableName::USER)::findOrFail($id);
            $login_user = $this->getLoginUser($user);
            // if "$user" has "login_user" obj and unchecked "use_loginuser", delete login user object.
            if (!is_null($login_user) && !array_key_exists('use_loginuser', $data)) {
                $login_user->delete();
                DB::commit();
                return $this->response();
            }

            // if "$user" doesn't have "login_user" obj and checked "use_loginuser", create login user object.
            $has_change = false;
            $is_newuser = false;
            $password = null;
            if (is_null($login_user) && array_get($data, 'use_loginuser')) {
                $login_user = new LoginUser;
                $is_newuser = true;
                $login_user->base_user_id = $user->id;
                $has_change = true;
            }

            // if user select "reset_password" (or new create)
            if (array_key_exists('reset_password', $data)) {
                // user select "create_password_auto"
                if (isset($data['create_password_auto'])) {
                    $password = make_password();
                    $login_user->password = bcrypt($password);
                    $has_change = true;
                } elseif (isset($data['password'])) {
                    $rules = [
                    'password' => get_password_rule(true),
                ];
                    $validation = Validator::make($data, $rules);
                    if ($validation->fails()) {
                        // TODOresponse
                        return;
                    }
                    $password = array_get($data, 'password');
                    $login_user->password = bcrypt($password);
                    $has_change = true;
                }
            }

            if ($has_change) {
                $login_user->save();

                // mailsend
                if (array_key_exists('send_password', $data)) {
                    $prms = [];
                    $prms['user'] = $user->toArray()['value'];
                    $prms['user']['password'] = $password;
                    //if($is_newuser){
                    MailSender::make($is_newuser ? MailKeyName::CREATE_USER : MailKeyName::RESET_PASSWORD_ADMIN, $user)
                        ->prms($prms)
                        ->user($user)
                        ->send();
                    //}
                }
                DB::commit();
            }
            DB::commit();
        } catch (Exception $ex) {
            DB::rollback();
            throw $ex;
        }

        return $this->response();
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
        $url = admin_base_path('loginuser');
        return redirect($url);
    }

    protected function getLoginUser($user){
        $login_user = $user->login_users()->whereNull('login_provider')->first();
        return $login_user;
    }
}
