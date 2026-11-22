<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * templates/request_pack (申請パック, ISS02818): the request tables, the workflow
 * shared by them, the role groups and the "申請・承認" menu hang together, and
 * nothing in it depends on the ids of the site it was made on.
 */
class RequestPackTemplateTest extends UnitTestBase
{
    /**
     * @return array<string, mixed>
     */
    protected function config(?string $locale = null): array
    {
        $base = exment_package_path('templates/request_pack');
        $json = (string) file_get_contents("$base/config.json");
        if (is_null($locale)) {
            $config = json_decode($json, true);
            $this->assertIsArray($config);
            return $config;
        }
        $current = \App::getLocale();
        try {
            \App::setLocale($locale);
            return (new TemplateImporter())->getMergeJson($json, ['basePath' => $base]);
        } finally {
            \App::setLocale($current);
        }
    }

    public function testTheWorkflowCoversTheRequestTables(): void
    {
        $config = $this->config();
        $tables = array_column($config['custom_tables'], 'table_name');
        $this->assertCount(1, $config['workflows']);
        $workflow = $config['workflows'][0];
        $this->assertSame('0', strval($workflow['workflow_type']), 'one workflow for every request table');
        foreach ($workflow['workflow_tables'] as $wt) {
            $this->assertContains($wt['table_name'], $tables);
        }
        $this->assertSame(['request_ringi', 'request_expense', 'request_trip', 'request_payment', 'request_leave'], array_column($workflow['workflow_tables'], 'table_name'));

        $statuses = array_column($workflow['workflow_statuses'], 'status_name');
        foreach ($workflow['workflow_actions'] as $action) {
            $from = $action['status_from_name'];
            $this->assertTrue($from === Define::WORKFLOW_START_KEYNAME || in_array($from, $statuses, true), "from of {$action['action_name']}");
            foreach ($action['workflow_condition_headers'] as $header) {
                $this->assertContains($header['status_to'], $statuses, "to of {$action['action_name']}");
            }
            // only the creator (a fixed system value) or the user chosen when submitting: no user, organization or role id of another site
            foreach ($action['workflow_authorities'] as $authority) {
                $this->assertSame('system', $authority['related_type'], $action['action_name']);
            }
            if (empty($action['workflow_authorities'])) {
                $this->assertSame('action_select', $action['options']['work_target_type'], $action['action_name']);
            }
        }
    }

    public function testTheMenuUsesKnownSystemMenusAndItsTables(): void
    {
        $config = $this->config();
        $tables = array_column($config['custom_tables'], 'table_name');
        foreach ($config['admin_menu'] as $menu) {
            if ($menu['menu_type'] === 'system') {
                $this->assertArrayHasKey($menu['menu_name'], Define::MENU_SYSTEM_DEFINITION);
            } elseif ($menu['menu_type'] === 'table') {
                $this->assertContains($menu['menu_target_name'], $tables);
            }
        }
        $this->assertContains('workflow_task', array_column($config['admin_menu'], 'menu_name'));
        // the group is for the applicants and the approvers of the pack (by name: ISS02822)
        $group = collect($config['admin_menu'])->firstWhere('menu_name', 'request');
        $this->assertSame(array_column($config['roles'], 'role_group_name'), array_get($group, 'options.visible_role_group_names'));
    }

    public function testTheRoleGroupsAreForTheTablesOfThePack(): void
    {
        $config = $this->config();
        $tables = array_column($config['custom_tables'], 'table_name');
        $this->assertSame(['request_applicant_group', 'request_approver_group'], array_column($config['roles'], 'role_group_name'));
        foreach ($config['roles'] as $role) {
            foreach ($role['role_group_permissions'] as $permission) {
                $this->assertContains($permission['role_group_target_name'], $tables);
            }
        }
    }

    public function testEnglishGivesEveryTableAndColumnAName(): void
    {
        $config = $this->config('en');
        $this->assertSame('Request pack', $config['template_view_name']);
        foreach ($config['custom_tables'] as $table) {
            $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $table['table_view_name'], $table['table_name']);
            foreach ($table['custom_columns'] as $column) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $column['column_view_name'], $table['table_name'] . '.' . $column['column_name']);
            }
        }
        // the values of the choices stay the same in every language; only the labels change
        $ja = $this->config();
        $values = function ($config) {
            $result = [];
            foreach ($config['custom_tables'] as $table) {
                foreach ($table['custom_columns'] as $column) {
                    if ($column['column_type'] === 'select_valtext') {
                        $result[$column['column_name']] = array_map(function ($line) {
                            return explode(',', $line)[0];
                        }, explode("\n", $column['options']['select_item_valtext']));
                    }
                }
            }
            return $result;
        };
        $this->assertSame($values($ja), $values($config));
    }
}
