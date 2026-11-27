<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The request model cache (custom_value_value.*) is filled through the permission scope of the user that
 * was logged in at that moment. Nothing here clears it by hand: a change of the authenticated user has to do it.
 */
class ModelCacheUserIsolationTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const RESTRICTED = [
        TestDefine::TESTDATA_USER_LOGINID_DEV_USERB,
        TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC,
        TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD,
    ];

    protected const TABLES = [
        TestDefine::TESTDATA_TABLE_NAME_EDIT,
        TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL,
        TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST,
    ];

    protected function setUp(): void
    {
        parent::setUp();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function loginAs(string $loginId): void
    {
        $this->be(LoginUser::find($loginId));
    }

    protected function table(string $name): CustomTable
    {
        return CustomTable::getEloquent($name);
    }

    /** @return array<int, string> ids the current user can see, from an uncached query */
    protected function visibleIds(string $tableName): array
    {
        return $this->table($tableName)->getValueQuery()->orderBy('id')->pluck('id')->map(fn($id) => (string)$id)->all();
    }

    /** @return array<int, string> */
    protected function allIds(string $tableName): array
    {
        return $this->table($tableName)->getValueModel()->newQuery()->withoutGlobalScopes()->orderBy('id')->pluck('id')
            ->map(fn($id) => (string)$id)->all();
    }

    /** @return array<int, string> ids that getValueModel() answers with a record */
    protected function modelIds(string $tableName, array $ids): array
    {
        $table = $this->table($tableName);
        return array_values(array_filter($ids, fn($id) => !is_null($table->getValueModel($id))));
    }

    /**
     * @return array<int, array{string, string, array<int, string>}> [restricted login id, table, ids that user can see]
     */
    protected function restrictedCases(): array
    {
        $cases = [];
        foreach (static::RESTRICTED as $loginId) {
            foreach (static::TABLES as $tableName) {
                $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
                $all = $this->allIds($tableName);
                $this->loginAs($loginId);
                System::clearRequestSession();
                $visible = $this->visibleIds($tableName);
                if (count($visible) < count($all)) {
                    $cases[] = [$loginId, $tableName, $visible];
                }
            }
        }
        System::clearRequestSession();
        $this->assertNotEmpty($cases, 'the test data has no user who sees less than the administrator');
        return $cases;
    }

    public function testAdministratorCacheIsNotServedToARestrictedUser()
    {
        foreach ($this->restrictedCases() as [$loginId, $tableName, $visible]) {
            System::clearRequestSession();
            $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $all = $this->allIds($tableName);
            $this->assertSame($all, $this->modelIds($tableName, $all));

            $this->loginAs($loginId);
            $this->assertSame($visible, $this->modelIds($tableName, $all), "$loginId / $tableName");
        }
    }

    public function testBulkPreloadOfAnotherUserIsNotServedToARestrictedUser()
    {
        foreach ($this->restrictedCases() as [$loginId, $tableName, $visible]) {
            System::clearRequestSession();
            $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $all = $this->allIds($tableName);
            $this->table($tableName)->setCustomValueModels($all);

            $this->loginAs($loginId);
            $this->assertSame($visible, $this->modelIds($tableName, $all), "$loginId / $tableName");
        }
    }

    public function testDeniedLookupOfARestrictedUserIsNotServedToTheAdministrator()
    {
        foreach ($this->restrictedCases() as [$loginId, $tableName, $visible]) {
            System::clearRequestSession();
            $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $all = $this->allIds($tableName);

            $this->loginAs($loginId);
            $this->assertSame($visible, $this->modelIds($tableName, $all));

            $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $this->assertSame($all, $this->modelIds($tableName, $all), "$loginId / $tableName");
        }
    }

    public function testBackAndForthBetweenUsers()
    {
        [$loginId, $tableName, $visible] = $this->restrictedCases()[0];
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $all = $this->allIds($tableName);

        foreach ([TestDefine::TESTDATA_USER_LOGINID_ADMIN, $loginId, TestDefine::TESTDATA_USER_LOGINID_ADMIN, $loginId] as $user) {
            $this->loginAs($user);
            $expected = $user === $loginId ? $visible : $all;
            $this->assertSame($expected, $this->modelIds($tableName, $all), $user);
        }
    }

    public function testLogoutIsAUserChangeToo()
    {
        [$loginId, $tableName, $visible] = $this->restrictedCases()[0];
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $all = $this->allIds($tableName);

        $this->loginAs($loginId);
        $this->assertSame($visible, $this->modelIds($tableName, $all));

        // a guest is not filtered by the model scope (batch / login flow): the denied lookups of the user must be gone
        \Auth::logout();
        $this->assertSame($all, $this->modelIds($tableName, $all));
    }

    public function testTheSameUserKeepsItsCache()
    {
        [$loginId, $tableName] = $this->restrictedCases()[0];
        $this->loginAs($loginId);
        $all = $this->allIds($tableName);
        $this->modelIds($tableName, $all);

        $queries = 0;
        \DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->modelIds($tableName, $all);
        $this->assertSame(0, $queries, 'the cache of an unchanged user must still be used');
    }

    /** the sync connection runs a job inside the request: the request cache is kept, and a job that acts as another user must not read the cache of the request user */
    public function testSyncJobActingAsAnotherUserDoesNotReadTheRequestUsersCache()
    {
        [$loginId, $tableName, $visible] = $this->restrictedCases()[0];
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $all = $this->allIds($tableName);
        $this->assertSame($all, $this->modelIds($tableName, $all));

        ModelCacheProbeJob::$seen = null;
        ModelCacheProbeJob::dispatch($loginId, $tableName, $all)->onConnection('sync');
        $this->assertSame($visible, ModelCacheProbeJob::$seen, 'job as ' . $loginId);

        // and the request user, restored afterwards, gets his own answer again
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame($all, $this->modelIds($tableName, $all));
    }
}

class ModelCacheProbeJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    use \Illuminate\Foundation\Bus\Dispatchable;
    use \Illuminate\Queue\InteractsWithQueue;
    use \Illuminate\Bus\Queueable;

    /** @var array<int, string>|null */
    public static ?array $seen = null;

    /**
     * @param array<int, string> $ids
     */
    public function __construct(public string $loginId, public string $tableName, public array $ids)
    {
    }

    public function handle(): void
    {
        \Auth::guard()->login(LoginUser::find($this->loginId));
        $table = CustomTable::getEloquent($this->tableName);
        static::$seen = array_values(array_filter($this->ids, fn($id) => !is_null($table->getValueModel($id))));
    }
}
