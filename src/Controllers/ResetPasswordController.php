<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\ResetsPasswords;
use Illuminate\Support\Facades\Config;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Password;

class ResetPasswordController extends Controller
{
    use ResetsPasswords;
    use \Exceedone\Exment\Controllers\AuthTrait;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->redirectTo = admin_base_path('');
        $model = getModelName(SystemTableName::USER);
        if (!Config::has('auth.providers.exment_admins')) {
            Config::set('auth.providers.exment_admins', [
                'driver' => 'eloquent',
                'model' => $model
            ]);
        }

        // add for exment_admins
        if (!Config::has('auth.passwords.exment_admins')) {
            Config::set('auth.passwords.exment_admins', [
                'provider' => 'exment_admins',
                'table' => 'password_resets',
                'expire' => 720,
            ]);
        }
        //TODO:only set admin::guest
        //$this->middleware('guest');
    }

    /**
     * Get the password reset validation rules.
     *
     * @return array
     */
    protected function rules()
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => get_password_rule(true),
        ];
    }

    /**
     * Display the password reset view for the given token.
     *
     * If no token is present, display the link request form.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string|null  $token
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function showResetForm(Request $request, $token)
    {
        return view('exment::auth.reset')->with(
            $this->getLoginPageData(['token' => $token, 'email' => $request->email])
        );
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function reset(Request $request)
    {
        $this->validate($request, $this->rules(), $this->validationErrorMessages());

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $broker = $this->broker();
        $array = $request->only(
            'password',
            'password_confirmation',
            'token'
        );
        $array['email'] = $request->get('email');
        $response = $broker->reset(
            $array,
            function ($user, $password) {
                $this->resetPassword($user, $password);
            }
        );

        if ($response == Password::PASSWORD_RESET) {
            admin_toastr(trans('admin.update_succeeded'));
        }
        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $response == Password::PASSWORD_RESET
                    ? $this->sendResetResponse($response)
                    : $this->sendResetFailedResponse($request, $response);
    }

    /**
     * Reset the given user's password.
     *
     * @param  \Illuminate\Contracts\Auth\CanResetPassword  $user
     * @param  string  $password
     * @return void
     */
    protected function resetPassword(LoginUser $user, $password)
    {
        $user->password = Hash::make($password);

        //$user->setRememberToken(Str::random(60));

        $user->saveOrFail();

        event(new PasswordReset($user));

        $this->guard()->login($user);
    }

    //defining which password broker to use, in our case its the exment
    protected function broker()
    {
        return Password::broker('exment_admins');
    }
        
    protected function guard()
    {
        return Auth::guard('admin');
    }
}
