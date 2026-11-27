<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * templates/sales_pack (営業パック, ISS02818): customers, deals, quotes and
 * contracts hang together, nothing depends on the ids of the site it was made
 * on, and English names every table and column.
 */
class SalesPackTemplateTest extends UnitTestBase
{
    /**
     * @return array<string, mixed>
     */
    protected function config(?string $locale = null): array
    {
        $base = exment_package_path('templates/sales_pack');
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
        $this->assertSame(['sales_customer', 'sales_customer_contact', 'sales_deal', 'sales_quote', 'sales_quote_detail', 'sales_contract', 'sales_activity'], $tables);
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

    public function testTheMenuIsForTheSalesRoleGroup(): void
    {
        $config = $this->config();
        $group = null;
        foreach ($config['admin_menu'] as $menu) {
            if ($menu['menu_name'] === 'sales') {
                $group = $menu;
            }
        }
        $this->assertIsArray($group);
        $this->assertSame('20', strval($group['order']), 'the order of the feature map');
        $this->assertSame(['sales_member_group'], array_get($group, 'options.visible_role_group_names'));
        $this->assertSame(['sales_member_group'], array_column($config['roles'], 'role_group_name'));
    }

    public function testEnglishGivesEveryTableAndColumnAName(): void
    {
        $config = $this->config('en');
        $this->assertSame('Sales pack', $config['template_view_name']);
        foreach ($config['custom_tables'] as $table) {
            $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $table['table_view_name'], $table['table_name']);
            foreach ($table['custom_columns'] as $column) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}\x{4e00}-\x{9fff}]/u', $column['column_view_name'], $table['table_name'] . '.' . $column['column_name']);
            }
        }
    }

    /**
     * The views and the dashboard name only the tables and columns of the pack (an import
     * leaves out what it cannot find), and every language names them.
     */
    public function testTheViewsAndTheDashboardNameTheColumnsOfThePack(): void
    {
        $config = $this->config();
        $columns = [];
        foreach ($config['custom_tables'] as $table) {
            foreach ($table['custom_columns'] as $column) {
                $columns[] = $table['table_name'] . '.' . $column['column_name'];
            }
        }
        $suuids = [];
        foreach ($config['custom_views'] as $view) {
            $suuids[] = $view['suuid'];
            foreach (['custom_view_columns', 'custom_view_filters', 'custom_view_sorts', 'custom_view_summaries'] as $kind) {
                foreach ($view[$kind] as $item) {
                    $name = $item['view_column_table_name'] . '.' . $item['view_column_target_name'];
                    $this->assertTrue(in_array($name, $columns, true) || $item['view_column_target_name'] === 'id', $view['view_view_name'] . ': ' . $name);
                }
            }
            foreach ((array) $view['options'] as $key => $value) {
                if (str_starts_with($key, 'kanban_') && str_ends_with($key, '_column')) {
                    $this->assertContains($value, $columns, $key);
                }
            }
        }
        $this->assertCount(1, $config['dashboards']);
        foreach ($config['dashboards'][0]['dashboard_boxes'] as $box) {
            $this->assertContains($box['options']['target_view_suuid'], $suuids, $box['dashboard_box_view_name']);
        }

        foreach (['en', 'de', 'es', 'ko', 'vi', 'zh_CN'] as $locale) {
            $merged = $this->config($locale);
            foreach ($merged['custom_views'] as $view) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $view['view_view_name'], "$locale: " . $view['suuid']);
            }
            $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $merged['dashboards'][0]['dashboard_view_name'], $locale);
            foreach ($merged['dashboards'][0]['dashboard_boxes'] as $box) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $box['dashboard_box_view_name'], $locale);
            }
            foreach ($merged['custom_tables'] as $table) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $table['table_view_name'], "$locale: " . $table['table_name']);
                foreach ($table['custom_columns'] as $column) {
                    $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $column['column_view_name'], "$locale: " . $table['table_name'] . '.' . $column['column_name']);
                    $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', strval(array_get($column, 'options.select_item_valtext')), "$locale: " . $column['column_name']);
                }
            }
            foreach ($merged['admin_menu'] as $menu) {
                $this->assertDoesNotMatchRegularExpression('/[\x{3040}-\x{30ff}]/u', $menu['title'], "$locale: " . $menu['menu_name']);
            }
        }
    }

    public function testTheQuoteAddsUpItsLines(): void
    {
        $columns = [];
        foreach ($this->config()['custom_tables'] as $table) {
            foreach ($table['custom_columns'] as $column) {
                $columns[$table['table_name'] . '.' . $column['column_name']] = $column['options']['calc_formula'] ?? null;
            }
        }
        $this->assertSame('${value:quantity} * ${value:unit_price}', $columns['sales_quote_detail.amount'] ?? null);
        $this->assertSame('${sum:sales_quote_detail.amount}', $columns['sales_quote.total'] ?? null);
    }
}
