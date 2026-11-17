<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A comment or a document is a row of its own table that hangs off a record of another table
 * (parent_type and parent_id). It is only for those who can access that record, whatever the
 * comment or document table itself allows: the list, one row, its update and its delete.
 *
 * The users of the test data:
 * - admin: the administrator.
 * - user2: may read and edit every record of custom_value_edit_all, and only the records of
 *   custom_value_edit that are shared with the user (the ones the user created).
 * - company2-userF: belongs to no role group, so reaches no record of either table.
 *
 * The calls are the screens' own ajax calls (admin/webapi/..., the logged-in session), which
 * the token API (admin/api/...) shares the controller with.
 *
 * Exment keeps the records and permissions it read in a static request cache
 * (System::requestSession), which a new PHP process empties on every request in production;
 * the tests empty it before each request and each change of user.
 *
 * Everything a test writes is rolled back.
 */
class ChildRowParentPermissionTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected const TABLE_ALL = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
    protected const TABLE_SHARED = TestDefine::TESTDATA_TABLE_NAME_EDIT;

    protected const USER_ADMIN = 1;
    protected const USER_USER2 = 3;
    /** company2-userF */
    protected const USER_NO_ROLE = 10;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // saving a record or a comment notifies the table's notify targets
        Notification::fake();
        $this->actAs(static::USER_ADMIN);
    }

    /**
     * Laravel keeps the controller it made for a route and hands it the next request of the
     * same route. Exment's controllers read the table from the request when they are made, so
     * a request for another table would be answered for the table of the request before. A new
     * PHP process serves every request in production; here every request gets new controllers.
     *
     * @param string $method
     * @param string $uri
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @param string|null $content
     * @return TestResponse
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
        System::clearRequestSession();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testTheFixtureUsersReachTheRecordsAsDescribed(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $private = $this->record(static::TABLE_SHARED, 'parent private');
        $this->actAs(static::USER_USER2);
        $own = $this->record(static::TABLE_SHARED, 'parent own');

        $this->actAs(static::USER_NO_ROLE);
        $this->getApi($this->dataPath(static::TABLE_ALL, $open))->assertStatus(403);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $private))->assertStatus(403);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $own))->assertStatus(403);

        $this->actAs(static::USER_USER2);
        $this->getApi($this->dataPath(static::TABLE_ALL, $open))->assertStatus(200);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $private))->assertStatus(403);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $own))->assertStatus(200);

        $this->actAs(static::USER_ADMIN);
        $this->getApi($this->dataPath(static::TABLE_ALL, $open))->assertStatus(200);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $private))->assertStatus(200);
        $this->getApi($this->dataPath(static::TABLE_SHARED, $own))->assertStatus(200);
    }

    public function testTheCommentListLeavesOutTheCommentsOfRecordsTheUserCannotAccess(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $private = $this->record(static::TABLE_SHARED, 'parent private');
        $this->actAs(static::USER_USER2);
        $own = $this->record(static::TABLE_SHARED, 'parent own');
        $this->actAs(static::USER_ADMIN);

        $onOpen = $this->comment($open, 'on open');
        $onPrivate = $this->comment($private, 'on private');
        $onOwn = $this->comment($own, 'on own');
        $parentless = $this->comment(null, 'parentless');
        // the record it was written on does not exist (any more)
        $orphan = $this->attach(SystemTableName::COMMENT, static::TABLE_ALL, $this->missingId(static::TABLE_ALL), ['comment_detail' => 'orphan']);

        $all = [$onOpen, $onPrivate, $onOwn, $parentless, $orphan];
        $path = $this->listPath(SystemTableName::COMMENT, $all);

        // no role group: neither record is reachable. What has no record to follow stays as it was.
        $this->actAs(static::USER_NO_ROLE);
        $this->assertSame(
            $this->idsOf([$parentless, $orphan]),
            $this->listedIds($this->getApi($path)->assertStatus(200))
        );

        // user2: every record of the one table, only the own record of the other
        $this->actAs(static::USER_USER2);
        $this->assertSame(
            $this->idsOf([$onOpen, $onOwn, $parentless, $orphan]),
            $this->listedIds($this->getApi($path)->assertStatus(200))
        );

        $this->actAs(static::USER_ADMIN);
        $this->assertSame(
            $this->idsOf($all),
            $this->listedIds($this->getApi($path)->assertStatus(200))
        );
    }

    public function testTheCommentListWithoutAFilterDoesNotShowThemEither(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $onOpen = $this->comment($open, 'on open');
        $parentless = $this->comment(null, 'parentless');

        // newest first, so both rows are on the first page whatever else the table holds
        $path = 'data/' . SystemTableName::COMMENT . '?count=100&orderby=' . urlencode('id desc');

        $this->actAs(static::USER_NO_ROLE);
        $ids = $this->listedIds($this->getApi($path)->assertStatus(200));
        $this->assertNotContains(intval($onOpen->id), $ids);
        $this->assertContains(intval($parentless->id), $ids);

        $this->actAs(static::USER_USER2);
        $ids = $this->listedIds($this->getApi($path)->assertStatus(200));
        $this->assertContains(intval($onOpen->id), $ids);
        $this->assertContains(intval($parentless->id), $ids);
    }

    public function testACommentOfARecordTheUserCannotAccessIsNotReadUpdatedOrDeleted(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $private = $this->record(static::TABLE_SHARED, 'parent private');
        $onOpen = $this->comment($open, 'on open');
        $onPrivate = $this->comment($private, 'on private');

        // [user, comment]: no role group at all, and a role that does not reach this record
        $cases = [
            [static::USER_NO_ROLE, $onOpen, 'on open'],
            [static::USER_NO_ROLE, $onPrivate, 'on private'],
            [static::USER_USER2, $onPrivate, 'on private'],
        ];
        foreach ($cases as list($user, $comment, $body)) {
            $this->actAs($user);
            $path = $this->dataPath(SystemTableName::COMMENT, $comment);

            $this->getApi($path)
                ->assertStatus(403)
                ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

            $this->putApi($path, ['value' => ['comment_detail' => 'TAMPERED']])
                ->assertStatus(403)
                ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

            $this->deleteApi($path)
                ->assertStatus(403)
                ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

            $stored = $this->stored($comment);
            $this->assertSame($body, $stored->getValue('comment_detail'), 'the comment is not changed');
            $this->assertFalse($stored->trashed(), 'the comment is not deleted');
        }
    }

    public function testACommentOfARecordTheUserCanAccessIsStillReadUpdatedAndDeleted(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $this->actAs(static::USER_USER2);
        $own = $this->record(static::TABLE_SHARED, 'parent own');
        $this->actAs(static::USER_ADMIN);

        // [user, record]
        $cases = [
            [static::USER_USER2, $open],
            [static::USER_USER2, $own],
            [static::USER_ADMIN, $open],
            [static::USER_ADMIN, $own],
        ];
        foreach ($cases as $index => list($user, $record)) {
            $this->actAs(static::USER_ADMIN);
            $comment = $this->comment($record, "body $index");

            $this->actAs($user);
            $path = $this->dataPath(SystemTableName::COMMENT, $comment);

            $this->getApi($path)
                ->assertStatus(200)
                ->assertJsonFragment(['id' => intval($comment->id)])
                ->assertJsonFragment(['comment_detail' => "body $index"]);

            $this->putApi($path, ['value' => ['comment_detail' => "updated $index"]])
                ->assertStatus(200)
                ->assertJsonFragment(['comment_detail' => "updated $index"]);
            $this->assertSame("updated $index", $this->stored($comment)->getValue('comment_detail'));

            $this->deleteApi($path)->assertStatus(204);
            $this->assertTrue($this->stored($comment)->trashed());
        }
    }

    public function testACommentWithoutARecordIsStillReadUpdatedAndDeleted(): void
    {
        $comment = $this->comment(null, 'parentless');

        $this->actAs(static::USER_NO_ROLE);
        $path = $this->dataPath(SystemTableName::COMMENT, $comment);

        $this->getApi($path)
            ->assertStatus(200)
            ->assertJsonFragment(['comment_detail' => 'parentless']);

        $this->putApi($path, ['value' => ['comment_detail' => 'parentless updated']])
            ->assertStatus(200);
        $this->assertSame('parentless updated', $this->stored($comment)->getValue('comment_detail'));

        $this->deleteApi($path)->assertStatus(204);
        $this->assertTrue($this->stored($comment)->trashed());
    }

    public function testACommentIsAddedFromTheRecordPageOnlyByThoseWhoCanAccessTheRecord(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $url = admin_urls('data', static::TABLE_ALL, $open->id, 'addcomment');

        $this->actAs(static::USER_NO_ROLE);
        System::clearRequestSession();
        $this->post($url, ['comment' => 'from no role'], static::AJAX)->assertStatus(403);
        $this->assertSame(0, $this->commentCount($open));

        $this->actAs(static::USER_USER2);
        System::clearRequestSession();
        $this->post($url, ['comment' => 'from user2'])
            ->assertRedirect(admin_urls('data', static::TABLE_ALL, $open->id));
        $this->assertSame(1, $this->commentCount($open));

        $this->actAs(static::USER_ADMIN);
        System::clearRequestSession();
        $this->post($url, ['comment' => 'from admin'])
            ->assertRedirect(admin_urls('data', static::TABLE_ALL, $open->id));
        $this->assertSame(2, $this->commentCount($open));
    }

    public function testTheRecordChecksOfACommentFollowTheRecordItHangsOff(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $onOpen = $this->comment($open, 'on open');
        $parentless = $this->comment(null, 'parentless');
        $orphan = $this->attach(SystemTableName::COMMENT, static::TABLE_ALL, $this->missingId(static::TABLE_ALL), ['comment_detail' => 'orphan']);

        // a row read without the user's scope, as a plugin or a batch would hand it over
        $this->actAs(static::USER_NO_ROLE);
        $row = $this->stored($onOpen);
        $this->assertTrue($row->isParentInaccessible());
        $this->assertEquals(ErrorCode::PERMISSION_DENY(), $row->enableAccess());
        $this->assertEquals(ErrorCode::PERMISSION_DENY(), $row->enableEdit());
        $this->assertEquals(ErrorCode::PERMISSION_DENY(), $row->enableDelete());

        foreach ([$parentless, $orphan] as $comment) {
            System::clearRequestSession();
            $row = $this->stored($comment);
            $this->assertFalse($row->isParentInaccessible());
            $this->assertTrue($row->enableAccess());
            $this->assertTrue($row->enableEdit());
            $this->assertTrue($row->enableDelete());
        }

        foreach ([static::USER_USER2, static::USER_ADMIN] as $user) {
            $this->actAs($user);
            $row = $this->stored($onOpen);
            $this->assertFalse($row->isParentInaccessible());
            $this->assertTrue($row->enableAccess());
            $this->assertTrue($row->enableEdit());
            $this->assertTrue($row->enableDelete());
        }
    }

    public function testTheDocumentListLeavesOutTheDocumentsOfRecordsTheUserCannotAccess(): void
    {
        $open = $this->record(static::TABLE_ALL, 'parent open');
        $private = $this->record(static::TABLE_SHARED, 'parent private');

        $onOpen = $this->document($open, 'open.txt');
        $onPrivate = $this->document($private, 'private.txt');
        $path = $this->listPath(SystemTableName::DOCUMENT, [$onOpen, $onPrivate]);

        $this->actAs(static::USER_NO_ROLE);
        $this->assertSame([], $this->listedIds($this->getApi($path)->assertStatus(200)));
        $this->getApi($this->dataPath(SystemTableName::DOCUMENT, $onOpen))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);
        $this->getApi($this->dataPath(SystemTableName::DOCUMENT, $onPrivate))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

        $this->actAs(static::USER_USER2);
        $this->assertSame($this->idsOf([$onOpen]), $this->listedIds($this->getApi($path)->assertStatus(200)));
        $this->getApi($this->dataPath(SystemTableName::DOCUMENT, $onPrivate))
            ->assertStatus(403)
            ->assertJsonFragment(['code' => ErrorCode::PERMISSION_DENY]);

        $this->actAs(static::USER_ADMIN);
        $this->assertSame($this->idsOf([$onOpen, $onPrivate]), $this->listedIds($this->getApi($path)->assertStatus(200)));
    }

    // helpers ----------------------------------------------------

    /**
     * Log in as the user (the id of the user record) and forget what the previous user read.
     */
    protected function actAs(int $base_user_id): void
    {
        $user = LoginUser::where('base_user_id', $base_user_id)->first();
        if (!$user instanceof LoginUser) {
            $this->fail("the test data has no login user for the user $base_user_id");
        }
        $this->be($user);
        System::clearRequestSession();
    }

    protected function table(string $table_name): CustomTable
    {
        $table = CustomTable::getEloquent($table_name);
        if (!$table instanceof CustomTable) {
            $this->fail("the test data has no $table_name table");
        }

        return $table;
    }

    protected function newRow(string $table_name): CustomValue
    {
        $row = $this->table($table_name)->getValueModel();
        if (!$row instanceof CustomValue) {
            $this->fail("no value model for $table_name");
        }

        return $row;
    }

    /**
     * A new record of the table, created by the user who is logged in.
     */
    protected function record(string $table_name, string $text): CustomValue
    {
        $row = $this->newRow($table_name);
        $row->setValue(['text' => $text]);
        $row->save();

        return $row;
    }

    /**
     * A row of a table whose rows hang off a record: a comment or a document.
     *
     * @param string|null $parent_type
     * @param int|null $parent_id
     * @param array<string, mixed> $value
     */
    protected function attach(string $table_name, ?string $parent_type, ?int $parent_id, array $value): CustomValue
    {
        $row = $this->newRow($table_name);
        $row->parent_type = $parent_type;
        $row->parent_id = $parent_id;
        $row->setValue($value);
        $row->save();

        return $row;
    }

    /**
     * A comment on the record, the way the record page saves it. Null: a comment on no record.
     */
    protected function comment(?CustomValue $record, string $body): CustomValue
    {
        return $this->attach(
            SystemTableName::COMMENT,
            $record ? strval($record->custom_table->table_name) : null,
            $record ? intval($record->id) : null,
            ['comment_detail' => $body]
        );
    }

    /**
     * The document row of an attachment of the record (the row only: no file is stored).
     */
    protected function document(CustomValue $record, string $name): CustomValue
    {
        return $this->attach(
            SystemTableName::DOCUMENT,
            strval($record->custom_table->table_name),
            intval($record->id),
            ['file_uuid' => make_uuid(), 'document_name' => $name]
        );
    }

    /**
     * An id no record of the table has, deleted ones included.
     */
    protected function missingId(string $table_name): int
    {
        return intval($this->newRow($table_name)->newQueryWithoutScopes()->max('id')) + 100000;
    }

    /**
     * The row as the database holds it now, whoever is logged in and deleted or not.
     */
    protected function stored(CustomValue $row): CustomValue
    {
        $stored = $row->newQueryWithoutScopes()->where('id', $row->id)->first();
        if (!$stored instanceof CustomValue) {
            $this->fail('the row is no longer in the database');
        }

        return $stored;
    }

    /**
     * The comments on the record that are not deleted, whoever is logged in.
     */
    protected function commentCount(CustomValue $record): int
    {
        return $this->newRow(SystemTableName::COMMENT)->newQueryWithoutScopes()
            ->where('parent_type', $record->custom_table->table_name)
            ->where('parent_id', $record->id)
            ->whereNull('deleted_at')
            ->count();
    }

    protected function dataPath(string $table_name, CustomValue $row): string
    {
        return 'data/' . $table_name . '/' . intval($row->id);
    }

    /**
     * The list of the table, narrowed to the given rows.
     *
     * @param array<int, CustomValue> $rows
     */
    protected function listPath(string $table_name, array $rows): string
    {
        return 'data/' . $table_name . '?count=100&id=' . implode(',', $this->idsOf($rows));
    }

    /**
     * @param array<int, CustomValue> $rows
     * @return array<int, int> ascending
     */
    protected function idsOf(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = intval($row->id);
        }
        sort($ids);

        return $ids;
    }

    /**
     * The ids of the rows a list call answered, ascending.
     *
     * @param TestResponse $response
     * @return array<int, int>
     */
    protected function listedIds(TestResponse $response): array
    {
        $list = $response->json('data');
        $this->assertIsArray($list);

        $ids = [];
        foreach ($list as $row) {
            $ids[] = is_array($row) ? intval($row['id'] ?? 0) : 0;
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return TestResponse
     */
    protected function getApi(string $path): TestResponse
    {
        System::clearRequestSession();

        return $this->get(admin_url('webapi/' . $path), static::AJAX);
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse
     */
    protected function putApi(string $path, array $data): TestResponse
    {
        System::clearRequestSession();

        return $this->put(admin_url('webapi/' . $path), $data, static::AJAX);
    }

    /**
     * @return TestResponse
     */
    protected function deleteApi(string $path): TestResponse
    {
        System::clearRequestSession();

        return $this->delete(admin_url('webapi/' . $path), [], static::AJAX);
    }
}
