# Test
How to test.


## Setup test
- Please execute this command.  
<span style="color:red;">CAUTION: If execute this command, reset all data.</span>

```
php artisan exment:inittest
```

- Please execute this command.  

```
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Feature
.\vendor\bin\phpunit .\vendor\exceedone\exment\tests\Unit
```


## About Testdata

### User
| id | user_code | test password |
| ---- | ---- | ---- |
| 1 | admin | adminadmin |
| 2 | user1 | user1user1 |
| 3 | user2 | user2user2 |
| 4 | user3 | user3user3 |
| 5 | company1-userA | company1-userA |
| 6 | dev-userB | dev-userB |
| 7 | dev1-userC | dev1-userC |
| 8 | dev1-userD | dev1-userD |
| 9 | dev2-userE | dev2-userE |
| 10 | company2-userF | company2-userF |

### Organization
| id | organization_code | parent_organization_code | users |
| ---- | ---- | ---- | ---- |
| 1 | company1 | - | company1-userA |
| 2 | dev | company1 | dev-userB |
| 3 | manage | company1 | - |
| 4 | dev1 | dev | dev1-userC,dev1-userD |
| 5 | dev2 | dev | dev2-userE |
| 6 | company2 | - | company2-userF |
| 7 | company2-a | company2 | - |



### RoleGroup
| id | role_group_name | organizations | users | permissions |
| ---- | ---- | ---- | ---- | ---- |
| 1 | data_admin_group | - | user1 | Can access all data |
| 2 | user_organization_admin_group | - | - | Can manage user and organization |
| 3 | information_manage_group | - | - | Can manage information |
| 4 | user_group | dev | user2,user3 | Please look bottom |


### CustomTable
| id | table_name | description |
| ---- | ---- | ---- |
| 9 | roletest_custom_value_edit_all | user_group users can edit all_custom_value. |
| 10 | roletest_custom_value_view_all | user_group users can view all_custom_value. |
| 11 | roletest_custom_value_access_all | user_group users can access all_custom_value. |
| 12 | roletest_custom_value_edit | user_group users can edit custom_value. And has workflow. |
| 13 | roletest_custom_value_view | user_group users can view custom_value. |
| 14 | no_permission | user_group users don't have permission. |


### CustomColumn
- Each tables have above columns;

| column_name | column_type | options | description |
| ---- | ---- | ---- | ---- |
| text | text | required | 'test_' + created_user_id |
| user | user | index_enabled | created_user_id |
| index_text | text | index_enabled | 'index_text_' + created_user_id + '_' + loop_no(1-10) |
| odd_even | text | index_enabled | If loop_no(1-10) is odd then 'odd' else 'even' |
| multiples_of_3 | yesno | index_enabled | If loop_no(1-10)'s multiple is 3 then 1 else 0 |


### CustomView
- Each tables have above view;

| view_name | view_type | filter_description |
| ---- | ---- | ---- | ---- |
| table_name + ' view all' | all | - |
| table_name + ' view and' | default | odd_even != odd and multiples_of_3 == 1 and user == 2 |
| table_name + ' view or' | default | odd_even != odd or multiples_of_3 == 1 or user == 2 |
| table_name + ' view workflow_status_start' | default | Workflow status is "start" |
| table_name + ' view workflow_status_middle' | default | Workflow status is "middle" |
| table_name + ' view workflow_work_user' | default | Workflow is self |


### CustomValue
- Create custom data for each table.  
- Each of the above users creates 10 data.

Ex.

| value.text | value.user | value.odd_even | value.index_text | value.multiples_of_3 | created_user_id |
| ---- | ---- | ---- | ---- | ---- | ---- |
| test_1 | 1 | odd | index_1_1 | 0 | 1 |
| test_1 | 1 | even | index_1_2 | 0 | 1 |
| test_1 | 1 | odd | index_1_3 | 1 | 1 |
| test_2 | 2 | odd | index_2_1 | 0 | 2 |
| test_2 | 2 | even | index_2_2 | 0 | 2 |
| test_2 | 2 | odd | index_2_3 | 1 | 2 |


### Workflow

| id | workflow_name | workflow_type | setting_completed_flg | target_table |
| ---- | ---- | ---- | ---- | ---- |
| 1 | workflow_common_company | common | 1 | roletest_custom_value_edit_all |
| 2 | workflow_common_no_complete | common | - | - |
| 3 | workflow_for_individual_table | table | 1 | roletest_custom_value_edit |
