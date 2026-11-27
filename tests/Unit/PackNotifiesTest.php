<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\TemplateImportExport\NotifyTemplate;

/**
 * The notifies of the bundled packs (request, sales, asset, HR) name only
 * tables, columns and workflows of their own pack, so that an import finds
 * them all (NotifyTemplate skips a notify whose names are missing).
 */
class PackNotifiesTest extends UnitTestBase
{
    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function packs(): array
    {
        return [
            'request' => ['request_pack', 1],
            'sales' => ['sales_pack', 1],
            'asset' => ['asset_pack', 2],
            'hr' => ['hr_pack', 1],
        ];
    }

    /**
     * @dataProvider packs
     */
    public function testEveryNameOfANotifyIsInItsPack(string $pack, int $count): void
    {
        $config = json_decode((string) file_get_contents(exment_package_path("templates/$pack/config.json")), true);
        $this->assertIsArray($config);
        $columns = [];
        foreach ($config['custom_tables'] as $table) {
            $columns[$table['table_name']] = array_column($table['custom_columns'], 'column_name');
        }
        $workflows = array_column($config['workflows'] ?? [], 'workflow_view_name');

        $notifies = $config['notifies'] ?? [];
        $this->assertCount($count, $notifies);
        foreach ($notifies as $notify) {
            $name = $notify['notify_view_name'];
            if (strval($notify['notify_trigger']) === NotifyTrigger::WORKFLOW) {
                $this->assertContains($notify['target_name'], $workflows, $name);
            } else {
                $this->assertArrayHasKey($notify['target_name'], $columns, $name);
                $settings = $notify['trigger_settings'];
                if (isset($settings['notify_target_column_name'])) {
                    $this->assertContains($settings['notify_target_column_name'], $columns[$settings['notify_target_table_name']] ?? [], $name);
                }
            }
            foreach ($notify['action_settings'] as $action) {
                foreach ($action['notify_action_target'] as $target) {
                    if (strpos($target, NotifyTemplate::COLUMN_PREFIX) === 0) {
                        [$table, $column] = explode('.', substr($target, strlen(NotifyTemplate::COLUMN_PREFIX)), 2);
                        $this->assertContains($column, $columns[$table] ?? [], "$name: $target");
                    } else {
                        $this->assertContains($target, NotifyActionTarget::arrays(), "$name: $target");
                    }
                }
            }
            $this->assertArrayNotHasKey('webhook_url', $notify['action_settings'][0], $name);
        }

        // English names for every notify, found by suuid
        $lang = json_decode((string) file_get_contents(exment_package_path("templates/$pack/lang/en/lang.json")), true);
        $this->assertEqualsCanonicalizing(array_column($notifies, 'suuid'), array_column($lang['notifies'] ?? [], 'suuid'));
    }
}
