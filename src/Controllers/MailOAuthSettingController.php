<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\MailOAuth\MailOAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The settings of the mail with OAuth 2.0 (ISS02913, Services\MailOAuth): the provider, the
 * application (tenant, client ID, secret), and for Google the mailbox connected. The mail server,
 * its port and the mailbox (the user name) stay on the system setting page.
 *
 * Only the system administrators (Auth\Permission), and only while the feature is used
 * (Features::MAIL_OAUTH).
 */
class MailOAuthSettingController extends SystemSettingPageControllerBase
{
    protected const STATE_KEY = 'exment_mail_oauth_state';

    public function __construct()
    {
        $this->setPageInfo(exmtrans('system.mail_oauth.header'), exmtrans('system.mail_oauth.header'), exmtrans('system.mail_oauth.description'), 'fa-envelope');
    }

    protected function uri(): string
    {
        return 'mail_oauth_setting';
    }

    protected function group(): string
    {
        return 'mail_oauth';
    }

    protected function title(): string
    {
        return exmtrans('system.mail_oauth.header');
    }

    protected function setFields(WidgetForm $form): void
    {
        $form->descriptionHtml(esc_html(exmtrans('system.mail_oauth.help')) . ' <a href="' . esc_html(admin_url('system?advanced=1')) . '">' . esc_html(exmtrans('system.mail_oauth.mail_setting_link')) . '</a>');

        $form->select('system_mail_auth', exmtrans('system.mail_oauth.system_mail_auth'))
            ->options([
                MailOAuth::MICROSOFT => exmtrans('system.mail_oauth.providers.microsoft'),
                MailOAuth::GOOGLE => exmtrans('system.mail_oauth.providers.google'),
            ])
            ->help(exmtrans('system.mail_oauth.help_system_mail_auth'));

        $form->text('system_mail_oauth_tenant', exmtrans('system.mail_oauth.system_mail_oauth_tenant'))
            ->rules(['nullable', 'max:100', 'regex:/\A[A-Za-z0-9.\-]+\z/'])
            ->help(exmtrans('system.mail_oauth.help_system_mail_oauth_tenant'))
            ->attribute(['data-filter' => json_encode(['key' => 'system_mail_auth', 'value' => [MailOAuth::MICROSOFT]])]);

        $form->text('system_mail_oauth_client_id', exmtrans('system.mail_oauth.system_mail_oauth_client_id'))
            ->rules(['nullable', 'max:300']);

        $form->password('system_mail_oauth_client_secret', exmtrans('system.mail_oauth.system_mail_oauth_client_secret'))
            ->rules(['nullable', 'max:1000'])
            ->help(exmtrans('system.mail_oauth.help_system_mail_oauth_client_secret'));

        // Google: the mailbox is connected here, once (its refresh token is kept)
        $connectedAt = System::system_mail_oauth_connected_at();
        $state = is_nullorempty(System::system_mail_oauth_refresh_token())
            ? exmtrans('system.mail_oauth.not_connected')
            : exmtrans('system.mail_oauth.connected', strval($connectedAt));
        $form->descriptionHtml(
            '<p>' . esc_html($state) . '</p>'
            . '<p>' . esc_html(exmtrans('system.mail_oauth.help_redirect_uri')) . ' <code>' . esc_html(static::googleRedirectUri()) . '</code></p>'
            . '<a class="btn btn-sm btn-default" href="' . esc_html(admin_url('mail_oauth_setting/google')) . '">' . esc_html(exmtrans('system.mail_oauth.connect_google')) . '</a>'
        )->attribute(['data-filter' => json_encode(['key' => 'system_mail_auth', 'value' => [MailOAuth::GOOGLE]])]);
    }

    /**
     * Save (the secret is on the form as the mail password is); another application: a new token.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function post(Request $request)
    {
        MailOAuth::forgetToken();
        $response = parent::post($request);
        MailOAuth::forgetToken();
        return $response;
    }

    /**
     * To the page of Google, to connect the mailbox.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function connectGoogle(Request $request)
    {
        if (is_nullorempty(System::system_mail_oauth_client_id()) || is_nullorempty(System::system_mail_oauth_client_secret())) {
            admin_toastr(exmtrans('system.mail_oauth.not_set'), 'error');
            return redirect(admin_url($this->uri()));
        }
        $state = Str::random(40);
        $request->session()->put(static::STATE_KEY, $state);
        return redirect()->away(MailOAuth::googleAuthUrl(static::googleRedirectUri(), $state));
    }

    /**
     * Google sends the administrator back here with a code.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function googleCallback(Request $request)
    {
        $expected = $request->session()->pull(static::STATE_KEY);
        $state = strval($request->query('state'));
        if (!is_string($expected) || $expected === '' || !hash_equals($expected, $state)) {
            admin_toastr(exmtrans('system.mail_oauth.connect_failed', 'state'), 'error');
            return redirect(admin_url($this->uri()));
        }
        $code = strval($request->query('code'));
        if ($code === '') {
            admin_toastr(admin_html_message(exmtrans('system.mail_oauth.connect_failed', esc_html(Str::limit(strval($request->query('error')), 100)))), 'error');
            return redirect(admin_url($this->uri()));
        }

        try {
            MailOAuth::connectGoogle($code, static::googleRedirectUri());
        } catch (\Throwable $ex) {
            \Log::warning('[Exment] the mailbox was not connected to Google: ' . $ex->getMessage());
            admin_toastr(admin_html_message(exmtrans('system.mail_oauth.connect_failed', esc_html($ex->getMessage()))), 'error');
            return redirect(admin_url($this->uri()));
        }
        System::clearCache();
        admin_toastr(exmtrans('system.mail_oauth.connect_succeeded'));
        return redirect(admin_url($this->uri()));
    }

    /**
     * The address to register in the client of Google.
     */
    public static function googleRedirectUri(): string
    {
        return admin_url('mail_oauth_setting/google/callback');
    }
}
