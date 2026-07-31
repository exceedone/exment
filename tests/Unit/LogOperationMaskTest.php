<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Middleware\LogOperation;

/**
 * Tests for the operation-log masking rules in LogOperation.
 *
 * Masking works on two axes:
 *   - getHideColumns():      key names masked on EVERY URI (password, ...)
 *   - getHideColumnsByUri(): key names masked only when the request URI
 *     matches (e.g. "id" is the oauth client_id only on api_setting screens;
 *     "client_id"/"client_secret" are credentials only on the oauth endpoints).
 * Additionally hidePathParams() masks sensitive path segments
 * (api_setting/{client_id}) before the path itself is logged.
 */
class LogOperationMaskTest extends TestCase
{
    /**
     * Mask a json input string the same way handle() does for a request on $path.
     */
    protected function mask(string $json, string $path): string
    {
        return json_encode(LogOperation::maskInputArray(json_decode($json, true), $path));
    }

    /**
     * Build a request path below the admin prefix ("admin/api_setting/x" etc.).
     */
    protected function adminPath(string $uri): string
    {
        return ltrim(admin_base_path($uri), '/');
    }

    // ----- global (URI independent) masking -------------------------------

    public function testPasswordIsMaskedOnSystemUri()
    {
        // "password" stays in the global list: it is posted on many system/auth
        // screens (login, profile, reset, token password grant, ...) and
        // inclusion-scoping it by URI would risk leaking a real password if one
        // screen was missed, so it is masked everywhere by default.
        $masked = $this->mask(
            '{"password":"pass123","name":"foo"}',
            $this->adminPath('auth/setting')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['password']);
        $this->assertSame('foo', $decoded['name']);
    }

    public function testPasswordStaysMaskedOnOauthTokenUri()
    {
        // grant_type=password posts a real password to the token endpoint;
        // the oauth URI is NOT a data URI, so it must stay masked.
        $masked = $this->mask(
            '{"grant_type":"password","username":"u","password":"real-pass"}',
            $this->adminPath('oauth/token')
        );
        $this->assertSame('***', json_decode($masked, true)['password']);
    }

    public function testPasswordIsMaskedOnEveryDataUriIncludingUserTable()
    {
        // REGRESSION: Exment user management is a system table at "data/user" and
        // posts a REAL login password. "password" must stay masked on every data
        // URI - it must NOT be treated as business data, or a real user password
        // leaks into the operation log.
        $userSave = $this->mask(
            '{"password":"adminadmin","password_confirmation":"adminadmin","value":{"user_code":"admin"}}',
            $this->adminPath('data/user/1')
        );
        $decoded = json_decode($userSave, true);
        $this->assertSame('***', $decoded['password']);
        $this->assertSame('***', $decoded['password_confirmation']);
        $this->assertSame('admin', $decoded['value']['user_code']);

        // a same-named business column is over-masked too - the safe trade-off
        $bizSave = $this->mask(
            '{"value":{"password":"biz-pass"}}',
            $this->adminPath('data/accounts')
        );
        $this->assertSame('***', json_decode($bizSave, true)['value']['password']);
    }

    public function testNestedSecretsAreMasked()
    {
        $masked = $this->mask(
            '{"client_api_key":{"key":"key_abcdef"},"value":{"password":"p"}}',
            $this->adminPath('api_setting/xxxx')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['client_api_key']);
        $this->assertSame('***', $decoded['value']['password']);
    }

    // ----- URI-scoped masking ---------------------------------------------

    public function testIdIsMaskedOnApiSettingUri()
    {
        $masked = $this->mask(
            '{"id":"5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f","name":"client"}',
            $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['id']);
        $this->assertSame('client', $decoded['name']);
    }

    public function testIdIsNotMaskedOnOtherUris()
    {
        $masked = $this->mask(
            '{"id":"12345"}',
            $this->adminPath('data/information')
        );
        $this->assertSame('12345', json_decode($masked, true)['id']);
    }

    public function testClientIdIsMaskedOnOauthTokenUri()
    {
        $masked = $this->mask(
            '{"grant_type":"client_credentials","client_id":"5e02b3a0-xxxx","client_secret":"sec"}',
            $this->adminPath('oauth/token')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['client_id']);
        $this->assertSame('***', $decoded['client_secret']);
        $this->assertSame('client_credentials', $decoded['grant_type']);
    }

    public function testBusinessClientIdColumnIsNotMasked()
    {
        // a user-defined table can have a plain "client_id" business column;
        // it must stay readable in the operation log (TC feedback (1))
        $masked = $this->mask(
            '{"value":{"client_id":"C-0001","client_name":"Sample Trading Co."}}',
            $this->adminPath('data/client')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('C-0001', $decoded['value']['client_id']);
        $this->assertSame('Sample Trading Co.', $decoded['value']['client_name']);
    }

    public function testSecretIsMaskedOnApiSettingUri()
    {
        // the api_setting edit form posts the client secret as "secret"
        $masked = $this->mask(
            '{"name":"client","secret":"real-secret"}',
            $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f')
        );
        $this->assertSame('***', json_decode($masked, true)['secret']);
    }

    public function testApiKeyIsMaskedOnOauthTokenUri()
    {
        // the token endpoint accepts grant_type=api_key with an "api_key" credential
        $masked = $this->mask(
            '{"grant_type":"api_key","client_id":"5e02b3a0-xxxx","client_secret":"sec","api_key":"key_abcdef"}',
            $this->adminPath('oauth/token')
        );
        $this->assertSame('***', json_decode($masked, true)['api_key']);
    }

    public function testBusinessCredentialColumnsAreNotMaskedOnDataUri()
    {
        // "secret"/"api_key"/"client_secret"/"client_api_key" are credentials only
        // on specific system URIs; same-named user-defined business columns must
        // stay readable on their data screen (TC feedback (1)). "password" is the
        // exception - it stays masked everywhere (see the user-table regression).
        $masked = $this->mask(
            '{"value":{"secret":"S-99","api_key":"AK-01","client_secret":"CS-1","client_api_key":"CAK-1","password":"p@ss"}}',
            $this->adminPath('data/client')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('S-99', $decoded['value']['secret']);
        $this->assertSame('AK-01', $decoded['value']['api_key']);
        $this->assertSame('CS-1', $decoded['value']['client_secret']);
        $this->assertSame('CAK-1', $decoded['value']['client_api_key']);
        $this->assertSame('***', $decoded['value']['password']);
    }

    public function testClientSecretIsMaskedOnlyOnOauthUri()
    {
        // client_secret is a credential on the oauth token endpoint...
        $onOauth = $this->mask('{"client_secret":"sec"}', $this->adminPath('oauth/token'));
        $this->assertSame('***', json_decode($onOauth, true)['client_secret']);

        // ...but a plain business column elsewhere
        $elsewhere = $this->mask('{"value":{"client_secret":"CS-9"}}', $this->adminPath('data/foo'));
        $this->assertSame('CS-9', json_decode($elsewhere, true)['value']['client_secret']);
    }

    public function testClientApiKeyIsMaskedOnlyOnApiSettingUri()
    {
        // client_api_key is posted nested by the api_setting form...
        $onApiSetting = $this->mask(
            '{"client_api_key":{"key":"real-key"}}',
            $this->adminPath('api_setting/xxxx')
        );
        $this->assertSame('***', json_decode($onApiSetting, true)['client_api_key']);

        // ...but a plain business column elsewhere
        $elsewhere = $this->mask('{"value":{"client_api_key":"CAK-9"}}', $this->adminPath('data/foo'));
        $this->assertSame('CAK-9', json_decode($elsewhere, true)['value']['client_api_key']);
    }

    public function testPreviousUrlPathSegmentIsMasked()
    {
        // laravel-admin posts the previous page url as "_previous_"
        $prev = 'http://localhost/' . $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f/edit');
        $masked = $this->mask(
            json_encode(['name' => 'client', '_previous_' => $prev]),
            $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f')
        );
        $this->assertSame(
            'http://localhost/' . $this->adminPath('api_setting/5e02b3a0***/edit'),
            json_decode($masked, true)['_previous_']
        );
    }

    public function testPreviousUrlIsMaskedWhenRelative()
    {
        // REGRESSION: the url was matched with a leading slash prepended, so a
        // relative "_previous_" ("admin/api_setting/{id}/edit") matched nothing
        // and was stored unmasked - a silent miss, no error raised.
        $rel = $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f/edit');
        $this->assertSame(
            $this->adminPath('api_setting/5e02b3a0***/edit'),
            LogOperation::hideUrlPathParams($rel)
        );
    }

    public function testUrlPathIsMaskedInQueryStringToo()
    {
        // the same url is often carried again as a "back" parameter
        $rel = $this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f/edit');
        $masked = LogOperation::hideUrlPathParams('http://localhost/' . $rel . '?back=/' . $rel);
        $this->assertStringNotContainsString('7a7a-11f1', $masked);
        $this->assertSame(
            'http://localhost/' . $this->adminPath('api_setting/5e02b3a0***/edit')
                . '?back=/' . $this->adminPath('api_setting/5e02b3a0***/edit'),
            $masked
        );
    }

    public function testUnrelatedUrlIsUntouched()
    {
        $url = 'http://localhost/' . $this->adminPath('data/client/123') . '?x=1';
        $this->assertSame($url, LogOperation::hideUrlPathParams($url));
    }

    // ----- path masking ------------------------------------------------------

    public function testPathClientIdSegmentIsPartiallyMasked()
    {
        $this->assertSame(
            $this->adminPath('api_setting/5e02b3a0***/edit'),
            LogOperation::hidePathParams($this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f/edit'))
        );
    }

    public function testPathShortIdSegmentIsFullyMasked()
    {
        $this->assertSame(
            $this->adminPath('api_setting/***'),
            LogOperation::hidePathParams($this->adminPath('api_setting/123'))
        );
    }

    public function testPathCreateSegmentIsNotMasked()
    {
        $path = $this->adminPath('api_setting/create');
        $this->assertSame($path, LogOperation::hidePathParams($path));
    }

    public function testPathResetTokenSegmentIsFullyMasked()
    {
        // auth/reset/{token} carries the raw password-reset token in the URL on
        // both GET (mail link) and POST (reset form action), and the route group
        // includes admin.log - so the token lands in the logged path. Unlike a
        // record id it is a bearer credential: no traceability prefix is kept.
        $token = str_repeat('a1b2c3d4', 8); // 64 chars, like a real reset token
        $masked = LogOperation::hidePathParams($this->adminPath('auth/reset/' . $token));
        $this->assertSame($this->adminPath('auth/reset/***'), $masked);
        $this->assertStringNotContainsString('a1b2c3d4', $masked);
        // idempotent - PatchDataCommand re-runs over already-masked rows
        $this->assertSame($masked, LogOperation::hidePathParams($masked));
    }

    public function testPathMaskingIsIdempotent()
    {
        $once = LogOperation::hidePathParams($this->adminPath('api_setting/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f'));
        $this->assertSame($once, LogOperation::hidePathParams($once));
    }

    public function testUnrelatedPathIsUntouched()
    {
        $path = $this->adminPath('data/client/123');
        $this->assertSame($path, LogOperation::hidePathParams($path));
    }

    // ----- config driven mask targets ----------------------------------------
    //
    // The mask targets live in config "exment.operation_log.*" so an install can
    // edit them without touching the source. Only requiredHideColumns() stays in
    // the class, as a safety net for a config/exment.php that is out of date (it
    // is published once per install and exment:update never refreshes it),
    // hand-edited or emptied.

    public function testDefaultConfigShipsTheBuiltInTargets()
    {
        // guards the package config block itself: everything below is owned by
        // config only, so a dropped or renamed block silently stops masking it
        $this->assertSame(
            ['id', 'secret', 'client_api_key'],
            LogOperation::getHideColumnsByUri()['api_setting*']
        );
        $this->assertSame(
            ['client_id', 'api_key', 'client_secret'],
            LogOperation::getHideColumnsByUri()['oauth/*']
        );
        foreach (['oauth_client_secret', 'saml_sp_privatekey', 'system_mail_password'] as $column) {
            $this->assertContains($column, LogOperation::getHideColumns());
        }
        $this->assertSame(
            $this->adminPath('api_setting/***'),
            LogOperation::hidePathParams($this->adminPath('api_setting/123'))
        );
        // the reset token url is the most damaging entry to lose - assert the
        // shipped config really carries it (see testPathResetTokenSegmentIsFullyMasked)
        $this->assertSame(
            $this->adminPath('auth/reset/***'),
            LogOperation::hidePathParams($this->adminPath('auth/reset/' . str_repeat('a1b2c3d4', 8)))
        );
    }

    public function testResetTokenFieldIsMaskedOnlyOnResetForm()
    {
        // auth/reset posts the raw reset token back as a hidden field "token"
        $onReset = $this->mask(
            '{"token":"raw-reset-token","password":"p"}',
            $this->adminPath('auth/reset/raw-reset-token')
        );
        $this->assertSame('***', json_decode($onReset, true)['token']);

        // ...but "token" is a plain business column name anywhere else, so it is
        // deliberately NOT in the global list
        $elsewhere = $this->mask('{"value":{"token":"T-1"}}', $this->adminPath('data/foo'));
        $this->assertSame('T-1', json_decode($elsewhere, true)['value']['token']);
    }

    public function testConfigAddsGlobalMaskColumn()
    {
        config(['exment.operation_log.mask_columns' => ['my_secret_key']]);
        $masked = $this->mask(
            '{"my_secret_key":"s3cret","other":"keep"}',
            $this->adminPath('data/foo')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['my_secret_key']);
        $this->assertSame('keep', $decoded['other']);
    }

    public function testConfigDefinesUriScopedColumns()
    {
        config(['exment.operation_log.mask_columns_by_uri' => ['data/my_table*' => ['secret_column']]]);
        $masked = $this->mask(
            '{"secret_column":"v","value":{"secret_column":"v2"}}',
            $this->adminPath('data/my_table/1/edit')
        );
        $decoded = json_decode($masked, true);
        $this->assertSame('***', $decoded['secret_column']);
        $this->assertSame('***', $decoded['value']['secret_column']);
        // ...and stays scoped to that uri
        $elsewhere = json_decode($this->mask('{"secret_column":"v"}', $this->adminPath('data/foo')), true);
        $this->assertSame('v', $elsewhere['secret_column']);
    }

    public function testConfigAddsNewPathPrefix()
    {
        config(['exment.operation_log.mask_path_prefixes' => ['my_page' => false]]);
        $this->assertSame(
            $this->adminPath('my_page/***'),
            LogOperation::hidePathParams($this->adminPath('my_page/5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f'))
        );
    }

    // ----- safety net: config cannot switch these off ------------------------

    public function testConfigCannotUnmaskSessionCredentials()
    {
        // an install that empties or rewrites the config must keep these masked
        config(['exment.operation_log.mask_columns' => []]);
        $decoded = json_decode(
            $this->mask(
                '{"password":"real","current_password":"real2","access_token":"t","verify_code":"123456"}',
                $this->adminPath('data/foo')
            ),
            true
        );
        foreach (['password', 'current_password', 'access_token', 'verify_code'] as $column) {
            $this->assertSame('***', $decoded[$column]);
        }
    }

    public function testMissingConfigStillMasksSessionCredentials()
    {
        // simulates a published config/exment.php from a version without the block:
        // only requiredHideColumns() survives - the uri and path rules are config
        // owned, so they are gone. Documented trade-off of keeping them editable.
        config(['exment.operation_log' => null]);
        $decoded = json_decode($this->mask('{"password":"real"}', $this->adminPath('data/foo')), true);
        $this->assertSame('***', $decoded['password']);
        $this->assertSame([], LogOperation::getHideColumnsByUri());
    }

    public function testMalformedConfigIsIgnored()
    {
        config([
            'exment.operation_log.mask_columns'        => 'not-an-array',
            'exment.operation_log.mask_columns_by_uri' => 'not-an-array',
            'exment.operation_log.mask_path_prefixes'  => 'not-an-array',
        ]);
        $decoded = json_decode($this->mask('{"password":"real"}', $this->adminPath('data/foo')), true);
        $this->assertSame('***', $decoded['password']);
        $this->assertSame([], LogOperation::getHideColumnsByUri());
        // non-string entries inside a valid array are dropped, not masked as keys
        config(['exment.operation_log.mask_columns' => ['ok_col', '', 123, ['nested']]]);
        $this->assertContains('ok_col', LogOperation::getHideColumns());
        foreach (LogOperation::getHideColumns() as $column) {
            $this->assertIsString($column);
        }
    }
}
