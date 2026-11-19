<?php

namespace Exceedone\Exment\Services\Login\Ldap;

use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SsoLoginErrorType;
use Exceedone\Exment\Services\Login\LoginServiceInterface;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Validator\ExmentCustomValidator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * LoginService
 *
 * LDAP is reached through directorytree/ldaprecord (composer require directorytree/ldaprecord).
 * adldap2, used up to v6, is abandoned and cannot be installed with Laravel 12.
 */
class LdapService implements LoginServiceInterface
{
    public const SCHEMA_ACTIVE_DIRECTORY = 'ActiveDirectory';
    public const SCHEMA_OPEN_LDAP = 'OpenLDAP';

    /**
     * Whether the LDAP library is installed.
     */
    public static function isLibraryInstalled(): bool
    {
        return class_exists(\LdapRecord\Connection::class);
    }

    /**
     * Get the schema of the setting: ActiveDirectory (default) or OpenLDAP.
     */
    public static function getSchema(LoginSetting $login_setting): string
    {
        $schema = $login_setting->getOption('ldap_schema');
        return (empty($schema) || $schema == static::SCHEMA_ACTIVE_DIRECTORY) ? static::SCHEMA_ACTIVE_DIRECTORY : static::SCHEMA_OPEN_LDAP;
    }

    /**
     * Checking retrieveByCredential.
     * (1) Login using LDAP
     * (2) Get user info
     * (3) sync from exment column name
     * (4) Validation value
     * (5) get login_user info. If not exists, create user (if set setting).
     * return login_user
     * @param array $credentials
     * @return ?LoginUser
     * if null, not match ldap user. Showing wrong ID or password not match.
     *
     * @throws SsoLoginErrorException
     */
    // @phpstan-ignore-next-line
    public static function retrieveByCredential(array $credentials)
    {
        list($result, $message, $adminMessage, $custom_login_user) = static::loginCallback(request(), array_get($credentials, 'login_setting') ?? LoginSetting::getLdapSetting(array_get($credentials, 'provider_name')));

        if ($result === true) {
            return LoginService::executeLogin(request(), $custom_login_user);
        }

        // if not exists, retun null
        if ($result == SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER) {
            return null;
        }

        // else, throw exception
        throw new SsoLoginErrorException($result, $message, $adminMessage);
    }


    /**
     * Validate Credential. Check password.
     *
     * @param Authenticatable $login_user
     * @param array $credentials
     * @return boolean
     */
    // @phpstan-ignore-next-line
    public static function validateCredential(Authenticatable $login_user, array $credentials)
    {
        // always true.
        return true;
    }


    /**
     * Get the LdapRecord connection config of the setting.
     *
     * The screen keeps the adldap2 names: "use SSL" is ldaps:// (LdapRecord use_tls) and
     * "use TLS" is STARTTLS (LdapRecord use_starttls).
     *
     * @return array<string, mixed>
     */
    public static function getLdapConfig(LoginSetting $login_setting)
    {
        return [
            'hosts' => stringToArray($login_setting->getOption('ldap_hosts')),
            'port' => (int) ($login_setting->getOption('ldap_port') ?? 389),
            'timeout' => 10,
            'base_dn' => (string) $login_setting->getOption('ldap_base_dn'),
            // anonymous bind until the user is bound, as before
            'username' => null,
            'password' => null,
            'follow_referrals' => false,
            'use_tls' => boolval($login_setting->getOption('ldap_use_ssl')),
            'use_starttls' => boolval($login_setting->getOption('ldap_use_tls')),
        ];
    }

    /**
     * Make the connection of the setting.
     *
     * @return \LdapRecord\Connection
     */
    public static function newConnection(LoginSetting $login_setting)
    {
        return new \LdapRecord\Connection(static::getLdapConfig($login_setting));
    }

    /**
     * Get login user name. append prefix and suffix.
     *
     * @param string $username User input name
     * @param LoginSetting $login_setting
     * @return string
     */
    public static function getLdapUserName($username, LoginSetting $login_setting): string
    {
        $prefix = $login_setting->getOption('ldap_account_prefix');
        $suffix = $login_setting->getOption('ldap_account_suffix');

        return ((isset($prefix) && strpos($username, $prefix) !== 0) ? $prefix : '') .
            $username .
            ((isset($suffix) && strripos($username, $suffix) !== (mb_strlen($username) - mb_strlen($suffix))) ? $suffix : '');
    }

    /**
     * Get login user dn.
     *
     * @param \LdapRecord\Connection $connection
     * @param string $username
     * @param LoginSetting $login_setting
     * @return string|null null: not found
     */
    public static function getLdapUserDN($connection, $username, LoginSetting $login_setting): ?string
    {
        $query = $connection->query();
        static::applyRawFilter($query, $login_setting->getOption('ldap_filter'));

        $ldapUser = static::findEntry($query, $login_setting, $username);
        return isset($ldapUser) && $ldapUser->getDn() !== '' ? $ldapUser->getDn() : null;
    }

    /**
     * Sync user from ldap
     *
     * @param \LdapRecord\Connection $connection
     * @param LoginSetting $login_setting
     * @param string $username
     * @return LdapEntry|null
     */
    public static function syncLdapUser($connection, LoginSetting $login_setting, $username)
    {
        $query = $connection->query();
        $schema = static::getSchema($login_setting);
        if ($schema != static::SCHEMA_ACTIVE_DIRECTORY && !empty($login_setting->getOption('ldap_filter'))) {
            static::applyRawFilter($query, $login_setting->getOption('ldap_filter'));
        } elseif ($schema == static::SCHEMA_ACTIVE_DIRECTORY) {
            // users of Active Directory (adldap2 search()->users())
            $query->where('objectclass', '=', 'user')
                ->where('objectcategory', '=', 'person')
                ->where('objectclass', '!=', 'contact');
        } else {
            // users of OpenLDAP (adldap2 search()->users() with the OpenLDAP schema)
            $query->where('objectclass', '=', 'inetorgperson')
                ->where('objectclass', '=', 'person');
        }

        return static::findEntry($query, $login_setting, $username);
    }

    /**
     * Add the filter of the setting, if any.
     *
     * @param \LdapRecord\Query\Builder $query
     * @param mixed $filter
     */
    protected static function applyRawFilter($query, $filter): void
    {
        if (is_string($filter) && $filter !== '') {
            $query->rawFilter($filter);
        }
    }

    /**
     * Find the entry whose search key is the user name.
     *
     * @param \LdapRecord\Query\Builder $query
     */
    protected static function findEntry($query, LoginSetting $login_setting, string $username): ?LdapEntry
    {
        $entry = $query->findBy((string) $login_setting->getOption('ldap_search_key'), $username);

        return empty($entry) ? null : new LdapEntry($entry, static::getSchema($login_setting));
    }

    /**
     * @param LoginSetting $login_setting
     * @return ModalForm
     */
    public static function getTestForm(LoginSetting $login_setting)
    {
        $form = new ModalForm(System::get_system_values());
        $form->action(route('exment.logintest_form', ['id' => $login_setting->id]));
        $form->disableReset();

        $form->descriptionHtml(exmtrans('login.message.login_test_description'));

        $form->text('username', trans('admin.username'))->required();
        $form->password('password', trans('admin.password'))->required();

        $form->textarea('resultarea', exmtrans('common.execute_result'))
            ->attribute(['readonly' => true])
            ->rows(4);

        $form->setWidth(10, 2);


        return $form;
    }



    // @phpstan-ignore-next-line
    public static function setLdapForm($form, $login_setting, $errors)
    {
        if (array_has($errors, LoginType::LDAP)) {
            $form->descriptionHtml($errors[LoginType::LDAP])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
            return;
        }


        if (!isset($login_setting)) {
            $form->text('ldap_name', exmtrans('login.ldap_name'))
                ->help(sprintf(exmtrans('common.help.max_length'), 30) . exmtrans('common.help_code'))
                ->required()
                ->rules(["max:30", "regex:/" . Define::RULES_REGEX_SYSTEM_NAME . "/", new \Exceedone\Exment\Validator\SamlNameUniqueRule()])
                ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        } else {
            $form->display('ldap_name_text', exmtrans('login.ldap_name'))->default(function () use ($login_setting) {
                return $login_setting->getOption('ldap_name');
            });
            $form->internal('ldap_name');
        }

        $form->exmheader(exmtrans('login.ldap_setting'))->hr()
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);
        $form->select('ldap_schema', "スキーマ")->options(["OpenLDAP" => "OpenLDAP", "ActiveDirectory" => "ActiveDirectory"])
            ->required()
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])])
            ->default('ActiveDirectory');
        $form->text('ldap_hosts', exmtrans('login.ldap_hosts'))
            ->required()
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_port', exmtrans('login.ldap_port'))
            ->required()
            ->rules('numeric|nullable')
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_base_dn', exmtrans('login.ldap_base_dn'))
            ->help(exmtrans('login.help.ldap_base_dn'))
            ->default('dc=example,dc=co,dc=jp')
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_search_key', exmtrans('login.ldap_search_key'))
            ->help(exmtrans('login.help.ldap_search_key'))
            ->required()
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_filter', exmtrans('login.ldap_filter'))
            ->help(exmtrans('login.help.ldap_filter'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_account_prefix', exmtrans('login.ldap_account_prefix'))
            ->help(exmtrans('login.help.ldap_account_prefix'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->text('ldap_account_suffix', exmtrans('login.ldap_account_suffix'))
            ->help(exmtrans('login.help.ldap_account_suffix'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])]);

        $form->switchbool('ldap_use_ssl', exmtrans('login.ldap_use_ssl'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])])
            ->default(false);

        $form->switchbool('ldap_use_tls', exmtrans('login.ldap_use_tls'))
            ->attribute(['data-filter' => json_encode(['key' => 'login_type', 'parent' => 1, 'value' => [LoginType::LDAP]])])
            ->default(false);
    }

    /**
     * Execute login test
     *
     * @param Request $request
     * @param mixed $login_setting
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function loginTest(Request $request, $login_setting)
    {
        list($result, $message, $adminMessage, $custom_login_user) = static::loginCallback($request, $login_setting);

        return getAjaxResponse([
            'result' => $result === true,
            'reload' => false,
            'keepModal' => true,
            'messages' => [
                'resultarea' => [
                    'type' => 'input',
                    'message' => $adminMessage,
                ],
            ],
        ]);
    }

    /**
     * Get login result and message.
     *
     * @param Request $request
     * @param LoginSetting $login_setting
     * @return array<mixed> $result(bool), $message(string), $adminMessage(string), $custom_login_user
     */
    protected static function loginCallback(Request $request, $login_setting)
    {
        $credentials = $request->only(['username', 'password']);

        $username = static::getLdapUserName($credentials['username'], $login_setting);

        $custom_login_user = null;
        try {

            // attempt to ldap
            $connection = static::newConnection($login_setting);
            $connection->connect();

            $bindDN = static::getSchema($login_setting) == static::SCHEMA_ACTIVE_DIRECTORY ?
                $username :
                static::getLdapUserDN($connection, $credentials['username'], $login_setting);
            if (!$bindDN || !$connection->auth()->attempt($bindDN, (string) $credentials['password'], true)) {
                // @phpstan-ignore-next-line
                return LoginService::getLoginResult(SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER, [exmtrans('error.login_failed')]);
            }

            // sync to exment
            $ldapUser = static::syncLdapUser($connection, $login_setting, $username);

            if (!$ldapUser) {
                // @phpstan-ignore-next-line
                return LoginService::getLoginResult(SsoLoginErrorType::NOT_EXISTS_PROVIDER_USER, [exmtrans('error.login_failed')]);
            }

            // mapping to loginuser
            $custom_login_user = LdapUser::with($login_setting, $ldapUser);

            if (!is_nullorempty($custom_login_user->mapping_errors)) {
                // @phpstan-ignore-next-line
                return LoginService::getLoginResult(SsoLoginErrorType::SYNC_MAPPING_ERROR, exmtrans('login.sso_provider_error'), $custom_login_user->mapping_errors);
            }

            /** @var ExmentCustomValidator $validator */
            $validator = LoginService::validateCustomLoginSync($custom_login_user);
            if ($validator->fails()) {
                return LoginService::getLoginResult(
                    // @phpstan-ignore-next-line
                    SsoLoginErrorType::SYNC_VALIDATION_ERROR,
                    exmtrans('login.sso_provider_error_validate', ['errors' => implode(' ', $validator->getMessageStrings())]),
                    // @phpstan-ignore-next-line
                    $validator->errors(),
                    $custom_login_user
                );
            }

            return LoginService::getLoginResult(true, [], [], $custom_login_user);
        } catch (\LdapRecord\Auth\BindException|\LdapRecord\ConnectionException $ex) {
            // cannot reach / bind to the server
            \Log::error($ex);
            // @phpstan-ignore-next-line
            return LoginService::getLoginResult(SsoLoginErrorType::PROVIDER_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        } catch (\Exception $ex) {
            \Log::error($ex);
            // @phpstan-ignore-next-line
            return LoginService::getLoginResult(SsoLoginErrorType::UNDEFINED_ERROR, exmtrans('login.sso_provider_error'), [$ex]);
        }
    }

    // @phpstan-ignore-next-line
    public static function appendActivateSwalButton($tools, LoginSetting $login_setting)
    {
        return LoginService::appendActivateSwalButtonSso($tools, $login_setting);
    }
}
