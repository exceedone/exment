<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\JoinedOrgFilterType;

class PermissionTest extends TestCase
{
    protected function init(){
        System::clearCache();
    }

    public function testRoleGroupAdmin()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        $this->be(LoginUser::find(1));
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDirect()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        $this->be(LoginUser::find(6)); //dev-userB
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupUpper()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        $this->be(LoginUser::find(5)); //company1-userA
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDowner()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        $this->be(LoginUser::find(7)); //dev1-userC
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupOtherOrg()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        $this->be(LoginUser::find(10)); //company2-userF
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDirectOnlyUpper()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_UPPER);
        $this->be(LoginUser::find(6)); //dev-userB
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupUpperOnlyUpper()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_UPPER);
        $this->be(LoginUser::find(5)); //company1-userA
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDownerOnlyUpper()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_UPPER);
        $this->be(LoginUser::find(7)); //dev1-userC
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupOtherOrgOnlyUpper()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_UPPER);
        $this->be(LoginUser::find(10)); //company2-userF
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }
    
    public function testRoleGroupDirectOnlyDowner()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_DOWNER);
        $this->be(LoginUser::find(6)); //dev-userB
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupUpperOnlyDowner()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_DOWNER);
        $this->be(LoginUser::find(5)); //company1-userA
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDownerOnlyDowner()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_DOWNER);
        $this->be(LoginUser::find(7)); //dev1-userC
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupOtherOrgOnlyDowner()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_DOWNER);
        $this->be(LoginUser::find(10)); //company2-userF
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }
    
    public function testRoleGroupDirectOnlyJoin()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_JOIN);
        $this->be(LoginUser::find(6)); //dev-userB
        
        $this->assertTrue(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupUpperOnlyJoin()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_JOIN);
        $this->be(LoginUser::find(5)); //company1-userA
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupDownerOnlyJoin()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_JOIN);
        $this->be(LoginUser::find(7)); //dev1-userC
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    public function testRoleGroupOtherOrgOnlyJoin()
    {
        $this->init();
        System::org_joined_type_role_group(JoinedOrgFilterType::ONLY_JOIN);
        $this->be(LoginUser::find(10)); //company2-userF
        
        $this->assertFalse(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }
}
