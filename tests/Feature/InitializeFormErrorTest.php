<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Services\Installer\InitializeForm;
use Illuminate\Support\Facades\DB;

/**
 * Tests for install-step error when DB connection fails.
 *
 * Scenario: .env has no EXMENT_INITIALIZE=1, DB connection is wrong.
 * User completes install wizard and clicks "initialize data" on the final step.
 *
 * Bug A: InitializeForm::post() — \DB::beginTransaction() is OUTSIDE the try block.
 *        If DB fails at beginTransaction → unhandled QueryException → 500.
 *        Even if inside the try, catch block has no return → returns null → PHP/Laravel error.
 *
 * Bug B: LoginUserProvider::retrieveById() — no try-catch around LoginUser::find().
 *        When DB fails → QueryException propagates through SessionGuard → AuthenticateWebApi → 500.
 *        (This is the exact stack trace shown in storage/logs/laravel.log)
 */
class InitializeFormErrorTest extends FeatureTestBase
{
    /**
     * Bug A: When DB throws at \DB::beginTransaction() (which is OUTSIDE the try block),
     * InitializeForm::post() must NOT let the exception propagate uncaught.
     * It must return a proper RedirectResponse with an error message.
     *
     * BEFORE FIX: RuntimeException propagates unhandled → test fails at $this->fail()
     * AFTER FIX:  exception is caught, returns RedirectResponse → test passes
     */
    public function testInitializeFormDbExceptionMustReturnRedirectNotPropagate(): void
    {
        // Mock \DB::beginTransaction() to throw (simulates SQLSTATE[HY000][2054] or connection fail)
        DB::shouldReceive('beginTransaction')
            ->once()
            ->andThrow(new \RuntimeException('SQLSTATE[HY000] [2054] The server requested authentication method unknown to the client'));
        // rollback may or may not be called depending on fix location; allow it to be called
        DB::shouldReceive('rollback')->zeroOrMoreTimes()->andReturn(null);

        // Bind a fake POST request (same data as the initialize form)
        $request = \Illuminate\Http\Request::create('/admin/initialize', 'POST', [
            'user_code'             => 'admin',
            'user_name'             => 'Admin User',
            'email'                 => 'admin@example.com',
            'password'              => 'Admin1234!',
            'password_confirmation' => 'Admin1234!',
            'site_name'             => 'Test Site',
            'site_name_short'       => 'Test',
        ]);
        app()->instance('request', $request);

        $form = new InitializeForm();

        try {
            $response = $form->post();

            // ---- AFTER FIX: execution reaches here ----
            $this->assertNotNull(
                $response,
                'InitializeForm::post() must NOT return null when DB fails'
            );
            $this->assertInstanceOf(
                \Illuminate\Http\RedirectResponse::class,
                $response,
                'InitializeForm::post() should return a RedirectResponse on DB error'
            );
        } catch (\Exception $e) {
            // ---- BEFORE FIX: exception propagates here → test fails ----
            $this->fail(
                'InitializeForm::post() must not throw exception when DB fails. ' .
                'Got ' . get_class($e) . ': ' . $e->getMessage()
            );
        }
    }

    /**
     * Bug B: LoginUserProvider::retrieveById() must return null (not throw)
     * when the DB query fails — matching the exact error in storage/logs/laravel.log:
     *   SQLSTATE[HY000] [2054] The server requested authentication method unknown to the client
     *   at LoginUserProvider.php(29): LoginUser::find(1)
     *
     * BEFORE FIX: QueryException propagates → AuthenticateWebApi crashes → 500
     * AFTER FIX:  returns null → Auth::check() returns false → 401 response
     */
    public function testLoginUserProviderDbFailMustReturnNullNotThrow(): void
    {
        // Force DB connection to fail by pointing to a port that is not in use.
        // Port 39999 is not a standard service port and should be unreachable,
        // causing PDO to throw "Connection refused" instantly (no timeout).
        $defaultConn  = config('database.default');
        $originalPort = config("database.connections.{$defaultConn}.port");

        config(["database.connections.{$defaultConn}.port" => 39999]);
        DB::purge($defaultConn);

        try {
            $provider = new LoginUserProvider(app('hash'), LoginUser::class);

            try {
                $result = $provider->retrieveById(1);

                // ---- AFTER FIX: null returned, no exception ----
                $this->assertNull(
                    $result,
                    'LoginUserProvider::retrieveById() must return null when DB fails, not throw'
                );
            } catch (\Exception $e) {
                // ---- BEFORE FIX: QueryException or PDOException propagates here ----
                $this->fail(
                    'LoginUserProvider::retrieveById() must not throw exception when DB fails. ' .
                    'Got ' . get_class($e) . ': ' . $e->getMessage()
                );
            }
        } finally {
            // Restore original DB config so subsequent tests are unaffected
            config(["database.connections.{$defaultConn}.port" => $originalPort]);
            DB::purge($defaultConn);
        }
    }
}
