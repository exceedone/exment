<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;

class PermissionTest extends UnitTestBase
{
    protected function init(){
        System::clearCache();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
    }

    // User - Organization -------------------------------------------
    public function testOrganizationCompany()
    {
        $this->executeTestOrganizationUser(5, JoinedOrgFilterType::ONLY_JOIN, [1], true);
    }

    public function testOrganizationDev()
    {
        $this->executeTestOrganizationUser(6, JoinedOrgFilterType::ONLY_JOIN, [2], true);
    }

    public function testOrganizationDev1()
    {
        $this->executeTestOrganizationUser(7, JoinedOrgFilterType::ONLY_JOIN, [4], true);
    }

    public function testOrganizationCompanyUpper()
    {
        $this->executeTestOrganizationUser(5, JoinedOrgFilterType::ONLY_UPPER, [1, 2, 3, 4, 5], true);
    }

    public function testOrganizationDevUpper()
    {
        $this->executeTestOrganizationUser(6, JoinedOrgFilterType::ONLY_UPPER, [2, 4, 5], true);
    }

    public function testOrganizationDev1Upper()
    {
        $this->executeTestOrganizationUser(7, JoinedOrgFilterType::ONLY_UPPER, [4], true);
    }

    public function testOrganizationCompanyDowner()
    {
        $this->executeTestOrganizationUser(5, JoinedOrgFilterType::ONLY_DOWNER, [1], true);
    }

    public function testOrganizationDevDowner()
    {
        $this->executeTestOrganizationUser(6, JoinedOrgFilterType::ONLY_DOWNER, [1, 2], true);
    }

    public function testOrganizationDev1Downer()
    {
        $this->executeTestOrganizationUser(7, JoinedOrgFilterType::ONLY_DOWNER, [1, 2, 4], true);
    }

    public function testOrganizationCompanyAll()
    {
        $this->executeTestOrganizationUser(5, JoinedOrgFilterType::ALL, [1, 2, 3, 4, 5], true);
    }

    public function testOrganizationDevAll()
    {
        $this->executeTestOrganizationUser(6, JoinedOrgFilterType::ALL, [1, 2, 4, 5], true);
    }

    public function testOrganizationDev1All()
    {
        $this->executeTestOrganizationUser(7, JoinedOrgFilterType::ALL, [1, 2, 4], true);
    }

    
    // Organization - Organization -------------------------------------------
    public function testOrganizationOrgCompany()
    {
        $this->executeTestOrganizationOrg(1, JoinedOrgFilterType::ONLY_JOIN, [1], true);
    }

    public function testOrganizationOrgDev()
    {
        $this->executeTestOrganizationOrg(2, JoinedOrgFilterType::ONLY_JOIN, [2], true);
    }

    public function testOrganizationOrgDev1()
    {
        $this->executeTestOrganizationOrg(3, JoinedOrgFilterType::ONLY_JOIN, [3], true);
    }

    public function testOrganizationOrgCompanyUpper()
    {
        $this->executeTestOrganizationOrg(1, JoinedOrgFilterType::ONLY_UPPER, [1, 2, 3, 4, 5], true);
    }

    public function testOrganizationOrgDevUpper()
    {
        $this->executeTestOrganizationOrg(2, JoinedOrgFilterType::ONLY_UPPER, [2, 4, 5], true);
    }

    public function testOrganizationOrgDev1Upper()
    {
        $this->executeTestOrganizationOrg(4, JoinedOrgFilterType::ONLY_UPPER, [4], true);
    }

    public function testOrganizationOrgCompanyDowner()
    {
        $this->executeTestOrganizationOrg(1, JoinedOrgFilterType::ONLY_DOWNER, [1], true);
    }

    public function testOrganizationOrgDevDowner()
    {
        $this->executeTestOrganizationOrg(2, JoinedOrgFilterType::ONLY_DOWNER, [1, 2], true);
    }

    public function testOrganizationOrgDev1Downer()
    {
        $this->executeTestOrganizationOrg(4, JoinedOrgFilterType::ONLY_DOWNER, [1, 2, 4], true);
    }

    public function testOrganizationOrgCompanyAll()
    {
        $this->executeTestOrganizationOrg(1, JoinedOrgFilterType::ALL, [1, 2, 3, 4, 5], true);
    }

    public function testOrganizationOrgDevAll()
    {
        $this->executeTestOrganizationOrg(2, JoinedOrgFilterType::ALL, [1, 2, 4, 5], true);
    }

    public function testOrganizationOrgDev1All()
    {
        $this->executeTestOrganizationOrg(4, JoinedOrgFilterType::ALL, [1, 2, 4], true);
    }


    // Role Group -------------------------------------------
    public function testRoleGroupAdmin()
    {
        $this->executeTestRoleGroup(1, JoinedOrgFilterType::ALL, true);
    }

    public function testRoleGroupDirect()
    {
        $this->executeTestRoleGroup(6, JoinedOrgFilterType::ALL, true);
    }

    public function testRoleGroupUpper()
    {
        $this->executeTestRoleGroup(5, JoinedOrgFilterType::ALL, true);
    }

    public function testRoleGroupDowner()
    {
        $this->executeTestRoleGroup(7, JoinedOrgFilterType::ALL, true);
    }

    public function testRoleGroupOtherOrg()
    {
        $this->executeTestRoleGroup(10, JoinedOrgFilterType::ALL, false);
    }

    public function testRoleGroupDirectOnlyUpper()
    {
        $this->executeTestRoleGroup(6, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testRoleGroupUpperOnlyUpper()
    {
        $this->executeTestRoleGroup(5, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testRoleGroupDownerOnlyUpper()
    {
        $this->executeTestRoleGroup(7, JoinedOrgFilterType::ONLY_UPPER, false);
    }

    public function testRoleGroupOtherOrgOnlyUpper()
    {
        $this->executeTestRoleGroup(10, JoinedOrgFilterType::ONLY_UPPER, false);
    }
    
    public function testRoleGroupDirectOnlyDowner()
    {
        $this->executeTestRoleGroup(6, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testRoleGroupUpperOnlyDowner()
    {
        $this->executeTestRoleGroup(5, JoinedOrgFilterType::ONLY_DOWNER, false);
    }

    public function testRoleGroupDownerOnlyDowner()
    {
        $this->executeTestRoleGroup(7, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testRoleGroupOtherOrgOnlyDowner()
    {
        $this->executeTestRoleGroup(10, JoinedOrgFilterType::ONLY_DOWNER, false);
    }
    
    public function testRoleGroupDirectOnlyJoin()
    {
        $this->executeTestRoleGroup(6, JoinedOrgFilterType::ONLY_JOIN, true);
    }

    public function testRoleGroupUpperOnlyJoin()
    {
        $this->executeTestRoleGroup(5, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testRoleGroupDownerOnlyJoin()
    {
        $this->executeTestRoleGroup(7, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testRoleGroupOtherOrgOnlyJoin()
    {
        $this->executeTestRoleGroup(10, JoinedOrgFilterType::ONLY_JOIN, false);
    }


    // Custom Value -------------------------------------------
    public function testCustomValueAdmin()
    {
        $this->executeTestCustomValue(1, JoinedOrgFilterType::ONLY_JOIN, true);
    }

    public function testCustomValueDirect()
    {
        $this->executeTestCustomValue(6, JoinedOrgFilterType::ONLY_JOIN, true);
    }

    public function testCustomValueUpper()
    {
        $this->executeTestCustomValue(5, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testCustomValueDowner()
    {
        $this->executeTestCustomValue(7, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testCustomValueOtherOrg()
    {
        $this->executeTestCustomValue(10, JoinedOrgFilterType::ONLY_JOIN, false);
    }


    public function testCustomValueDirectOnlyUpper()
    {
        $this->executeTestCustomValue(6, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testCustomValueUpperOnlyUpper()
    {
        $this->executeTestCustomValue(5, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testCustomValueDownerOnlyUpper()
    {
        $this->executeTestCustomValue(7, JoinedOrgFilterType::ONLY_UPPER, false);
    }

    public function testCustomValueOtherOrgOnlyUpper()
    {
        $this->executeTestCustomValue(10, JoinedOrgFilterType::ONLY_UPPER, false);
    }
    
    
    public function testCustomValueDirectOnlyDowner()
    {
        $this->executeTestCustomValue(6, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testCustomValueUpperOnlyDowner()
    {
        $this->executeTestCustomValue(5, JoinedOrgFilterType::ONLY_DOWNER, false);
    }

    public function testCustomValueDownerOnlyDowner()
    {
        $this->executeTestCustomValue(7, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testCustomValueOtherOrgOnlyDowner()
    {
        $this->executeTestCustomValue(10, JoinedOrgFilterType::ONLY_DOWNER, false);
    }
    
    
    public function testCustomValueDirectAll()
    {
        $this->executeTestCustomValue(6, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomValueUpperAll()
    {
        $this->executeTestCustomValue(5, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomValueDownerAll()
    {
        $this->executeTestCustomValue(7, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomValueOtherOrgAll()
    {
        $this->executeTestCustomValue(10, JoinedOrgFilterType::ALL, false);
    }


    // Workflow -------------------------------------------
    public function testWorkflowAdmin()
    {
        $this->executeTestWorkflow(1, JoinedOrgFilterType::ONLY_JOIN, false, true);
    }

    public function testWorkflowDirect()
    {
        $this->executeTestWorkflow(6, JoinedOrgFilterType::ONLY_JOIN, true, true);
    }

    public function testWorkflowUpper()
    {
        $this->executeTestWorkflow(5, JoinedOrgFilterType::ONLY_JOIN, false, true);
    }

    public function testWorkflowDowner()
    {
        $this->executeTestWorkflow(7, JoinedOrgFilterType::ONLY_JOIN, false, true);
    }

    public function testWorkflowOtherOrg()
    {
        $this->executeTestWorkflow(10, JoinedOrgFilterType::ONLY_JOIN, false, false);
    }
    

    public function testWorkflowDirectOnlyUpper()
    {
        $this->executeTestWorkflow(6, JoinedOrgFilterType::ONLY_UPPER, true, true);
    }

    public function testWorkflowUpperOnlyUpper()
    {
        $this->executeTestWorkflow(5, JoinedOrgFilterType::ONLY_UPPER, true, true);
    }

    public function testWorkflowDownerOnlyUpper()
    {
        $this->executeTestWorkflow(7, JoinedOrgFilterType::ONLY_UPPER, false, true);
    }

    public function testWorkflowOtherOrgOnlyUpper()
    {
        $this->executeTestWorkflow(10, JoinedOrgFilterType::ONLY_UPPER, false, false);
    }

    
    public function testWorkflowDirectOnlyDowner()
    {
        $this->executeTestWorkflow(6, JoinedOrgFilterType::ONLY_DOWNER, true, true);
    }

    public function testWorkflowUpperOnlyDowner()
    {
        $this->executeTestWorkflow(5, JoinedOrgFilterType::ONLY_DOWNER, false, true);
    }

    public function testWorkflowDownerOnlyDowner()
    {
        $this->executeTestWorkflow(7, JoinedOrgFilterType::ONLY_DOWNER, true, true);
    }

    public function testWorkflowOtherOrgOnlyDowner()
    {
        $this->executeTestWorkflow(10, JoinedOrgFilterType::ONLY_DOWNER, false, false);
    }
    

    public function testWorkflowDirectAll()
    {
        $this->executeTestWorkflow(6, JoinedOrgFilterType::ALL, true, true);
    }

    public function testWorkflowUpperAll()
    {
        $this->executeTestWorkflow(5, JoinedOrgFilterType::ALL, true, true);
    }

    public function testWorkflowDownerAll()
    {
        $this->executeTestWorkflow(7, JoinedOrgFilterType::ALL, true, true);
    }

    public function testWorkflowOtherOrgAll()
    {
        $this->executeTestWorkflow(10, JoinedOrgFilterType::ALL, false, false);
    }

    
    // Form -------------------------------------------
    public function testCustomFormDirect()
    {
        $this->executeTestCustomForm(6, JoinedOrgFilterType::ONLY_JOIN, true);
    }

    public function testCustomFormUpper()
    {
        $this->executeTestCustomForm(5, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testCustomFormDowner()
    {
        $this->executeTestCustomForm(7, JoinedOrgFilterType::ONLY_JOIN, false);
    }

    public function testCustomFormOtherOrg()
    {
        $this->executeTestCustomForm(10, JoinedOrgFilterType::ONLY_JOIN, false);
    }


    public function testCustomFormDirectOnlyUpper()
    {
        $this->executeTestCustomForm(6, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testCustomFormUpperOnlyUpper()
    {
        $this->executeTestCustomForm(5, JoinedOrgFilterType::ONLY_UPPER, true);
    }

    public function testCustomFormDownerOnlyUpper()
    {
        $this->executeTestCustomForm(7, JoinedOrgFilterType::ONLY_UPPER, false);
    }

    public function testCustomFormOtherOrgOnlyUpper()
    {
        $this->executeTestCustomForm(10, JoinedOrgFilterType::ONLY_UPPER, false);
    }
    
    
    public function testCustomFormDirectOnlyDowner()
    {
        $this->executeTestCustomForm(6, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testCustomFormUpperOnlyDowner()
    {
        $this->executeTestCustomForm(5, JoinedOrgFilterType::ONLY_DOWNER, false);
    }

    public function testCustomFormDownerOnlyDowner()
    {
        $this->executeTestCustomForm(7, JoinedOrgFilterType::ONLY_DOWNER, true);
    }

    public function testCustomFormOtherOrgOnlyDowner()
    {
        $this->executeTestCustomForm(10, JoinedOrgFilterType::ONLY_DOWNER, false);
    }
    
    
    public function testCustomFormDirectAll()
    {
        $this->executeTestCustomForm(6, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomFormUpperAll()
    {
        $this->executeTestCustomForm(5, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomFormDownerAll()
    {
        $this->executeTestCustomForm(7, JoinedOrgFilterType::ALL, true);
    }

    public function testCustomFormOtherOrgAll()
    {
        $this->executeTestCustomForm(10, JoinedOrgFilterType::ALL, false);
    }





    protected function executeTestOrganizationUser($loginId, $joinedOrgFilterType, $antiOrganizations, bool $antiResult){
        $this->init();

        $user = CustomTable::getEloquent('user')->getValueModel($loginId);
        $organizations = $user->getOrganizationIds($joinedOrgFilterType);
        
        sort($organizations);
        sort($antiOrganizations);
        
        $result = true;
        if(count($organizations) != count($antiOrganizations)){
            $result = false;
        }
        else{

            for($i = 0; $i < count($organizations); $i++){
                if($organizations[$i] != $antiOrganizations[$i]){
                    $result = false;
                    break;
                }
            }
        }

        $func = $antiResult ? 'assertTrue' : 'assertFalse';
        $this->{$func}(
            $result
        );
    }

    protected function executeTestOrganizationOrg($id, $joinedOrgFilterType, $antiOrganizations, bool $antiResult){
        $this->init();

        $organization = CustomTable::getEloquent('organization')->getValueModel($id);
        $organizations = $organization->getOrganizationIds($joinedOrgFilterType);
        
        sort($organizations);
        sort($antiOrganizations);
        
        $result = true;
        if(count($organizations) != count($antiOrganizations)){
            $result = false;
        }
        else{
            for($i = 0; $i < count($organizations); $i++){
                if($organizations[$i] != $antiOrganizations[$i]){
                    $result = false;
                    break;
                }
            }
        }

        $func = $antiResult ? 'assertTrue' : 'assertFalse';
        $this->{$func}(
            $result
        );
    }

    protected function executeTestRoleGroup($loginId, $joinedOrgFilterType, bool $result){
        $this->init();
        $this->be(LoginUser::find($loginId));
        System::org_joined_type_role_group($joinedOrgFilterType);
        
        $func = $result ? 'assertTrue' : 'assertFalse';
        $this->{$func}(CustomTable::getEloquent('roletest_custom_value_edit')->hasPermission());
    }

    protected function executeTestCustomValue($loginId, $joinedOrgFilterType, bool $result){
        $this->init();
        $this->be(LoginUser::find($loginId));
        System::org_joined_type_role_group($joinedOrgFilterType);
        System::org_joined_type_custom_value($joinedOrgFilterType);
        
        $func = $result ? 'assertTrue' : 'assertFalse';
        $custom_value = CustomTable::getEloquent('roletest_custom_value_edit')->getValueModel()->find(6); // created by dev user
        $this->{$func}(isset($custom_value));
    }
    
    protected function executeTestWorkflow($loginId, $joinedOrgFilterType, bool $result, bool $customValueResult){
        $this->init();
        $this->be(LoginUser::find($loginId));
        // set as All
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        System::org_joined_type_custom_value(JoinedOrgFilterType::ALL);
        
        $custom_value = CustomTable::getEloquent('roletest_custom_value_edit_all')->getValueModel()->find(101); // created by dev user
        $func = $customValueResult ? 'assertTrue' : 'assertFalse';
        $this->{$func}(isset($custom_value));

        if(!isset($custom_value)){
            return;
        }

        // check work users
        System::org_joined_type_workflow($joinedOrgFilterType);
        $workflowWorkUsers = $custom_value->workflow_work_users;

        $result = $workflowWorkUsers->contains(function($workflowWorkUser) use($loginId){
            return $workflowWorkUser->custom_table->table_name == SystemTableName::USER && $workflowWorkUser->id == $loginId;
        });
        $func = $result ? 'assertTrue' : 'assertFalse';
        $this->{$func}($result);

        
        // get actions
        $result = count($custom_value->getWorkflowActions(true)) > 0;
        $this->{$func}($result);
    }
    
    protected function executeTestCustomForm($loginId, $joinedOrgFilterType, bool $result){
        $this->init();
        $this->be(LoginUser::find($loginId));
        // set as All
        System::org_joined_type_role_group(JoinedOrgFilterType::ALL);
        System::org_joined_type_custom_value(JoinedOrgFilterType::ALL);
        System::org_joined_type_custom_form($joinedOrgFilterType);
        
        $custom_table = CustomTable::getEloquent('roletest_custom_value_edit');
        $custom_form = $custom_table->getPriorityForm(6); // dev-userB
        
        $this->assertTrue($custom_form->form_view_name == ($result ? 'form' : 'form_default'));
    }
}
