<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * templates/hr_pack (人事・総務パック, ISS02818): the staff list and the
 * procedures hang together, nothing depends on the ids of the site it was made
 * on, and English names every table and column.
 */
class HrPackTemplateTest extends UnitTestBase
{
    /**
     * @return array<string, mixed>
     */
    protected function config(?string $locale = null): array
    {
        $base = exment_package_path('templates/hr_pack');
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

    public function testTheTablesReferToEachOtherByName(): void
    {
        $config = $this->config();
        $tables = array_column($config['custom_tables'], 'table_name');
        $this->assertSame(['hr_employee', 'hr_procedure', 'hr_procedure_task'], $tables);
        foreach ($config['custom_tables'] as $table) {
            foreach ($table['custom_columns'] as $column) {
                if ($column['column_type'] === 'select_table') {
                    $this->assertContains($column['options']['select_target_table_name'] ?? null, $tables, $table['table_name'] . '.' . $column['column_name']);
                }
            }
        }
        foreach ($config['custom_relations'] as $relation) {
            $this->assertContains($relation['parent_custom_table_name'], $tables);
            $this->assertContains($relation['child_custom_table_name'], $tables);
        }
    }

    public function testTheMenuIsForTheHrRoleGroups(): void
    {
        $config = $this->config();
        $group = null;
        foreach ($config['admin_menu'] as $menu) {
            if ($menu['menu_name'] === 'hr') {
                $group = $menu;
            }
        }
        $this->assertIsArray($group);
        $this->assertSame('50', strval($group['order']), 'the order of the feature map');
        $this->assertSame(['hr_admin_group', 'hr_member_group'], array_get($group, 'options.visible_role_group_names'));
        $this->assertSame(['hr_admin_group', 'hr_member_group'], array_column($config['roles'], 'role_group_name'));
    }

    public function testEnglishGivesEveryTableAndColumnAName(): void
    {
        $config = $this->config('en');
        $this->assertSame('HR pack', $config['template_view_name']);
        foreach ($config['custom_tables'] as $table) {
            $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $table['table_view_name'], $table['table_name']);
            foreach ($table['custom_columns'] as $column) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $column['column_view_name'], $table['table_name'] . '.' . $column['column_name']);
            }
        }
    }
}
