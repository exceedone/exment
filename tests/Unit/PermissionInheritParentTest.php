<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * inherit_parent_permission: a child record can be read by whoever can read
 * the record it hangs off.
 *
 * Nobody assigns each member of a project to each of its issues, so a table
 * can be told to take its permission from its parent instead - for reading
 * only, as the option says ("Viewable with Permissions of Parent data").
 * Writing a child still needs a grant on the child itself; editable_with_parent
 * can only add the parent as a further condition on top of that, never grant
 * anything. These tests pin both halves on a row the user was never given
 * directly - which is the only case the option exists for.
 *
 * Each test builds its own parent and child as the administrator, so the two
 * rows are shared with nobody else, and then decides what the user under test
 * is given on the parent.
 */
class PermissionInheritParentTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * Build a parent and a child nobody but the administrator was given, and
     * tell the child table to follow its parent.
     *
     * @param bool $grantChildEdit whether the general group may edit the child
     *                             table at all, which is a separate question
     *                             from which of its rows
     * @return array<mixed> [$parent, $child]
     */
    protected function prepare($grantChildEdit = true)
    {
        System::clearCache();
        System::clearRequestSession();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);

        $parent = $parent_table->getValueModel();
        $parent->setValue('text', 'inherit_parent_permission_test');
        $parent->save();

        $child = $child_table->getValueModel();
        $child->parent_id = $parent->id;
        $child->parent_type = $parent_table->table_name;
        $child->setValue('text', 'inherit_parent_permission_test_child');
        $child->save();

        $child_table->setOption('inherit_parent_permission', '1');
        $child_table->save();

        // The seeder already grants the general group custom_value_edit on
        // every table it makes, child_table included, so the "no grant" case
        // is the one that has to do something: take it away. The transaction
        // around the test puts it back.
        $permission = RoleGroupPermission::where('role_group_id', TestDefine::TESTDATA_ROLEGROUP_GENERAL)
            ->where('role_group_permission_type', RoleType::TABLE)
            ->where('role_group_target_id', $child_table->id)
            ->first();
        if (!$grantChildEdit) {
            if (isset($permission)) {
                $permission->delete();
            }
        } elseif (!isset($permission)) {
            $permission = new RoleGroupPermission();
            $permission->role_group_id = TestDefine::TESTDATA_ROLEGROUP_GENERAL;
            $permission->role_group_permission_type = RoleType::TABLE;
            $permission->role_group_target_id = $child_table->id;
            $permission->permissions = [Permission::CUSTOM_VALUE_EDIT];
            $permission->save();
        }

        return [$parent, $child];
    }

    /**
     * Share one record with the user under test, then become that user.
     *
     * @param mixed $custom_value
     * @param string|null $permission null shares nothing
     * @return void
     */
    protected function beUser2($custom_value = null, $permission = null)
    {
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);

        if (isset($custom_value) && isset($permission)) {
            \DB::table('custom_value_authoritables')->insert([
                'parent_id' => $custom_value->id,
                'parent_type' => $custom_value->custom_table->table_name,
                'authoritable_type' => $permission,
                'authoritable_user_org_type' => 'user',
                'authoritable_target_id' => $user->base_user_id,
                'created_at' => \Carbon\Carbon::now(),
                'updated_at' => \Carbon\Carbon::now(),
            ]);
        }

        // Permissions are memoised for the life of the request, so a change
        // made here is invisible until both the cache and that memo are gone.
        System::clearCache();
        System::clearRequestSession();
        $this->be($user);
    }

    /**
     * @param mixed $child
     * @return CustomTable
     */
    protected function childTable($child)
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
    }

    /**
     * Given nothing on the parent, the child is out of reach.
     *
     * @return void
     */
    public function testNoAccessWithoutTheParent()
    {
        list($parent, $child) = $this->prepare();
        $this->beUser2();

        $child_table = $this->childTable($child);
        $this->assertFalse($child_table->hasPermissionData($child->id), 'a child of a record nobody gave you should not be readable');
        $this->assertFalse($child_table->hasPermissionEditData($child->id), 'nor editable');
        $this->assertNull($child_table->getValueModel()->find($child->id), 'nor listed');
    }

    /**
     * Given the parent to read, the child can be read - and listed, which is
     * the half CustomValueModelScope answers.
     *
     * @return void
     */
    public function testReadInheritsFromTheParent()
    {
        list($parent, $child) = $this->prepare();
        $this->beUser2($parent, Permission::CUSTOM_VALUE_VIEW);

        $child_table = $this->childTable($child);
        $this->assertTrue($child_table->hasPermissionData($child->id), 'a child of a record you may read should be readable');
        $this->assertNotNull($child_table->getValueModel()->find($child->id), 'and the list should agree with that');
    }

    /**
     * Given the parent to read only, the child stays read-only.
     *
     * @return void
     */
    public function testWriteDoesNotInheritFromAReadableParent()
    {
        list($parent, $child) = $this->prepare();
        $this->beUser2($parent, Permission::CUSTOM_VALUE_VIEW);

        $child_table = $this->childTable($child);
        $this->assertTrue($child_table->hasPermissionData($child->id), 'readable');
        $this->assertFalse($child_table->hasPermissionEditData($child->id), 'but a reader of the parent must not be able to write the child');
    }

    /**
     * Given the parent to edit, the child can be read and listed but still not
     * written: the option lends reading, not writing, so a child somebody else
     * created is written by those it is shared with, not by everyone who may
     * edit its parent.
     *
     * @return void
     */
    public function testWriteDoesNotInheritFromAnEditableParent()
    {
        list($parent, $child) = $this->prepare();
        $this->beUser2($parent, Permission::CUSTOM_VALUE_EDIT);

        $child_table = $this->childTable($child);
        $this->assertTrue($child_table->hasPermissionData($child->id), 'readable');
        $this->assertNotNull($child_table->getValueModel()->find($child->id), 'and listed');
        $this->assertFalse($child_table->hasPermissionEditData($child->id), 'but an editor of the parent must not be able to write the child');

        $row = $child_table->getValueModel($child->id);
        $this->assertNotNull($row);
        $this->assertNotTrue($row->enableEdit(true), 'and the form action check should agree');
        $this->assertNotTrue($row->enableDelete(true), 'nor may it be deleted');

        // The control: a share of the child itself is what makes it writable,
        // so the refusals above come from the parent not lending writing, not
        // from this user being unable to write the child table at all.
        $this->beUser2($child, Permission::CUSTOM_VALUE_EDIT);
        $this->assertTrue($child_table->hasPermissionEditData($child->id), 'a share of the child itself does make it writable');
    }

    /**
     * The parent decides which rows can be seen, not whether the child table
     * may be used at all: with no grant on the child table, sharing the parent
     * - even for edit - neither lists the child nor makes it writable.
     *
     * @return void
     */
    public function testWriteNeedsAGrantOnTheChildTableToo()
    {
        list($parent, $child) = $this->prepare(false);
        $this->beUser2($parent, Permission::CUSTOM_VALUE_EDIT);

        $child_table = $this->childTable($child);
        $this->assertFalse($child_table->hasPermissionEditData($child->id), 'no grant on the child table means no write, however the parent is shared');
        $this->assertNull($child_table->getValueModel()->find($child->id), 'and the list shows nothing either');
    }

    /**
     * A blanket grant on the parent is the other condition under which
     * CustomValueModelScope shows an inherited row, and it lends what a share
     * of the parent lends - reading, not writing: every child is listed and
     * can be opened, none can be written without a grant on the child table.
     *
     * @return void
     */
    public function testWriteDoesNotInheritFromABlanketGrantOnTheParent()
    {
        list($parent, $child) = $this->prepare(false);

        // Swap the general group's grant on the parent for a blanket one, and
        // share nothing: custom_value_edit_all is about every row of the table,
        // so there is no share left to give.
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $permission = RoleGroupPermission::where('role_group_id', TestDefine::TESTDATA_ROLEGROUP_GENERAL)
            ->where('role_group_permission_type', RoleType::TABLE)
            ->where('role_group_target_id', $parent_table->id)
            ->first();
        if (!isset($permission)) {
            $permission = new RoleGroupPermission();
            $permission->role_group_id = TestDefine::TESTDATA_ROLEGROUP_GENERAL;
            $permission->role_group_permission_type = RoleType::TABLE;
            $permission->role_group_target_id = $parent_table->id;
        }
        $permission->permissions = [Permission::CUSTOM_VALUE_EDIT_ALL];
        $permission->save();

        $this->beUser2();

        $child_table = $this->childTable($child);
        $this->assertNotNull($child_table->getValueModel()->find($child->id), 'the list shows every child of a table the user may edit in full');
        $this->assertTrue($child_table->hasPermissionData($child->id), 'so the row is readable');
        $this->assertFalse($child_table->hasPermissionEditData($child->id), 'but reading is all the parent lends, not writing');
    }
}
