<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\WorkflowValueAuthority;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;

class Api3WorkflowTest extends ApiTestBase
{
    public function testGetWorkflowList()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow'))
            ->assertStatus(200)
            ->assertDontSeeText('workflow_common_no_complete')
            ->assertJsonCount(3, 'data');
    }

    public function testGetWorkflowListAll()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?all=1')
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete')
            ->assertJsonCount(4, 'data');
    }

    public function testGetWorkflowListWithCount()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?all=1&count=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testGetWorkflowListById()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=2')
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete');
    }

    public function testGetWorkflowListByMultiId()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=1,3')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function testGetWorkflowListExpand()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?expands=statuses,actions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'workflow_statuses',
                        'workflow_actions',
                        ],
                    ],
                ]);
    }

    public function testGetWorkflowListNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow') . '?id=9999')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function testWrongScopeGetWorkflowList()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetWorkflow()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '2'))
            ->assertStatus(200)
            ->assertSeeText('workflow_common_no_complete');
    }

    public function testGetWorkflowExpand()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '2') . '?expands=statuses,actions')
            ->assertStatus(200)
            ->assertJsonStructure([
                'workflow_statuses',
                'workflow_actions'
            ]);
    }

    public function testGetWorkflowNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '9999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowStatusList()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '3', 'statuses'))
            ->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testGetWorkflowStatusListNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '999', 'statuses'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }

    public function testGetWorkflowActionList()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '3', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(3);
    }

    public function testGetWorkflowActionListNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'workflow', '999', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }

    public function testGetWorkflowStatus()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'status', '4'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 4,
                'workflow_id' => '2',
                'status_type'=> '0',
                'order'=> '0',
                'status_name' => 'waiting',
                'datalock_flg'=> '0',
                'completed_flg'=> '0',
            ]);
    }

    public function testGetWorkflowStatusNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'status', '999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
        ]);
    }

    public function testGetWorkflowAction()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'action', '4'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'id' => 4,
                'workflow_id' => '2',
                'status_from' => 'start',
                'action_name' => 'send',
                'ignore_work'=> '0',
                'options'=> [
                    'comment_type' => 'nullable',
                    'flow_next_type' => 'some',
                    'flow_next_count' => '1',
                    'work_target_type' => 'fix'
                ],
            ]);
    }

    public function testGetWorkflowActionNotFound()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'action', '999'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowData()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '1000', 'value'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'workflow_id' => '2',
                'morph_type' => 'custom_value_access_all',
                'morph_id' => '1000',
                'workflow_action_id'=> '5',
                'workflow_status_from_id'=> '4',
                'workflow_status_to_id'=> '5',
                'action_executed_flg'=> '0',
                'latest_flg'=> '1',
            ]);
    }

    public function testGetWorkflowDataExpand()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value') . '?expands=status_from,status_to,action')
            ->assertStatus(200);
        $response->assertJsonStructure([
                'workflow_status_from',
                'workflow_status_to',
                'workflow_action',
            ]);
    }

    public function testGetWorkflowDataNotFound()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '9999', 'value'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowDataNotStart()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '10', 'value'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_NOSTART
            ]);
    }

    public function testDenyGetWorkflowDataTable()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'value'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowData()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testGetWorkflowUser()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'work_users'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'organization_name' => 'dev'
            ]);
    }

    public function testGetWorkflowUserOrg()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'work_users'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment([
                'organization_name' => 'dev'
            ]);
    }

    public function testGetWorkflowUserAll()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'work_users') . '?all=1')
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function testGetWorkflowUserAsUser()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'work_users') . '?as_user=1')
            ->assertStatus(200)
            ->assertSeeText('dev0-userB');
    }

    public function testGetWorkflowUserNotFound()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '9999', 'work_users'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowUserEnd()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '1000', 'work_users'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_END
            ]);
    }

    public function testDenyGetWorkflowUserTable()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'work_users'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowUser()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'work_users'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }


    // Execute action 1 - Fix user ----------------------------------------------------

    public function testGetWorkflowExecAction()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertSeeText('action3');
    }

    public function testGetWorkflowExecActionAll()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'actions') . '?all=1')
            ->assertStatus(200)
            ->assertJsonCount(2)
            ->assertSeeText('action2');
    }

    public function testGetWorkflowExecActionZero()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_view_all', '1', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function testGetWorkflowExecActionNotFound()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '99999', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }


    // Execute action 2 - Get by userinfo ----------------------------------------------------
    // dev1-userC → dev0-userB

    public function testGetWorkflow2ExecAction()
    {
        $token = $this->getDev1UserCAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertSeeText('send');
    }

    public function testGetWorkflow2ExecActionAll()
    {
        $token = $this->getDev1UserCAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'actions') . '?all=1')
            ->assertStatus(200)
            ->assertJsonCount(1)
            ->assertSeeText('send');
    }

    public function testGetWorkflow2ExecActionZero()
    {
        $token = $this->getDev1UserCAccessToken([ApiScope::WORKFLOW_READ]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'workflow2', '1', 'actions'))
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function testGetWorkflow2ExecActionNotFound()
    {
        $token = $this->getDev1UserCAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'workflow2', '99999', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }



    public function testGetWorkflowExecActionNoTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testGetWorkflowExecActionEnd()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'actions'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::WORKFLOW_END
            ]);
    }

    public function testDenyGetWorkflowExecActionTable()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'actions'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowExecAction()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'actions'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testGetWorkflowHistory()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '1000', 'histories'))
            ->assertStatus(200)
            ->assertJsonCount(2);
    }

    public function testGetWorkflowHistoryZero()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_access_all', '10', 'histories'))
            ->assertStatus(200)
            ->assertJsonCount(0);
    }

    public function testGetWorkflowHistoryNotFound()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '99999', 'histories'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testGetWorkflowHistoryNoTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'histories'))
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDenyGetWorkflowHistoryTable()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'histories'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyGetWorkflowHistory()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'histories'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }


    // post value (!!! test execute workflow at once !!!)-------------------------------------

    public function testExecuteWorkflowNoNext()
    {
        $token = $this->getUserAccessToken('dev0-userB', 'dev0-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 2,
            'comment' => $comment
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflowWithNext()
    {
        $token = $this->getUserAccessToken('dev0-userB', 'dev0-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 2,
            'next_users' => '4,3',
            'next_organizations' => 2,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 2,
            'comment' => $comment,
            'created_user_id' => "6" //dev0-userB
        ]);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $id = array_get($json, 'id');

        $authorities = WorkflowValueAuthority::where('workflow_value_id', $id)->get();
        $this->assertTrue(!\is_nullorempty($authorities));
        $this->assertTrue(count($authorities) === 3);
        foreach ($authorities as $authority) {
            $this->assertTrue(
                ($authority->related_id == '2' && $authority->related_type == 'organization') ||
                ($authority->related_id == '3' && $authority->related_type == 'user') ||
                ($authority->related_id == '4' && $authority->related_type == 'user')
            );
        }
    }

    public function testExecuteWorkflowNoParam()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value'), [
            'comment' => 'comment'
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflowNoComment()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::VALIDATION_ERROR
        ]);
    }

    public function testExecuteWorkflow()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 3,
            'workflow_status_to_id' => '2',
            'created_user_id' => "3", //User1
            'comment' => $comment
        ]);
    }

    public function testExecuteWorkflowMultiUser()
    {
        $token = $this->getUserAccessToken('dev0-userB', 'dev0-userB', [ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit_all', '1000', 'value'), [
            'workflow_action_id' => 3,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 3,
            'workflow_status_to_id' => '3',
            'created_user_id' => "6", //User1
            'comment' => $comment
        ]);
    }

    public function testExecuteWorkflowNoAction()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 99999
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecuteWorkflowWrongAction()
    {
        $token = $this->getUser1AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1', 'value'), [
            'workflow_action_id' => 6
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecuteWorkflowActionWithFilterError()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')
            ->getValueModel()
            ->whereNot('id', 1)
            ->where('value->multiples_of_3', '1')
            ->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', $custom_value->id, 'value'), [
            'workflow_action_id' => 6
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecuteWorkflowActionWithFilter1()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $custom_value = CustomTable::getEloquent('custom_value_edit')
            ->getValueModel()
            ->whereNot('id', 1)
            ->whereNot('value->multiples_of_3', '1')
            ->first();

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', $custom_value->id, 'value'), [
            'workflow_action_id' => 6
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 6,
            'workflow_status_to_id' => '6',
            'created_user_id' => '1', //admin
        ]);
    }

    public function testExecuteWorkflowNotFound()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '99999', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::DATA_NOT_FOUND
            ]);
    }

    public function testExecuteWorkflowNoTable()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'not_found_table', '1', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(400)
            ->assertJsonFragment([
                'code' => ErrorCode::INVALID_PARAMS
            ]);
    }

    public function testDenyExecuteWorkflowTable()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'no_permission', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testDenyExecuteWorkflow()
    {
        $token = $this->getUser2AccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::PERMISSION_DENY
            ]);
    }

    public function testWrongScopeExecuteWorkflow()
    {
        $token = $this->getAdminAccessToken([ApiScope::WORKFLOW_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'custom_value_edit', '1000', 'value'), [
            'workflow_action_id' => 3
        ])
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }



    // post value (!!! test execute workflow at once !!!)-------------------------------------
    // Execute action 2 - Get by userinfo ----------------------------------------------------
    // dev1-userC → dev0-userB

    public function testExecuteWorkflow2WithNext()
    {
        $token = $this->getDev1UserCAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');
        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'value'), [
            'workflow_action_id' => 9,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 9,
            'workflow_status_to_id' => '8',
            'comment' => $comment,
            'created_user_id' => "7" //dev1-userC
        ]);

        $json = json_decode_ex($response->baseResponse->getContent(), true);
        $id = array_get($json, 'id');

        // get workflow value
        $custom_table = CustomTable::getEloquent('workflow1');
        $custom_value = $custom_table->getValueModel(61);
        $workflow_value = $custom_value->workflow_value;
        $this->assertTrue(!is_nullorempty($workflow_value));

        $this->assertMatch($workflow_value->morph_id, 61);
        $this->assertMatch($workflow_value->workflow_action_id, 9);
        $this->assertMatch($workflow_value->workflow_status_to_id, 8);
    }

    public function testExecuteWorkflow2WrongAction()
    {
        $token = $this->getDevUserBAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'value'), [
            'workflow_action_id' => 1
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecuteWorkflow2WrongUser()
    {
        $token = $this->getUserAccessToken('dev2-userE', 'dev2-userE', [ApiScope::WORKFLOW_EXECUTE]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'value'), [
            'workflow_action_id' => 10
        ])
        ->assertStatus(400)
        ->assertJsonFragment([
            'code' => ErrorCode::WORKFLOW_ACTION_DISABLED
        ]);
    }

    public function testExecute2Workflow()
    {
        $token = $this->getDevUserBAccessToken([ApiScope::WORKFLOW_EXECUTE]);

        $comment = 'comment' . date('YmdHis');

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->post(admin_urls('api', 'wf', 'data', 'workflow1', '61', 'value'), [
            'workflow_action_id' => 10,
            'comment' => $comment
        ])
        ->assertStatus(201)
        ->assertJsonFragment([
            'workflow_action_id' => 10,
            'workflow_status_to_id' => '9',
            'created_user_id' => "6", //dev0-userB
            'comment' => $comment
        ]);
    }
}
