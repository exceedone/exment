<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The comment API of a data:
 *   GET    api/data/{tableKey}/{id}/comments
 *   POST   api/data/{tableKey}/{id}/comments
 *   DELETE api/data/{tableKey}/{id}/comments/{suuid}
 * and the data screen, which saves and deletes a comment through the same code.
 */
class ApiCommentTest extends ApiTestBase
{
    use DatabaseTransactions;

    /** every user can edit the data of this table, and it has a notify fired by a comment */
    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testAddListAndDeleteAComment(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $custom_value = $this->createValue();

        $response = $this->api($token)->post($this->url($custom_value->id), ['comment' => "api comment\nsecond line"])
            ->assertStatus(201)
            ->assertJsonFragment([
                'comment' => "api comment\nsecond line",
                'created_user_id' => intval(TestDefine::TESTDATA_USER_LOGINID_ADMIN),
            ]);
        $suuid = $response->json('suuid');
        $this->assertNotEmpty($suuid);

        $comment = $this->findComment($suuid);
        $this->assertNotNull($comment);
        $this->assertSame(static::TABLE, $comment->parent_type);
        $this->assertEquals($custom_value->id, $comment->parent_id);
        $this->assertSame("api comment\nsecond line", $comment->getValue('comment_detail'));

        $this->api($token)->post($this->url($custom_value->id), ['comment' => 'api comment 2'])
            ->assertStatus(201);

        // the list: oldest first, paginated like the document api
        $list = $this->api($token)->get($this->url($custom_value->id))
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
        $this->assertSame($suuid, $list->json('data.0.suuid'));
        $this->assertSame('api comment 2', $list->json('data.1.comment'));
        $this->assertSame(2, $list->json('total'));

        $this->api($token)->get($this->url($custom_value->id) . '?count=1')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->api($token)->delete($this->url($custom_value->id, $suuid))
            ->assertStatus(204);
        $this->assertNull($this->findComment($suuid));

        // already deleted
        $this->api($token)->delete($this->url($custom_value->id, $suuid))
            ->assertStatus(404)
            ->assertJsonFragment(['code' => ErrorCode::DATA_NOT_FOUND]);
    }

    public function testACommentNotifies(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $custom_value = $this->createValue();

        $before = $this->countNavbar($custom_value);
        $this->api($token)->post($this->url($custom_value->id), ['comment' => 'api comment notifies'])
            ->assertStatus(201);

        // the table's notify (trigger "comment", notify_myself) tells the user who made the data
        $this->assertGreaterThan($before, $this->countNavbar($custom_value));
    }

    public function testAnEmptyCommentIsRefused(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $custom_value = $this->createValue();

        $this->api($token)->post($this->url($custom_value->id), ['comment' => ''])
            ->assertStatus(400)
            ->assertJsonFragment(['code' => ErrorCode::VALIDATION_ERROR]);
        $this->assertCount(0, $this->commentsOf(static::TABLE, $custom_value->id));
    }

    public function testADataThatDoesNotExist(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);

        $this->api($token)->get($this->url(99999999))->assertStatus(400);
        $this->api($token)->post($this->url(99999999), ['comment' => 'x'])->assertStatus(400);
    }

    public function testWrongScope(): void
    {
        $custom_value = $this->createValue();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $suuid = $custom_value->addComment('scope')->suuid;
        $this->forgetUser();

        // value_read can list, not write
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        $this->api($token)->get($this->url($custom_value->id))->assertStatus(200);
        $this->api($token)->post($this->url($custom_value->id), ['comment' => 'x'])
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::WRONG_SCOPE]);
        $this->api($token)->delete($this->url($custom_value->id, $suuid))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::WRONG_SCOPE]);
        $this->assertNotNull($this->findComment($suuid));

        // a scope other than the value's cannot list
        $token = $this->getAdminAccessToken([ApiScope::TABLE_READ]);
        $this->api($token)->get($this->url($custom_value->id))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::WRONG_SCOPE]);
    }

    public function testAUserWhoCannotViewTheData(): void
    {
        // user2 has no permission to this data of custom_value_edit
        $table = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $custom_value = CustomTable::getEloquent($table)->getValueModel(1);
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $suuid = $custom_value->addComment('not for user2')->suuid;
        $this->forgetUser();

        $token = $this->getUser2AccessToken([ApiScope::VALUE_WRITE]);
        $url = admin_urls('api', 'data', $table, 1, 'comments');

        $this->api($token)->get($url)
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);
        $this->api($token)->post($url, ['comment' => 'x'])
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);
        $this->api($token)->delete($url . '/' . $suuid)
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

        $this->assertNotNull($this->findComment($suuid));
        $this->assertCount(1, $this->commentsOf($table, 1));
    }

    public function testOnlyTheAuthorOrAnAdministratorDeletes(): void
    {
        $custom_value = $this->createValue();

        // written by the administrator
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $adminComment = $custom_value->addComment('by admin')->suuid;
        $this->forgetUser();

        // user1 can view the data, but did not write that comment
        $user1 = $this->getUser1AccessToken([ApiScope::VALUE_WRITE]);
        $this->api($user1)->delete($this->url($custom_value->id, $adminComment))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);
        $this->assertNotNull($this->findComment($adminComment));

        // user1 deletes a comment of his own
        $own = $this->api($user1)->post($this->url($custom_value->id), ['comment' => 'by user1'])
            ->assertStatus(201)
            ->assertJsonFragment(['created_user' => 'user1'])
            ->json('suuid');
        $this->api($user1)->delete($this->url($custom_value->id, $own))->assertStatus(204);
        $this->assertNull($this->findComment($own));

        // the administrator deletes a comment of user1
        $other = $this->api($user1)->post($this->url($custom_value->id), ['comment' => 'by user1 again'])
            ->assertStatus(201)
            ->json('suuid');
        $admin = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $this->api($admin)->delete($this->url($custom_value->id, $other))->assertStatus(204);
        $this->assertNull($this->findComment($other));
    }

    public function testACommentOfAnotherDataIsNotDeleted(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $custom_value = $this->createValue();
        $another = $this->createValue();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $suuid = $another->addComment('of another data')->suuid;
        $this->forgetUser();

        $this->api($token)->delete($this->url($custom_value->id, $suuid))->assertStatus(404);
        $this->assertNotNull($this->findComment($suuid));
    }

    public function testATableThatDoesNotUseComments(): void
    {
        $custom_value = $this->createValue();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $suuid = $custom_value->addComment('before the setting')->suuid;
        $this->forgetUser();

        $table = CustomTable::getEloquent(static::TABLE);
        $table->setOption('comment_flg', '0');
        $table->save();

        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE]);
        $this->api($token)->get($this->url($custom_value->id))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::FORM_ACTION_DISABLED]);
        $this->api($token)->post($this->url($custom_value->id), ['comment' => 'x'])
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::FORM_ACTION_DISABLED]);
        $this->api($token)->delete($this->url($custom_value->id, $suuid))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::FORM_ACTION_DISABLED]);

        $this->assertNotNull($this->findComment($suuid));
        $this->assertCount(1, $this->commentsOf(static::TABLE, $custom_value->id));
    }

    /**
     * The data screen deletes through the same code, so a request made by hand for a comment
     * of another user is refused there too (the trash icon was only hidden before).
     */
    public function testTheScreenRefusesToDeleteACommentOfAnotherUser(): void
    {
        $custom_value = $this->createValue();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $suuid = $custom_value->addComment('by admin')->suuid;

        $this->forgetUser();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $this->delete(admin_urls('data', static::TABLE, $custom_value->id, 'deletecomment', $suuid))
            ->assertJson(['result' => false]);
        $this->assertNotNull($this->findComment($suuid));

        $this->forgetUser();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->delete(admin_urls('data', static::TABLE, $custom_value->id, 'deletecomment', $suuid))
            ->assertOk()
            ->assertJson(['result' => true]);
        $this->assertNull($this->findComment($suuid));
    }

    // helpers ----------------------------------------------------

    /**
     * A request as the user of the token. The tests of one method call as several users, and
     * the guards keep the user of the request before, which production never does.
     *
     * @return $this
     */
    protected function api(string $token)
    {
        $this->forgetUser();

        return $this->withHeaders(['Authorization' => "Bearer $token"]);
    }

    protected function forgetUser(): void
    {
        $this->app['auth']->forgetGuards();
        System::clearRequestSession();
    }

    protected function url(string|int ...$paths): string
    {
        $id = array_shift($paths);

        return admin_urls('api', 'data', static::TABLE, $id, 'comments', ...$paths);
    }

    protected function createValue(): CustomValue
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $custom_value = CustomTable::getEloquent(static::TABLE)->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model for ' . static::TABLE);
        }
        $custom_value->setValue(['text' => 'api comment test']);
        $custom_value->save();
        $this->forgetUser();

        return $custom_value;
    }

    protected function findComment(string $suuid): ?CustomValue
    {
        return getModelName(SystemTableName::COMMENT)::withoutGlobalScopes()->where('suuid', $suuid)->whereNull('deleted_at')->first();
    }

    /**
     * @return \Illuminate\Support\Collection<int, CustomValue>
     */
    protected function commentsOf(string $table, int|string $id)
    {
        return getModelName(SystemTableName::COMMENT)::withoutGlobalScopes()
            ->where('parent_type', $table)
            ->where('parent_id', $id)
            ->whereNull('deleted_at')
            ->get();
    }

    protected function countNavbar(CustomValue $custom_value): int
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('parent_type', static::TABLE)
            ->where('parent_id', $custom_value->id)
            ->count();
    }
}
