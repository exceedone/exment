<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Exceedone\Exment\Middleware\LogOperation;
use Exceedone\Exment\Model\OperationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GUARD: masking must be reachable through the middleware, not only through its
 * static helpers.
 *
 * LogOperationMaskTest asserts the rules by calling maskInputArray() /
 * hidePathParams() directly. That leaves handle() itself uncovered: reverting
 * its two lines to the original
 *
 *     'path'  => substr($request->path(), 0, 255),
 *     'input' => json_encode($request->input()),
 *
 * writes every credential to admin_operation_log in plain text while the whole
 * mask suite stays green. This test drives real requests through handle() and
 * asserts on the row that actually reaches the table, so that revert fails here.
 *
 * It also pins the two customer requirements end-to-end, on the stored row:
 *   (1) a user-defined business column must NOT be masked just because its name
 *       looks sensitive (client_id / secret / api_key on a data screen);
 *   (2) TC-SYS-07-09 / TC-SYS-07-10: the oauth client_id is posted as "id" on
 *       api_setting screens and must be masked there - and only there, never as
 *       a blanket rule on every field named "id".
 */
class GuardOperationLogHandleAppliesMaskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();
        parent::tearDown();
    }

    /**
     * Build a request below the admin prefix, run it through the real
     * middleware, and return the operation-log row it wrote.
     *
     * @param array<mixed> $input
     */
    private function logged(string $uri, array $input = [], string $method = 'POST'): OperationLog
    {
        $path = ltrim(admin_base_path($uri), '/');
        $request = Request::create('http://localhost/' . $path, $method, $input);

        $maxId = OperationLog::max('id') ?? 0;

        $next = false;
        (new LogOperation())->handle($request, function () use (&$next) {
            $next = true;
            return 'next';
        });
        $this->assertTrue($next, 'handle() did not pass the request down the pipeline.');

        $log = OperationLog::where('id', '>', $maxId)->orderBy('id', 'desc')->first();
        $this->assertNotNull(
            $log,
            "No operation-log row was written for \"{$path}\". handle() swallows insert "
            . 'errors, so masking cannot be verified - check the db connection and that '
            . 'shouldLogOperation() still returns true for this request.'
        );

        return $log;
    }

    /**
     * @return array<mixed>
     */
    private function input(OperationLog $log): array
    {
        return json_decode($log->input, true) ?? [];
    }

    // ----- masking is actually wired into handle() ---------------------------

    public function testHandleMasksGlobalCredentialsInTheStoredRow()
    {
        $log = $this->logged('auth/setting', [
            'current_password' => 'RealPass@123',
            'password' => 'NewPass@456',
            'password_confirmation' => 'NewPass@456',
            '_token' => 'CsrfTokenXYZ',
            'user_name' => 'keep-me',
        ]);

        $input = $this->input($log);
        foreach (['current_password', 'password', 'password_confirmation', '_token'] as $key) {
            $this->assertSame('***', $input[$key], "\"{$key}\" reached admin_operation_log unmasked.");
        }
        $this->assertSame('keep-me', $input['user_name']);
        $this->assertStringNotContainsString('RealPass@123', $log->input);
    }

    public function testHandleMasksNestedCredentials()
    {
        // the core middleware only filters top-level keys, so a nested secret is
        // the clearest signal that Exment's recursive masking is the one running
        $log = $this->logged('data/user/1', [
            'value' => ['user_code' => 'admin', 'password' => 'nested-secret'],
        ]);

        $input = $this->input($log);
        $this->assertSame('***', $input['value']['password']);
        $this->assertSame('admin', $input['value']['user_code']);
    }

    public function testHandleMasksSensitivePathSegment()
    {
        $clientId = '5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f';
        $log = $this->logged('api_setting/' . $clientId . '/edit', [], 'GET');

        $this->assertSame(ltrim(admin_base_path('api_setting/5e02b3a0***/edit'), '/'), $log->path);
        $this->assertStringNotContainsString('7a7a-11f1', $log->path);
    }

    // ----- customer requirement (2): TC-SYS-07-09 / TC-SYS-07-10 -------------

    public function testOauthClientIdPostedAsIdIsMaskedOnApiSettingUri()
    {
        $log = $this->logged('api_setting', ['id' => '5e02b3a0-7a7a-11f1-bd22-0f4e2735fd7f'], 'GET');

        $this->assertSame('***', $this->input($log)['id']);
        $this->assertStringNotContainsString('5e02b3a0', $log->input);
    }

    public function testHandleMasksTheResetPasswordTokenInBothInputAndPath()
    {
        // The password-reset token is the one credential the removed core filter
        // (admin.operation_log.filter_input) used to mask that Exment now covers
        // from config alone - see GuardCoreFilterInputRemovedTest. It reaches the
        // log twice on the same request: as the hidden "token" field posted by
        // auth/reset.blade.php, and as the {token} segment of the form action.
        // Both are asserted here on the stored row, because a unit test on the
        // static helpers stays green even if this route stops being logged.
        $token = 'raw-reset-token-9f3a1c7b5d2e';
        $log = $this->logged('auth/reset/' . $token, [
            'token' => $token,
            'email' => 'user@example.com',
            'password' => 'NewPass@456',
            'password_confirmation' => 'NewPass@456',
        ]);

        $input = $this->input($log);
        $this->assertSame('***', $input['token'], 'The reset token reached admin_operation_log unmasked.');
        $this->assertSame('***', $input['password']);
        $this->assertSame('***', $input['password_confirmation']);

        // a bearer credential keeps no traceability prefix: the whole segment goes
        $this->assertSame(ltrim(admin_base_path('auth/reset/***'), '/'), $log->path);

        // belt and braces: the raw value must appear in neither column
        $this->assertStringNotContainsString($token, $log->input);
        $this->assertStringNotContainsString($token, $log->path);

        // the address is what makes the row useful for auditing - keep it readable
        $this->assertSame('user@example.com', $input['email']);
    }

    public function testIdIsNotMaskedOnDataUris()
    {
        // the rule must never degrade into "mask every field named id"
        $this->assertSame('12345', $this->input($this->logged('data/client', ['id' => '12345'], 'GET'))['id']);
        $this->assertSame('999', $this->input($this->logged('data/employee', ['id' => '999'], 'GET'))['id']);
    }

    // ----- customer requirement (1): user-defined columns stay readable ------

    public function testUserDefinedBusinessColumnsAreNotMaskedOnDataUri()
    {
        // the "client" table the customer described: columns whose names look
        // sensitive but hold plain business data
        $log = $this->logged('data/client', [
            'value' => [
                'client_id' => 'C-0001',
                'client_name' => 'Sample Trading Co.',
                'secret' => 'S-99',
                'api_key' => 'AK-01',
                'client_secret' => 'CS-1',
                'client_api_key' => 'CAK-1',
                'token' => 'T-1',
            ],
        ]);

        $value = $this->input($log)['value'];
        foreach (['client_id' => 'C-0001', 'client_name' => 'Sample Trading Co.', 'secret' => 'S-99',
            'api_key' => 'AK-01', 'client_secret' => 'CS-1', 'client_api_key' => 'CAK-1',
            'token' => 'T-1', ] as $column => $expected) {
            $this->assertSame(
                $expected,
                $value[$column],
                "Business column \"{$column}\" was masked on a data screen - it is only a "
                . 'credential on the system screen that owns it, so it must be uri-scoped.'
            );
        }
    }

    public function testSameNamesAreStillMaskedOnTheSystemScreensThatOwnThem()
    {
        // the other half of the trade-off: uri-scoping must not have unmasked the
        // real credentials on the screens where those names ARE credentials
        $apiSetting = $this->input($this->logged('api_setting/xxxx', [
            'secret' => 'real-secret',
            'client_api_key' => ['key' => 'real-key'],
        ]));
        $this->assertSame('***', $apiSetting['secret']);
        $this->assertSame('***', $apiSetting['client_api_key']);

        $oauth = $this->input($this->logged('oauth/token', [
            'grant_type' => 'client_credentials',
            'client_id' => '5e02b3a0-xxxx',
            'client_secret' => 'sec',
            'api_key' => 'key_abcdef',
        ]));
        foreach (['client_id', 'client_secret', 'api_key'] as $key) {
            $this->assertSame('***', $oauth[$key]);
        }
        $this->assertSame('client_credentials', $oauth['grant_type']);
    }
}
