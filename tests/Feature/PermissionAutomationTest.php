<?php

namespace Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\Permission;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Hash;

class PermissionAutomationTest extends TestCase
{
    protected $adminLoginUser;
    
    // Original system flags
    protected $originalApiAvailable;
    protected $originalPermissionAvailable;
    
    // Test data
    protected $customTable;
    protected $userA;
    protected $userB;
    protected $baseUserAId;
    protected $baseUserBId;
    protected $roleGroupA;
    protected $roleGroupB;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->adminLoginUser = LoginUser::find(1);
        if (!$this->adminLoginUser) {
            $this->markTestSkipped('Admin User not found to run setup.');
        }

        // Save original system flags
        $this->originalApiAvailable = System::api_available();
        $this->originalPermissionAvailable = System::permission_available();

        // Enable system flags for permission & WebAPI
        System::api_available(true);
        System::permission_available(true);

        // Clear cache
        System::clearCache();
    }

    public function test_rbac_security_flow()
    {
        $timestamp = date('YmdHis');

        // ---------------------------------------------------------
        // SETUP
        // ---------------------------------------------------------
        $this->actingAs($this->adminLoginUser, 'admin');
        \Exment::setGuard('admin');

        // 1. Find or create Unified table
        $reusedTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if ($reusedTable) {
            $this->customTable = $reusedTable;
            $tableName = $reusedTable->table_name;
        } else {
            $tableName = 'uni_table_' . $timestamp;
            $this->customTable = CustomTable::create([
                'table_name' => $tableName,
                'table_view_name' => 'Unified Table ' . $timestamp,
            ]);
            
            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_name',
                'column_view_name' => 'Test Name',
                'column_type' => 'text',
            ]);
            
            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_price',
                'column_view_name' => 'Test Price',
                'column_type' => 'integer',
            ]);
        }
        
        // Disable "all_user_*" flags to apply RBAC permissions on this table
        $this->customTable->update([
            'options' => array_merge($this->customTable->options ?? [], [
                'all_user_editable_flg' => '0',
                'all_user_viewable_flg' => '0',
                'all_user_accessable_flg' => '0',
            ])
        ]);
        
        System::clearCache();

        // 2. Create User A and User B (Base User -> Login User)
        $userTable = CustomTable::getEloquent('user');
        
        $baseUserA = $userTable->getValueModel();
        $baseUserA->setValue('name', 'User A ' . $timestamp);
        $baseUserA->save();
        $this->baseUserAId = $baseUserA->id;
        
        $this->userA = LoginUser::create([
            'password' => Hash::make('password123'),
            'base_user_id' => $this->baseUserAId,
        ]);

        $baseUserB = $userTable->getValueModel();
        $baseUserB->setValue('name', 'User B ' . $timestamp);
        $baseUserB->save();
        $this->baseUserBId = $baseUserB->id;

        $this->userB = LoginUser::create([
            'password' => Hash::make('password123'),
            'base_user_id' => $this->baseUserBId,
        ]);

        // 3. Create Role Group A and Role Group B
        $this->roleGroupA = RoleGroup::create([
            'role_group_name' => 'Role Group A ' . $timestamp,
            'role_group_view_name' => 'Group A ' . $timestamp,
        ]);

        $this->roleGroupB = RoleGroup::create([
            'role_group_name' => 'Role Group B ' . $timestamp,
            'role_group_view_name' => 'Group B ' . $timestamp,
        ]);

        // 4. Permissions: Grant CRUD permission on unified table to Group A
        RoleGroupPermission::create([
            'role_group_id' => $this->roleGroupA->id,
            'role_group_permission_type' => RoleType::TABLE,
            'role_group_target_id' => $this->customTable->id,
            'permissions' => [Permission::CUSTOM_VALUE_EDIT_ALL, Permission::CUSTOM_VALUE_VIEW_ALL],
        ]);
        // Group B has NO permission

        // 5. Assign Users to Role Groups
        RoleGroupUserOrganization::create([
            'role_group_id' => $this->roleGroupA->id,
            'role_group_user_org_type' => 'user',
            'role_group_target_id' => $this->baseUserAId,
        ]);

        RoleGroupUserOrganization::create([
            'role_group_id' => $this->roleGroupB->id,
            'role_group_user_org_type' => 'user',
            'role_group_target_id' => $this->baseUserBId,
        ]);

        System::clearCache();

        // ---------------------------------------------------------
        // STEP 1 - Authorized Access: Log in as User A and create a record
        // ---------------------------------------------------------
        // Logout Admin, log in as User A
        $this->actingAs($this->userA, 'admin');
        \Exment::setGuard('admin');
        System::clearCache(); // Clear cache to apply new session/roles

        $response = $this->postJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}",
            [
                'value' => [
                    'test_name' => 'This is a top secret by User A',
                    'test_price' => 777000,
                ]
            ]
        );

        $this->assertTrue(
            in_array($response->status(), [200, 201]),
            'User A failed to create record. Status: ' . $response->status() . ' Body: ' . $response->content()
        );

        $recordData = $response->json();
        $recordId = $recordData['id'] ?? null;
        $this->assertNotNull($recordId, 'Record ID should not be null after creation.');

        // ---------------------------------------------------------
        // STEP 2 - Unauthorized Read: Log in as User B and attempt to read User A's record
        // ---------------------------------------------------------
        $this->actingAs($this->userB, 'admin');
        \Exment::setGuard('admin');
        System::clearCache();

        $response = $this->getJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}/{$recordId}"
        );

        // Expect 403 Forbidden or 404 (due to Exment hiding records via query scope when unauthorized)
        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'User B should be blocked from reading. Status: ' . $response->status() . ' Body: ' . $response->content()
        );

        // ---------------------------------------------------------
        // STEP 3 - Unauthorized Update: User B attempts to modify data
        // ---------------------------------------------------------
        $response = $this->putJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}/{$recordId}",
            [
                'value' => [
                    'test_name' => 'Hacked by User B!',
                    'test_price' => 111,
                ]
            ]
        );

        $this->assertTrue(
            in_array($response->status(), [403, 404]),
            'User B should be blocked from updating. Status: ' . $response->status() . ' Body: ' . $response->content()
        );
        
        // Verify that DB data was not modified using Admin session
        $this->actingAs($this->adminLoginUser, 'admin');
        \Exment::setGuard('admin');
        System::clearCache();
        $dbRecord = CustomTable::getEloquent($tableName)->getValueModel($recordId);
        $this->assertNotNull($dbRecord);
        $this->assertEquals('This is a top secret by User A', $dbRecord->getValue('test_name'), 'Data was modified by unauthorized user!');
        $this->assertEquals(777000, $dbRecord->getValue('test_price'), 'Price was modified by unauthorized user!');
    }

    protected function tearDown(): void
    {
        // Restore original system flags
        if (isset($this->originalApiAvailable)) {
            System::api_available($this->originalApiAvailable);
        }
        if (isset($this->originalPermissionAvailable)) {
            System::permission_available($this->originalPermissionAvailable);
        }
        System::clearCache();

        parent::tearDown();
    }
}
