<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * - The admin pages and the login page say that other sites may not frame them, and that the
 *   browser may not guess the type of what they send. A public form can still be embedded.
 * - A file with no record (an avatar, an image of the system, an upload not saved yet) is
 *   deleted only by the user who uploaded it, or by an administrator.
 */
class AdminHardeningTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testTheAdminPagesAreNotFramedByOtherSites(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $response = $this->get(admin_url(''));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testTheLoginPageIsNotFramedByOtherSites(): void
    {
        $response = $this->get(admin_url('auth/login'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testAPublicFormCanStillBeEmbedded(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $public_form = PublicForm::where('custom_form_id', CustomForm::getDefault($table)->id)->first();
        $this->assertNotNull($public_form);

        $response = $this->get($public_form->getUrl());
        $this->assertNull($response->headers->get('X-Frame-Options'));
    }

    protected function fileWithoutRecord(): File
    {
        $uuid = make_uuid();
        \DB::table('files')->insert([
            'uuid' => $uuid,
            'file_type' => 1,
            'local_dirname' => 'hardening',
            'local_filename' => $uuid . '.txt',
            'filename' => 'avatar.txt',
            'created_user_id' => LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id,
        ]);
        return File::where('uuid', $uuid)->firstOrFail();
    }

    public function testAnotherUserDoesNotDeleteAFileWithoutRecord(): void
    {
        $file = $this->fileWithoutRecord();

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        System::clearRequestSession();
        $this->delete(admin_urls('files', $file->uuid), [], static::AJAX);

        $this->assertNotNull(File::where('uuid', $file->uuid)->first());
    }

    public function testTheAdministratorStillDeletesAFileWithoutRecord(): void
    {
        $file = $this->fileWithoutRecord();

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
        $this->delete(admin_urls('files', $file->uuid), [], static::AJAX);

        $this->assertNull(File::where('uuid', $file->uuid)->first());
    }
}
