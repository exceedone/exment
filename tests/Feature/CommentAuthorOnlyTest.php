<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;

/**
 * A comment is changed and deleted only by the user who wrote it, or by an administrator - also
 * through the data API (webapi/data/comment/{id}), not only by the delete button of the comment.
 *
 * user2 of the test data may edit every record of custom_value_edit_all.
 */
class CommentAuthorOnlyTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function actAs(string $login_id): void
    {
        $this->be(LoginUser::findOrFail($login_id));
        System::clearRequestSession();
    }

    /**
     * A comment the administrator wrote on a record user2 may edit.
     */
    protected function adminComment(): CustomValue
    {
        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $record = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel()->query()->first();
        $this->assertNotNull($record);

        $comment = CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel();
        $comment->parent_id = $record->id;
        $comment->parent_type = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
        $comment->setValue('comment_detail', 'written by the administrator');
        $comment->save();
        return $comment;
    }

    protected function stored(CustomValue $comment): ?CustomValue
    {
        return CustomTable::getEloquent(SystemTableName::COMMENT)->getValueModel()->query()
            ->withoutGlobalScopes()->where('id', $comment->id)->first();
    }

    public function testAnotherUserDoesNotRewriteTheComment(): void
    {
        $comment = $this->adminComment();

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->put(admin_urls('webapi', 'data', SystemTableName::COMMENT, $comment->id), [
            'value' => ['comment_detail' => 'rewritten by user2'],
        ], static::AJAX);

        $this->assertSame('written by the administrator', $this->stored($comment)->getValue('comment_detail'));
    }

    public function testAnotherUserDoesNotDeleteTheComment(): void
    {
        $comment = $this->adminComment();

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->delete(admin_urls('webapi', 'data', SystemTableName::COMMENT, $comment->id) . '?force=1', [], static::AJAX);

        $stored = $this->stored($comment);
        $this->assertNotNull($stored);
        $this->assertFalse($stored->trashed());
    }

    public function testTheAuthorStillDeletesTheComment(): void
    {
        $comment = $this->adminComment();

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->delete(admin_urls('webapi', 'data', SystemTableName::COMMENT, $comment->id), [], static::AJAX);

        $stored = $this->stored($comment);
        $this->assertTrue(!$stored || $stored->trashed());
    }
}
