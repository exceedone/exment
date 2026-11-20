<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Studio\BlankFormBuilder;
use Exceedone\Exment\Services\Studio\ScreenDefinition;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The screens of the Studio as JSON, for the phone app (ISS00231 phase 3).
 */
class ApiStudioScreenTest extends ApiTestBase
{
    /**
     * @var array<int, string>
     */
    protected $made = [];

    protected function tearDown(): void
    {
        foreach ($this->made as $name) {
            System::clearRequestSession();
            $table = CustomTable::where('table_name', $name)->first();
            if (isset($table)) {
                $table->delete();
            }
        }
        parent::tearDown();
    }

    /**
     * @param array<int, string> $scope
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function api(string $path, array $scope = [])
    {
        $token = $this->getAdminAccessToken($scope);
        return $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'studio', $path));
    }

    public function testTheScreensOfTheTablesAreListed(): void
    {
        $table = CustomTable::getEloquent('parent_table');
        $this->assertNotNull($table);
        $form = CustomForm::where('custom_table_id', $table->id)->firstOrFail();

        $response = $this->api('screens?table=parent_table')->assertOk();
        $screens = $response->json('data');
        $this->assertContains([
            'id' => $form->suuid,
            'name' => $form->form_view_name,
            'default' => boolval($form->default_flg),
            'table' => 'parent_table',
            'table_label' => $table->table_view_name,
        ], $screens);
        $this->assertSame(['parent_table'], array_values(array_unique(array_column($screens, 'table'))));
    }

    public function testAScreenMadeInTheStudioIsGivenWithItsRowsAndColumns(): void
    {
        $this->made[] = 'studio_test_api_screen';
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $table = BlankFormBuilder::build(BlankFormBuilder::normalize([
            'table_view_name' => 'API の画面',
            'table_name' => 'studio_test_api_screen',
            'fields' => [
                ['type' => ColumnType::TEXT, 'label' => 'company', 'required' => true, 'row' => 0, 'column' => 0],
                ['type' => ColumnType::SELECT, 'label' => 'rank', 'choices' => "A\nB", 'row' => 0, 'column' => 1],
                ['type' => ColumnType::YESNO, 'label' => 'active', 'row' => 0, 'column' => 1],
                ['type' => ColumnType::USER, 'label' => 'owner', 'row' => 1, 'column' => 0, 'visible_if' => ['target' => 2, 'operator' => 'eq', 'value' => '1']],
            ],
            'layout' => [['widths' => [3, 1]], ['widths' => [4]]],
        ]));
        $form = CustomForm::where('custom_table_id', $table->id)->firstOrFail();

        $json = $this->api('screens/' . $form->suuid)->assertOk()->json();

        $this->assertSame(ScreenDefinition::FORMAT, $json['format']);
        $this->assertSame(1, $json['version']);
        $this->assertSame(['name' => 'studio_test_api_screen', 'label' => 'API の画面'], $json['table']);
        $this->assertSame(['create' => true, 'edit' => true], $json['permissions']);
        $this->assertCount(1, $json['blocks']);

        $block = $json['blocks'][0];
        $this->assertSame('default', $block['type']);
        $this->assertSame('studio_test_api_screen', $block['table']);
        $this->assertNull($block['label']);

        // row 1: company | rank, active; row 2: owner
        $shape = array_map(function ($row) {
            return array_map(function ($column) {
                return [$column['width'], array_column($column['items'], 'name')];
            }, $row['columns']);
        }, $block['rows']);
        $this->assertSame([
            [[3, ['company']], [1, ['rank', 'active']]],
            [[4, ['owner']]],
        ], $shape);

        $company = $block['rows'][0]['columns'][0]['items'][0];
        $this->assertSame('field', $company['kind']);
        $this->assertSame(ColumnType::TEXT, $company['type']);
        $this->assertTrue($company['required']);
        $this->assertArrayNotHasKey('choices', $company);

        $rank = $block['rows'][0]['columns'][1]['items'][0];
        $this->assertSame([['value' => 'A', 'label' => 'A'], ['value' => 'B', 'label' => 'B']], $rank['choices']);
        $active = $block['rows'][0]['columns'][1]['items'][1];
        $this->assertSame(['1', '0'], array_column($active['choices'], 'value'));
        $owner = $block['rows'][1]['columns'][0]['items'][0];
        $this->assertSame(['table' => 'user'], $owner['reference']);
        // shown only while "active" is yes; an item without a condition has no key
        $this->assertSame(['all' => [['column' => 'active', 'operator' => 'eq', 'value' => '1']]], $owner['visible_if']);
        $this->assertArrayNotHasKey('visible_if', $company);
    }

    public function testAnUnknownScreenIsNotFound(): void
    {
        $this->api('screens/no-such-screen')->assertStatus(404);
    }

    public function testTheValueScopeIsNeeded(): void
    {
        $this->api('screens', [ApiScope::TABLE_READ])->assertStatus(403);
    }

    public function testAScreenOfATableTheUserMayNotOpenIsRefused(): void
    {
        $table = CustomTable::getEloquent('no_permission');
        $this->assertNotNull($table);
        $form = CustomForm::where('custom_table_id', $table->id)->first() ?? CustomForm::getDefault($table);

        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->be($user);
        System::clearRequestSession();
        if ($table->hasPermission(Permission::AVAILABLE_ACCESS_CUSTOM_VALUE)) {
            $this->markTestSkipped('user2 may open no_permission in this test data');
        }

        $token = $this->getUser2AccessToken();
        $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'studio', 'screens', $form->suuid))->assertStatus(403);
        $list = $this->withHeaders(['Authorization' => "Bearer $token"])->get(admin_urls('api', 'studio', 'screens'))->assertOk()->json('data');
        $this->assertNotContains('no_permission', array_column($list, 'table'));
    }
}
