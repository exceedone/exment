<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Auth\ProviderAvatar;
use Exceedone\Exment\Providers\CustomUserProvider;
use Encore\Admin\Form;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Request as Req;

/**
 * For login controller
 */
class AuthController extends \Encore\Admin\Controllers\AuthController
{
    use AuthTrait;

    /**
     * Login page.
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLoginExment(Request $request)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        return view('exment::auth.login', $this->getLoginPageData());
    }   
    

    /**
     * Login page using provider (SSO).
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function getLoginProvider(Request $request, $login_provider)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        // provider check
        $provider = config("services.$login_provider");
        if(!isset($provider)){
            abort(404);
        }
        $socialiteProvider = $this->getSocialiteProvider($login_provider);
        return $socialiteProvider->redirect();
    }

    /**
     * callback login provider and login exment
     *
     * @return \Illuminate\Contracts\View\Factory|Redirect|\Illuminate\View\View
     */
    public function callbackLoginProvider(Request $request, $login_provider)
    {
        if ($this->guard()->check()) {
            return redirect($this->redirectPath());
        }

        $error_url = admin_base_path('auth/login');
        
        $socialiteProvider = $this->getSocialiteProvider($login_provider);

        // get provider user
        $provider_user = null;
        try {
            $provider_user = $socialiteProvider->user();
        }
        catch(\Exception $ex){
            return redirect($error_url)->withInput()->withErrors(
                [$this->username() => exmtrans('login.sso_provider_error')]
            );   
        }

        // check exment user
        $exment_user = $this->getExmentUser($provider_user);
        if($exment_user === false){
            return redirect($error_url)->withInput()->withErrors(
                [$this->username() => exmtrans('login.noexists_user')]
            );
        }

        $login_user = $this->getLoginUser($socialiteProvider, $login_provider, $exment_user, $provider_user);
        
        if ($this->guard()->attempt(
            [
                'username' => $provider_user->email,
                'login_provider' => $login_provider,
                'password' => $provider_user->id,
            ]
        )) {
            return $this->sendLoginResponse($request);
        }

        return redirect($error_url)->withInput()->withErrors([$this->username() => $this->getFailedLoginMessage()]);
    }

    /**
     * get Socialite Provider
     */
    protected function getSocialiteProvider(string $login_provider){
        config(["services.$login_provider.redirect" => admin_urls("auth", "login", $login_provider, "callback")]);
        
        return \Socialite::with($login_provider)->stateless();
    }
    
    /**
     * get exment user from users table
     */
    protected function getExmentUser($provider_user){
        
        $exment_user = getModelName(SystemTableName::USER)
            ::where('value->email', $provider_user->email)
            ->first();
        if(!isset($exment_user)){
            return false;
        }

        // update user info
        $exment_user->setValue([
            'user_name' => $provider_user->name
        ]);
        $exment_user->save();

        return $exment_user;
    }

    /**
     * get login_user from login_users table
     */
    protected function getLoginUser($socialiteProvider, $login_provider, $exment_user, $provider_user){
        $hasLoginUser = false;
        // get login_user
        $login_user = CustomUserProvider::RetrieveByCredential(
            [
                'username' => $provider_user->email,
                'login_provider' => $login_provider
            ]
        );
        if(isset($login_user)){
            // check password
            if(CustomUserProvider::ValidateCredential($login_user, [
                'password' => $provider_user->id
            ])){
                $hasLoginUser = true;
            }
        }

        // get avatar
        $avatar  = $this->getAvatar($socialiteProvider, $provider_user);

        // if don't has, create loginuser or match email
        if (!$hasLoginUser) {
            $login_user = LoginUser::firstOrNew([
                'base_user_id' => $exment_user->id,
                'login_provider' => $login_provider,
                ]);
            $login_user->base_user_id = $exment_user->id;
            $login_user->login_provider = $login_provider;
            $login_user->password = bcrypt($provider_user->id);
        }

        if(isset($avatar)){
            $login_user->avatar = $avatar;
        }
        $login_user->save();
        return $login_user;
    }

    protected function getAvatar($socialiteProvider, $provider_user){
        try{
            // if socialiteProvider implements ProviderAvatar, call getAvatar
            if(is_subclass_of($socialiteProvider, ProviderAvatar::class)){
                $stream = $socialiteProvider->getAvatar($provider_user->token);
            }
            // if user obj has avatar, download avatar.
            else if(isset($provider_user)){
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', $avatar, [
                    'http_errors' => false,
                ]);
                $stream = $response->getBody()->getContents();
            }
            // file upload.
            if($stream != null){
                $file = ExmentFile::put(path_join("avatar", $provider_user->id), $stream, true);
                return $file->path;
            }
        }finally{

        }
        return null;
    }

    /**
     * Model-form for user setting.
     *
     * @return Form
     */
    protected function settingForm()
    {
        $user = LoginUser::class;
        return $user::form(function (Form $form) {
            $form->display('base_user.value.user_code', exmtrans('user.user_code'));
            $form->text('base_user.value.user_name', exmtrans('user.user_name'));
            $form->email('base_user.value.email', exmtrans('user.email'));
            $form->image('avatar', exmtrans('user.avatar'))
                ->move('avatar')
                ->name(function ($file) {
                    $exmentfile = ExmentFile::saveFileInfo($this->getDirectory(), $file->getClientOriginalName());
                    return $exmentfile->filename;
                });

            if (!useLoginProvider()) {
                $form->password('password', exmtrans('user.new_password'))->rules(get_password_rule(false))->help(exmtrans('user.help.change_only').exmtrans('user.help.password'));
                $form->password('password_confirmation', exmtrans('user.new_password_confirmation'));
            }

            $form->setAction(admin_base_path('auth/setting'));
            $form->ignore(['password_confirmation']);
            disableFormFooter($form);
            $form->tools(function (Form\Tools $tools) {
                $tools->disableView();
                $tools->disableDelete();
            });

            $form->saving(function (Form $form) {
                // if not contains $form->password, return
                $form_password = $form->password;
                if (!isset($form_password)) {
                    $form->password = $form->model()->password;
                } elseif ($form_password && $form->model()->password != $form_password) {
                    $form->password = bcrypt($form_password);
                }
            });
            
            $form->saved(function ($form) {
                // saving user info
                DB::transaction(function () use ($form) {
                    $req = Req::all();

                    // login_user id
                    $user_id = $form->model()->base_user->id;
                    // save user name and email
                    $user = getModelName(SystemTableName::USER)::find($user_id);
                    $user->setValue([
                        'user_name' => array_get($req, 'base_user.value.user_name'),
                        'email' => array_get($req, 'base_user.value.email'),
                    ]);
                    $user->save();
                });
                
                admin_toastr(trans('admin.update_succeeded'));
    
                return redirect(admin_base_path('auth/setting'));
            });
        });
    }
}
