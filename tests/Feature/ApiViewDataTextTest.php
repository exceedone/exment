<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;

/**
 * viewdata as text reads the records the cells select (select table, user, organization) once
 * a page, not once a cell (ISS02885): the cells are the same as when each cell reads its record.
 */
class ApiViewDataTextTest extends ApiTestBase
{
    public function testTextCellsAreTheSame(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $this->assertNotNull($custom_table);
        $this->assertNotEmpty($custom_table->getSelectTableColumns(), 'the table has columns that select a record');

        $views = CustomView::where('custom_table_id', $custom_table->id)
            ->whereIn('view_kind_type', [ViewKindType::DEFAULT, ViewKindType::ALLDATA])->get();
        $this->assertNotEmpty($views);

        $queries = [];
        \DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });
        foreach ($views as $custom_view) {
            System::clearRequestSession();
            $queries = [];
            \Auth::forgetGuards();
            $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                ->get(admin_urls('api', 'viewdata', $custom_table->table_name, $custom_view->suuid) . '?count=20&valuetype=text')
                ->assertStatus(200);
            $data = $response->json('data');
            $this->assertIsArray($data);

            // no query once a cell: none runs more than a few times
            $repeated = array_filter(array_count_values($queries), fn ($n) => $n > 3);
            $this->assertEmpty($repeated, "view {$custom_view->view_view_name}: " . json_encode($repeated));

            // the same page, each cell reading its record itself
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            System::clearRequestSession();
            $items = $custom_view->getDataPaginate(['maxCount' => 20, 'executeSearch' => false, 'isApi' => true])->items();
            System::clearRequestSession();
            list($headers, $bodies, $styles, $classes, $columnItems) = $custom_view->convertDataTable($items, ['appendLink' => false, 'valueType' => ValueType::TEXT]);
            $apiNames = collect($columnItems)->map(fn ($item) => $item->apiName())->toArray();
            $expected = array_map(fn ($body) => array_combine($apiNames, $body), $bodies);

            $this->assertSame(
                json_encode($expected, JSON_UNESCAPED_UNICODE),
                json_encode($data, JSON_UNESCAPED_UNICODE),
                "view {$custom_view->view_view_name}"
            );
            \Auth::logout();
        }
    }
}
