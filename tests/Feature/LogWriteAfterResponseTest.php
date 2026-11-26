<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Auth\Database\OperationLog;

/**
 * The operation log of a request is written after its response (ISS02903), the same row as when it
 * is written at once.
 */
class LogWriteAfterResponseTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($user);
        System::operation_log_scope(LogSettings::SCOPE_ALL);
        System::clearCache();
    }

    protected function tearDown(): void
    {
        config(['exment.log_write_mode' => LogWriter::MODE_AFTER_RESPONSE]);
        LogWriter::reset();
        parent::tearDown();
    }

    /**
     * @return array<string, mixed>
     */
    protected function request(string $mode): array
    {
        config(['exment.log_write_mode' => $mode]);
        $last = intval(OperationLog::query()->max('id'));
        $this->get(admin_urls_query('log_management', ['q' => 'x']))->assertOk();
        $this->assertSame(0, LogWriter::pendingCount(), 'written at the end of the request');
        $rows = OperationLog::query()->where('id', '>', $last)->get();
        $this->assertCount(1, $rows, $mode);
        return array_diff_key($rows->firstOrFail()->getAttributes(), ['id' => true, 'created_at' => true, 'updated_at' => true]);
    }

    public function testTheSameRowAfterTheResponse(): void
    {
        $sync = $this->request(LogWriter::MODE_SYNC);
        $after = $this->request(LogWriter::MODE_AFTER_RESPONSE);
        $this->assertSame($sync, $after);
        $this->assertSame('GET', $after['method']);
        $this->assertStringNotContainsString('"x"', strval($after['input']), 'the values stay hidden');
    }
}
