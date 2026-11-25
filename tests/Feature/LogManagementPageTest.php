<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The page of the log management (ISS02839): who sees it, the settings, the login records and
 * their CSV, the files of the application log.
 */
class LogManagementPageTest extends FeatureTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function loginAs($id): void
    {
        $this->be(LoginUser::findOrFail($id));
        System::clearRequestSession();
    }

    public function testTheSystemAdministratorSeesAndChangesIt(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        LoginLog::create(['result' => LoginLog::RESULT_FAILURE, 'failure_reason' => LoginLog::REASON_UNKNOWN_USER, 'login_name' => 'page-test-nobody', 'created_at' => now()]);

        $page = $this->get(admin_url('log_management'))->assertOk();
        $page->assertSee(exmtrans('log_management.types_header'));
        $page->assertSee(exmtrans('log_management.settings_header'));
        $page->assertSee('page-test-nobody');

        $this->post(admin_urls('log_management', 'setting'), [
            'log_retention_login_log' => '30',
            'log_retention_app_log' => '',
            'log_app_level' => 'warning',
            'log_app_daily' => '1',
            'operation_log_scope' => LogSettings::SCOPE_CHANGES,
        ])->assertRedirect();
        System::clearCache();
        $this->assertEquals(30, System::log_retention_login_log());
        $this->assertNull(System::log_retention_app_log());
        $this->assertSame('warning', System::log_app_level());
        $this->assertTrue(boolval(System::log_app_daily()));
        $this->assertSame(LogSettings::SCOPE_CHANGES, LogSettings::operationLogScope());
        $this->assertFalse(LogSettings::logsMethod('GET'));
        $this->assertTrue(LogSettings::logsMethod('POST'));

        // a wrong value is refused
        $this->post(admin_urls('log_management', 'setting'), ['operation_log_scope' => 'everything'])->assertSessionHasErrors('operation_log_scope');

        System::operation_log_scope(LogSettings::SCOPE_ALL);
        System::log_app_level(null);
        System::log_app_daily(false);
        System::log_retention_login_log(null);
    }

    public function testTheCsvOfTheLoginRecords(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        LoginLog::create(['result' => LoginLog::RESULT_FAILURE, 'login_name' => '=cmd|evil', 'created_at' => now()]);
        $response = $this->get(admin_urls_query('log_management', 'logins.csv', ['name' => 'cmd|evil']));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('created_at,result', $csv);
        $this->assertStringContainsString("'=cmd|evil", $csv, 'a cell does not start a formula');
    }

    public function testAFileOfTheApplicationLog(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $file = storage_path('logs/pagetest-2000-01-02.log');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, 'the log line');
        try {
            $this->get(admin_urls('log_management', 'files', 'pagetest-2000-01-02.log'))->assertOk();
            $this->get(admin_urls('log_management', 'files', 'nothing.log'))->assertNotFound();
            $this->get(admin_url('log_management/files/..%2F.env'))->assertNotFound();
        } finally {
            @unlink($file);
        }
    }

    public function testAUserWithoutAuditDoesNotSeeIt(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        // as ajax: the error page of a permission ends the process on a page request
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $page = $this->get(admin_url('log_management'), $ajax);
        $this->assertNotSame(200, $page->getStatusCode());
        $page->assertDontSee(exmtrans('log_management.types_header'));

        $this->post(admin_urls('log_management', 'setting'), ['operation_log_scope' => LogSettings::SCOPE_CHANGES], $ajax);
        System::clearCache();
        $this->assertSame(LogSettings::SCOPE_ALL, LogSettings::operationLogScope(), 'not changed by a user without the permission');
    }
}
