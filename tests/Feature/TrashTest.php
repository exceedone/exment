<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Events\CustomValueForceDeleted;
use Exceedone\Exment\Events\CustomValueRestored;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\TablePolicy;
use Exceedone\Exment\Services\Trash;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

/**
 * The trash (ISS00334): the deleted rows of all the tables the user may see the deleted rows
 * of, to restore them or delete them for good, with the permission of the list of each table;
 * and the events of a restore and a delete for good.
 *
 * The permissions of a table are set with a TablePolicy (it decides before the role groups),
 * so the tests do not depend on the role groups of the test data. Everything is rolled back.
 */
class TrashTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE_A = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
    protected const TABLE_B = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();
        config(['exment.features_locked' => '', 'exment.delete_force_custom_value' => false]);
        $settings = Features::settings();
        $settings[Trash::FEATURE] = ['enabled' => true, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    protected function tearDown(): void
    {
        TablePolicy::reset();
        Features::reset();
        System::clearRequestSession();
        parent::tearDown();
    }

    protected function login(string $loginId): void
    {
        $this->be(LoginUser::findOrFail($loginId));
        System::clearRequestSession();
        Features::reset();
    }

    protected function table(string $name): CustomTable
    {
        $table = CustomTable::getEloquent($name);
        $this->assertNotNull($table, "the test data has the table $name");
        return $table;
    }

    /** A row of the table, deleted (to the trash). */
    protected function trashed(string $tableName): CustomValue
    {
        $value = getModelName($this->table($tableName))::query()->orderBy('id')->first();
        $this->assertNotNull($value, "the test data has a row of $tableName");
        $value->delete();
        System::clearRequestSession();
        $value = getModelName($this->table($tableName))::withTrashed()->find($value->id);
        $this->assertTrue($value->trashed());
        return $value;
    }

    protected function item(CustomValue $value): string
    {
        return $value->custom_table->id . ':' . $value->id;
    }

    protected function find(CustomValue $value): ?CustomValue
    {
        System::clearRequestSession();
        return getModelName($value->custom_table)::withTrashed()->find($value->id);
    }

    /**
     * A table whose deleted rows may not be seen (but its rows may), or whose rows may not be
     * changed (but its deleted rows may be seen).
     */
    protected function policy(string $tableName, bool $trashed, bool $edit): void
    {
        TablePolicy::register($tableName, new class ($trashed, $edit) extends TablePolicy {
            public function __construct(protected bool $trashed, protected bool $edit)
            {
            }

            public function hasPermission(CustomTable $table, $role_key): ?bool
            {
                $keys = (array)$role_key;
                if (in_array(Permission::CUSTOM_VALUE_VIEW_TRASHED, $keys, true)) {
                    return $this->trashed ? null : false;
                }
                if (!$this->edit && ($keys == Permission::AVAILABLE_EDIT_CUSTOM_VALUE || $keys == Permission::AVAILABLE_ALL_EDIT_CUSTOM_VALUE)) {
                    return false;
                }
                return null;
            }

            public function hasPermissionEditData(CustomTable $table, $id): ?bool
            {
                return $this->edit ? null : false;
            }
        });
        System::clearRequestSession();
    }

    public function testTheListShowsOnlyTheTablesWhoseDeletedRowsTheUserMaySee(): void
    {
        $a = $this->trashed(static::TABLE_A);
        $b = $this->trashed(static::TABLE_B);
        $this->policy(static::TABLE_B, false, true);

        $tables = Trash::tables()->pluck('table_name')->all();
        $this->assertContains(static::TABLE_A, $tables);
        $this->assertNotContains(static::TABLE_B, $tables);

        $response = $this->get(admin_url('trash'));
        $response->assertStatus(200);
        $html = strval($response->getContent());
        $this->assertStringContainsString('data-trash-item="' . $this->item($a) . '"', $html);
        $this->assertStringNotContainsString('data-trash-item="' . $this->item($b) . '"', $html);
        $this->assertStringContainsString(e($a->getUrl()) . '?trashed=1', $html);

        // one table, the newest first: the row deleted last comes first
        $page = Trash::paginate(['table' => strval($a->custom_table->id)], 10);
        $this->assertGreaterThanOrEqual(1, $page->total());
        foreach ($page->items() as $item) {
            $this->assertSame(static::TABLE_A, $item['custom_table']->table_name);
        }
        $this->assertSame($a->id, $page->items()[0]['id']);

        // a row of another table is not restored from here
        $this->post(admin_url('trash/restore'), ['items' => [$this->item($b)]])->assertStatus(302);
        $this->assertTrue($this->find($b)->trashed());
    }

    public function testFilters(): void
    {
        $a = $this->trashed(static::TABLE_A);
        $id = strval($a->custom_table->id);
        $user = strval($a->deleted_user_id);

        $ids = function (array $filters) use ($id) {
            return collect(Trash::paginate($filters + ['table' => $id], 100)->items())->pluck('id')->all();
        };
        $this->assertContains($a->id, $ids(['user' => $user]));
        $this->assertNotContains($a->id, $ids(['user' => '999999']));
        $this->assertContains($a->id, $ids(['from' => now()->subDay()->format('Y-m-d'), 'to' => now()->addDay()->format('Y-m-d')]));
        $this->assertNotContains($a->id, $ids(['to' => now()->subDays(2)->format('Y-m-d')]));
    }

    public function testRestoreBringsBackTheRowAndItsChildren(): void
    {
        $childTable = $this->table(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $child = getModelName($childTable)::query()->where('parent_type', TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE)->whereNotNull('parent_id')->orderBy('id')->first();
        $this->assertNotNull($child, 'the test data has a child row');
        $parent = getModelName($this->table(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE))::find($child->parent_id);
        $this->assertNotNull($parent);

        $parent->delete();
        $this->assertTrue($this->find($parent)->trashed());
        $this->assertTrue($this->find($child)->trashed(), 'the child goes to the trash with its parent');

        // the child alone may not come back while its parent is in the trash
        $this->post(admin_url('trash/restore'), ['items' => [$this->item($child)]])->assertStatus(302);
        $this->assertTrue($this->find($child)->trashed());

        $this->post(admin_url('trash/restore'), ['items' => [$this->item($parent)]])->assertStatus(302);
        $this->assertFalse($this->find($parent)->trashed());
        $this->assertFalse($this->find($child)->trashed(), 'the child comes back with its parent');
        $this->assertNull($this->find($parent)->deleted_user_id);
    }

    public function testDeleteForGood(): void
    {
        $a = $this->trashed(static::TABLE_A);
        $a2 = getModelName($a->custom_table)::query()->orderBy('id')->first();
        $a2->delete();

        $this->post(admin_url('trash/delete'), ['items' => [$this->item($a), $this->item($a2)]])->assertStatus(302);
        $this->assertNull($this->find($a));
        $this->assertNull($this->find($a2));
    }

    public function testAUserWhoMayNotEditTheTableMayNotRestoreOrDelete(): void
    {
        $a = $this->trashed(static::TABLE_A);
        $this->policy(static::TABLE_A, true, false);

        // listed (the deleted rows may be seen), but not restored nor deleted
        $this->assertContains(static::TABLE_A, Trash::tables()->pluck('table_name')->all());
        $this->assertNotNull(Trash::denied($a->custom_table, $this->find($a)));

        $this->post(admin_url('trash/restore'), ['items' => [$this->item($a)]])->assertStatus(302);
        $this->assertTrue($this->find($a)->trashed());
        $this->post(admin_url('trash/delete'), ['items' => [$this->item($a)]])->assertStatus(302);
        $this->assertNotNull($this->find($a));
    }

    public function testTheEventsOfARestoreAndADeleteForGood(): void
    {
        Event::fake([CustomValueRestored::class, CustomValueForceDeleted::class]);

        $a = $this->trashed(static::TABLE_A);
        $a->restore();
        Event::assertDispatched(CustomValueRestored::class, function (CustomValueRestored $event) use ($a) {
            return $event->custom_value->id === $a->id && $event->custom_table->table_name === static::TABLE_A;
        });
        Event::assertNotDispatched(CustomValueForceDeleted::class);

        // a soft delete is not a delete for good
        $a->delete();
        Event::assertNotDispatched(CustomValueForceDeleted::class);

        $this->find($a)->forceDelete();
        Event::assertDispatched(CustomValueForceDeleted::class, function (CustomValueForceDeleted $event) use ($a) {
            return $event->custom_value->id === $a->id && $event->custom_table->table_name === static::TABLE_A;
        });
        $this->assertNull($this->find($a));
    }

    public function testTheFeatureAndTheMenu(): void
    {
        $this->assertTrue(Trash::available());
        $this->get(admin_url('trash'))->assertStatus(200);

        // off: the page is not there
        $settings = Features::settings();
        $settings[Trash::FEATURE] = ['enabled' => false, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
        $this->get(admin_url('trash'))->assertStatus(404);
    }
}
