<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Controllers;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Navbar\NavbarChannel;
use Illuminate\Http\Request;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Demo mode: config "exment.demo_mode" (.env EXMENT_DEMO_MODE).
 *
 * A public demo site publishes the login of its system administrator. In demo mode the
 * operations that run code on the server, change the server or other sites, send anything
 * outside or show secrets are refused, for every user. Normal data work stays: tables,
 * columns, forms, views, dashboards, data, workflows, comments and files.
 *
 * Everything that is refused is listed in this class, so it can be read in one place:
 * - BLOCKED_ACTIONS: the web and API actions, refused by the middleware DemoModeGuard.
 * - changesOwnLogin(): the password and login ID on the user's own setting page.
 * - LOCKED_SYSTEM_KEYS: the system settings that are not saved.
 * - userLoginErrors() / userDestroyError(): the login ID and the deletion of users who can log in.
 * - keepsSystemAdmins(): the system administrators of the system setting page.
 * - keepPublicFormScripts(): the custom CSS and JavaScript of public forms.
 * - hidesMenuUri(): the menu items of refused pages.
 * - allowsNotificationChannel(): only mail (to the log, see applyRuntimeConfig()) and the
 *   navbar notification are sent; Slack, Teams and any other channel are dropped.
 * - allowsOutboundMessage(): LINE messages are not sent.
 *
 * Console commands are not limited, so the server can still reset the demo by cron.
 */
class DemoMode
{
    /**
     * Web and API actions refused in demo mode, by controller.
     * "*" refuses every action of the controller.
     *
     * @var array<string, array<int, string>>
     */
    public const BLOCKED_ACTIONS = [
        // plugins: upload, settings, delete and the code editor. Installed plugins still run.
        Controllers\PluginController::class => ['create', 'store', 'edit', 'update', 'destroy', 'show'],
        Controllers\PluginCodeController::class => ['*'],
        // backup: create, download, upload, restore, rename, delete and the automatic backup setting
        Controllers\BackupController::class => ['*'],
        // template import (the file can contain anything) and template delete. Export stays.
        Controllers\TemplateController::class => ['import', 'delete'],
        // test mail
        Controllers\SystemController::class => ['sendTestMail'],
        // login settings: SSO / SAML / OAuth / LDAP, 2 factor and the password policy
        Controllers\LoginSettingController::class => [
            'create', 'store', 'edit', 'update', 'destroy', 'show',
            'activate', 'deactivate',
            'loginTestModal', 'loginTestForm', 'loginTestSso', 'loginTestCallback',
            'auth_2factor_verify', 'post2factor', 'postGlobal',
        ],
        // API keys and OAuth clients. The edit page shows the client secret.
        Controllers\ApiSettingController::class => ['create', 'store', 'edit', 'update', 'destroy', 'show'],
        \Laravel\Passport\Http\Controllers\ClientController::class => ['store', 'update', 'destroy'],
        \Laravel\Passport\Http\Controllers\PersonalAccessTokenController::class => ['store', 'destroy'],
        // passwords: the published login must keep working
        Controllers\ChangePasswordController::class => ['change'],
        Controllers\ForgetPasswordController::class => ['sendResetLinkEmail'],
        Controllers\ResetPasswordController::class => ['reset'],
        Controllers\LoginUserController::class => ['update', 'import'],
        // operation log: it shows the IP addresses and requests of the other visitors
        Controllers\LogController::class => ['*'],
        Controllers\ApiController::class => ['authLogs', 'authLog'],
        // "Run now" of the scheduled jobs: it starts commands on the server (cron still runs them)
        Controllers\ScheduledJobController::class => ['run'],
        // the API rate limit of the site (ISS00442)
        Controllers\ApiRateRaiseController::class => ['store', 'delete'],
    ];

    /**
     * System menu items not shown in demo mode (their pages are refused).
     *
     * @var array<int, string>
     */
    public const HIDDEN_MENU_URIS = ['auth/logs', 'backup'];

    /**
     * Public form options that run in the browser of the next visitor. In demo mode
     * saving a public form keeps their old value.
     *
     * @var array<int, string>
     */
    public const LOCKED_PUBLIC_FORM_OPTIONS = ['custom_css', 'custom_js'];

    /**
     * System settings that are not saved in demo mode: they reach outside, change how the
     * server works or hold secrets.
     *
     * @var array<int, string>
     */
    public const LOCKED_SYSTEM_KEYS = [
        // mail server
        'system_mail_host',
        'system_mail_port',
        'system_mail_encryption',
        'system_mail_username',
        'system_mail_password',
        'system_mail_from',
        'system_mail_from_view_name',
        'system_mail_auth',
        'system_mail_oauth_tenant',
        'system_mail_oauth_client_id',
        'system_mail_oauth_client_secret',
        // LINE
        'system_line_channel_token',
        'system_line_channel_secret',
        'system_line_oa_basic_id',
        // Teams workflow
        'system_teams_workflow_url',
        // LINE WORKS
        'system_lineworks_client_id',
        'system_lineworks_client_secret',
        'system_lineworks_service_account',
        'system_lineworks_private_key',
        'system_lineworks_bot_id',
        'system_lineworks_user_column',
        // automatic safety check sends by itself
        'safety_check_auto_enabled',
        // full-text search server
        'meili_host',
        'meili_key',
        'meili_index',
        'meili_realtime_sync',
        'meili_batch_size',
        'meili_repair_enabled',
        'meili_repair_at',
        // IP filter: could lock every visitor out
        'web_ip_filters',
        'api_ip_filters',
        'web_ip_filter_exempt_users',
        'web_ip_filter_exempt_organizations',
        'web_ip_filter_exempt_role_groups',
        'api_allowed_users',
        'api_allowed_organizations',
        'api_allowed_role_groups',
        // reCAPTCHA keys
        'recaptcha_type',
        'recaptcha_site_key',
        'recaptcha_secret_key',
        // API and the connection to exment.net
        'api_available',
        'outside_api',
    ];

    /**
     * System settings whose value is not shown on the setting page in demo mode.
     *
     * @var array<int, string>
     */
    public const SECRET_SYSTEM_KEYS = [
        'system_mail_username',
        'system_mail_password',
        'system_mail_oauth_client_secret',
        'system_line_channel_token',
        'system_line_channel_secret',
        'system_teams_workflow_url',
        'system_lineworks_client_secret',
        'system_lineworks_private_key',
        'meili_key',
        'recaptcha_site_key',
        'recaptcha_secret_key',
    ];

    /**
     * The user columns a user logs in with.
     *
     * @var array<int, string>
     */
    public const LOGIN_ID_COLUMNS = ['user_code', 'email'];

    public static function enabled(): bool
    {
        return boolval(config('exment.demo_mode', false));
    }

    /**
     * Register the listeners. They check enabled() each time, so the config can change at runtime.
     */
    public static function boot(): void
    {
        Event::listen(NotificationSending::class, function (NotificationSending $event) {
            if (static::allowsNotificationChannel($event->channel)) {
                return null;
            }
            Log::info('Demo mode: a notification was not sent.', [
                'channel' => $event->channel,
                'notification' => get_class($event->notification),
            ]);
            // false cancels the notification
            return false;
        });
    }

    /**
     * Change the config of this request: every mail goes to the log.
     * Called after the mail settings of the system setting page are applied.
     */
    public static function applyRuntimeConfig(): void
    {
        if (!static::enabled()) {
            return;
        }
        Config::set('mail.default', 'log');
        // a Laravel 6 style config/mail.php: its "driver" wins over "default"
        if (!is_nullorempty(Config::get('mail.driver'))) {
            Config::set('mail.driver', 'log');
        }
    }

    // Requests ---------------------------------------------------------------

    /**
     * Whether demo mode refuses this request.
     */
    public static function isBlockedRequest(Request $request): bool
    {
        if (!static::enabled()) {
            return false;
        }

        $route = $request->route();
        if (!$route instanceof Route) {
            return false;
        }
        $class = $route->getControllerClass();
        if (!is_string($class)) {
            return false;
        }
        $class = ltrim($class, '\\');
        $method = $route->getActionMethod();

        $methods = static::BLOCKED_ACTIONS[$class] ?? [];
        if (in_array('*', $methods, true) || in_array($method, $methods, true)) {
            return true;
        }

        return static::changesOwnLogin($class, $method, $request);
    }

    /**
     * The user's own setting page (auth/setting) changes the password, or the user code or
     * e-mail address when the user table lets users edit them.
     */
    protected static function changesOwnLogin(string $class, string $method, Request $request): bool
    {
        if ($class !== Controllers\AuthController::class || $method !== 'putSetting') {
            return false;
        }
        if ($request->filled('password')) {
            return true;
        }

        $login_user = \Exment::user();
        $base_user = $login_user ? $login_user->base_user : null;
        if (!$base_user instanceof CustomValue) {
            return false;
        }
        foreach (static::LOGIN_ID_COLUMNS as $column) {
            $key = "base_user.value.{$column}";
            if (!$request->has($key)) {
                continue;
            }
            if (strval($request->input($key)) !== strval(array_get($base_user->value, $column))) {
                return true;
            }
        }
        return false;
    }

    /**
     * The answer to a refused request.
     * API and ajax: 403 json. Web page: back to the page before, with the message.
     */
    public static function refuse(Request $request): Response
    {
        $message = static::message();

        if (static::expectsJson($request)) {
            return response()->json([
                'result' => false,
                'status' => false,
                'code' => ErrorCode::DEMO_MODE()->getValue(),
                'message' => $message,
                'toastr' => $message,
                'reload' => false,
            ], 403);
        }

        admin_toastr($message, 'error');

        // a page opened again by its own url must not redirect to itself
        $to = url()->previous();
        if (is_nullorempty($to) || $to === $request->fullUrl() || $to === $request->url()) {
            $to = admin_url('/');
        }

        // keep the input of the form, without passwords and uploaded files
        $input = array_except($request->input(), ['_token', '_method', 'password', 'password_confirmation', 'current_password']);

        return redirect($to)
            ->withInput($input)
            ->withErrors(['demo_mode' => $message]);
    }

    /**
     * Whether the request is answered as json: the APIs and ajax requests.
     */
    public static function expectsJson(Request $request): bool
    {
        if ($request->pjax()) {
            return false;
        }
        if ($request->ajax() || $request->expectsJson()) {
            return true;
        }

        $prefix = trim(strval(config('admin.route.prefix')), '/');
        foreach (['api', 'webapi', 'oauth'] as $segment) {
            $path = trim($prefix . '/' . $segment, '/');
            if ($request->is($path, $path . '/*')) {
                return true;
            }
        }
        return false;
    }

    public static function message(): string
    {
        return strval(exmtrans('demo_mode.message_blocked'));
    }

    /**
     * The banner shown on every admin page.
     */
    public static function bannerText(): string
    {
        return strval(exmtrans('demo_mode.banner'));
    }

    // System settings ----------------------------------------------------------

    /**
     * Remove the locked settings from the values to save.
     *
     * @param array<mixed> $inputs
     * @return array<mixed>
     */
    public static function filterSystemInputs(array $inputs): array
    {
        if (!static::enabled()) {
            return $inputs;
        }
        return array_except($inputs, static::LOCKED_SYSTEM_KEYS);
    }

    /**
     * Hide the secrets from the values shown on the setting page.
     *
     * @param array<mixed> $values
     * @return array<mixed>
     */
    public static function maskSystemValues(array $values): array
    {
        if (!static::enabled()) {
            return $values;
        }
        foreach (static::SECRET_SYSTEM_KEYS as $key) {
            if (array_key_exists($key, $values)) {
                $values[$key] = null;
            }
        }
        return $values;
    }

    /**
     * Whether the system administrators (system setting page) are kept as they are.
     */
    public static function keepsSystemAdmins(): bool
    {
        return static::enabled();
    }

    /**
     * Whether a system menu item is hidden.
     *
     * @param mixed $uri
     */
    public static function hidesMenuUri($uri): bool
    {
        return static::enabled() && in_array($uri, static::HIDDEN_MENU_URIS, true);
    }

    // Public forms ------------------------------------------------------------------

    /**
     * Before a public form is saved: put back the custom CSS and JavaScript it had,
     * so no visitor can store a script that runs for the next visitors.
     */
    public static function keepPublicFormScripts(PublicForm $public_form): void
    {
        if (!static::enabled()) {
            return;
        }

        $original = $public_form->exists ? json_decode(strval($public_form->getRawOriginal('options')), true) : [];
        if (!is_array($original)) {
            $original = [];
        }
        foreach (static::LOCKED_PUBLIC_FORM_OPTIONS as $key) {
            $old = array_get($original, $key);
            if (is_nullorempty($old)) {
                $public_form->forgetOption($key);
            } else {
                $public_form->setOption($key, $old);
            }
        }
    }

    // Users -----------------------------------------------------------------------

    /**
     * Validation errors when the user code or e-mail address of a user who can log in is changed.
     * Called by the validation of data (web form, API, import).
     *
     * @param CustomTable $custom_table
     * @param mixed $input
     * @param mixed $custom_value the saved record, null on create
     * @param array<string, mixed> $options column_name_prefix: prefix of the error key, addValue: whether the input has "value."
     * @return array<string, array<int, string>>
     */
    public static function userLoginErrors(CustomTable $custom_table, $input, $custom_value, array $options = []): array
    {
        if (!static::enabled() || $custom_table->table_name !== SystemTableName::USER) {
            return [];
        }
        if (!$custom_value instanceof CustomValue || !$custom_value->exists || !static::hasLogin($custom_value->id)) {
            return [];
        }

        // compare with the saved values, the model can already hold the new ones
        $original = json_decode(strval($custom_value->getRawOriginal('value')), true);
        if (!is_array($original)) {
            $original = [];
        }

        $valuePrefix = boolval(array_get($options, 'addValue', true)) ? 'value.' : '';
        $errorPrefix = strval(array_get($options, 'column_name_prefix'));

        $errors = [];
        foreach (static::LOGIN_ID_COLUMNS as $column) {
            if (!array_has($input, $valuePrefix . $column)) {
                continue;
            }
            if (strval(array_get($input, $valuePrefix . $column)) === strval(array_get($original, $column))) {
                continue;
            }
            $errors[$errorPrefix . $column] = [strval(exmtrans('demo_mode.message_login_id'))];
        }
        return $errors;
    }

    /**
     * The answer of validateValueDestroy() when a user who can log in would be deleted.
     *
     * @param CustomTable $custom_table
     * @param array<mixed> $ids
     * @return array{status: false, message: string}|null
     */
    public static function userDestroyError(CustomTable $custom_table, array $ids): ?array
    {
        if (!static::enabled() || $custom_table->table_name !== SystemTableName::USER) {
            return null;
        }
        if (!LoginUser::whereIn('base_user_id', $ids)->exists()) {
            return null;
        }
        return [
            'status' => false,
            'message' => strval(exmtrans('demo_mode.message_user_delete')),
        ];
    }

    /**
     * @param mixed $user_id
     */
    protected static function hasLogin($user_id): bool
    {
        return LoginUser::where('base_user_id', $user_id)->exists();
    }

    // Outbound --------------------------------------------------------------------

    /**
     * Whether a notification channel may send in demo mode. Mail goes to the log
     * (applyRuntimeConfig()) and the navbar notification stays in the database.
     *
     * @param mixed $channel
     */
    public static function allowsNotificationChannel($channel): bool
    {
        if (!static::enabled()) {
            return true;
        }
        return in_array($channel, [MailChannel::class, NavbarChannel::class, 'mail', 'database'], true);
    }

    /**
     * Whether a message to an outside service (LINE) may be sent. If not, it is written to the log.
     *
     * @param array<string, mixed> $context
     */
    public static function allowsOutboundMessage(string $service, array $context = []): bool
    {
        if (!static::enabled()) {
            return true;
        }
        Log::info("Demo mode: a {$service} message was not sent.", $context);
        return false;
    }
}
