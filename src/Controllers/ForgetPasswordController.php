<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use App\Http\Controllers\Controller;
use Exceedone\Exment\Enums\LoginType;
use Password;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class ForgetPasswordController extends Controller
{
    use SendsPasswordResetEmails;
    use \Exceedone\Exment\Controllers\AuthTrait;

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        //TODO:only set admin::guest
        //$this->middleware('guest');
    }

    /**
     * Display the form to request a password reset link.
     * Customize
     * @return bool|\Illuminate\Auth\Access\Response|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View|mixed
     */
    public function showLinkRequestForm()
    {
        return view('exment::auth.email', $this->getLoginPageData());
    }

    //defining which password broker to use, in our case its the exment
    protected function broker()
    {
        return Password::broker('exment_admins');
    }

    /**
     * Send a reset link to the given user.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
     */
    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);

        // We will send the password reset link to this user. Once we have attempted
        // to send the link, we will examine the response then see the message we
        // need to show to the user. Finally, we'll send out a proper response.
        $broker = $this->broker();
        $array = [
            'login_type' => LoginType::PURE,
            'target_column' => 'email',
            'username' => $request->input('email'),
        ];

        try {
            $response = $broker->sendResetLink($array);
            return $response == Password::RESET_LINK_SENT
                        ? $this->sendResetLinkResponse($request, $response)
                        : $this->sendResetLinkFailedResponse($request, $response);
        } catch (TransportExceptionInterface $ex) {
            \Log::error($ex);
            return back()->with('status_error', exmtrans('error.mailsend_failed'));
        }
    }
}
