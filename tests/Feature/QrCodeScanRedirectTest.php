<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\DataScanSubmitRedirect;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * GET admin/qr-code/{tableName}/{id} (QrCodeController::scanRedirect) is the URL a scanned QR
 * code opens. It renders no QR image and downloads nothing: an unknown table shows the error
 * page, a known one redirects to the record's edit page with a flag chosen by the table's
 * action_after_read option.
 */
class QrCodeScanRedirectTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testUnknownTableShowsTheNotFoundPage(): void
    {
        $this->get(admin_url('qr-code/claude_coverage_no_such_table/1'))
            ->assertStatus(200)
            ->assertSee(exmtrans('custom_table.qr_code.table_not_found'));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideActionAfterRead(): array
    {
        return [
            'continue editing' => [DataScanSubmitRedirect::CONTINUE_EDITING, '&after-save=1'],
            'view' => [DataScanSubmitRedirect::VIEW, '&after-save=3'],
            'list' => [DataScanSubmitRedirect::LIST, ''],
            'camera' => [DataScanSubmitRedirect::CAMERA, '&redirect-camera=1'],
            'top' => [DataScanSubmitRedirect::TOP, '&redirect-dashboard=1'],
        ];
    }

    /**
     * @dataProvider provideActionAfterRead
     */
    public function testKnownTableRedirectsToTheEditPage(string $actionAfterRead, string $suffix): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $table = CustomTable::getEloquent($tableKey);
        $this->assertNotNull($table);
        // form_after_read 0 = the table's default form
        $table->setOption('form_after_read', 0);
        $table->setOption('action_after_read', $actionAfterRead);
        $table->save();
        System::clearCache();

        $suuid = CustomForm::getDefault($table)->suuid;

        $this->get(admin_url("qr-code/{$tableKey}/1"))
            ->assertRedirect(admin_urls('data', $tableKey, 1, 'edit?formid=' . $suuid . $suffix));
    }
}
