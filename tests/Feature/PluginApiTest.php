<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;

class PluginApiTest extends ApiTestBase
{
    public function testSampleApiColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::PLUGIN]);

        $result = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'plugins', 'sampleapi', 'column')
            . '?table=all_columns_table_fortest&column=select_table_multiple');

        $result->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'select_table_multiple',
                'column_type' => 'select_table',
            ]);
    }

    public function testSampleApiTableColumn()
    {
        $token = $this->getAdminAccessToken([ApiScope::PLUGIN]);

        $result = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'plugins', 'sampleapi', 'tablecolumn', 'all_columns_table_fortest', 'select_table_multiple'));

        $result->assertStatus(200)
            ->assertJsonFragment([
                'column_name' => 'select_table_multiple',
                'column_type' => 'select_table',
            ]);
    }
}
