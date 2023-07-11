<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\ResetsPasswords;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Password;

class ResetPasswordController extends Controller
{
    use ResetsPasswords;
    use \Exceedone\Exment\Controllers\AuthTrait;

    protected $login_user;
    protected $redirectTo;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->redirectTo = admin_url('auth/login');
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
            'password' => get_password_rule(true, $this->login_user),
        ];
    }

    /**
     * Display the password reset view for the given token.
     * If no token is present, display the link request form.
     *
     * @param Request $request
     * @param $token
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\View|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function showResetForm(Request $request, $token)
    {
        // get email
        $email = $this->getEmailByToken($token);
        if (!isset($email)) {
            admin_toastr(trans('passwords.token'));
            return redirect($this->redirectTo);
        }

        return view('exment::auth.reset')->with(
            $this->getLoginPageData(['token' => $token, 'email' => $email])
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
        $broker = $this->broker();

        // get email
        $email = $this->getEmailByToken($request->get('token'));
        if (!isset($email)) {
            admin_toastr(trans('passwords.token'));
            return back()->withInput();
        }

        $array = [
            'login_type' => LoginType::PURE,
            'target_column' => 'email',
            'username' => $email,
        ];

        // get user for password history validation
        $this->login_user = $broker->getUser($array);

        $this->validate($request, $this->rules(), $this->validationErrorMessages());

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $array = $request->only(
            'password',
            'password_confirmation',
            'token'
        );

        $array = array_merge([
            'login_type' => LoginType::PURE,
            'target_column' => 'email',
            'username' => $email,
        ], $array);

        $response = $broker->reset(
            $array,
            function ($user, $password) {
                $this->resetPassword($user, $password);
            }
        );

        if ($response == Password::PASSWORD_RESET) {
            admin_toastr(trans($response));
        }
        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $response == Password::PASSWORD_RESET
                    ? $this->sendResetResponse($request, $response)
                    : $this->sendResetFailedResponse($request, $response);
    }

    /**
     * Reset the given user's password.
     *
     * @param  LoginUser  $user
     * @param  string  $password
     * @return void
     */
    protected function resetPassword(LoginUser $user, $password)
    {
        // password sets at LoginUser Model
        $user->password = $password;
        //$user->password = Hash::make($password);

        //$user->setRememberToken(Str::random(60));

        $user->saveOrFail();

        event(new PasswordReset($user));
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

    /**
     * Get email address by token
     *
     * @return string|null
     */
    protected function getEmailByToken($token)
    {
        $broker = $this->broker();
        // get email by table 'password_resets'
        $records = \DB::table(SystemTableName::PASSWORD_RESET)->get()->toArray();

        foreach ($records as $record) {
            // if match token
            if ($broker->getRepository()->getHasher()->check($token, $record->token)) {
                return $record->email;
            }
        }

        return null;
    }
}
