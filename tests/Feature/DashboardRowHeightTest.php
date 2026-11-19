<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\DashboardType;
use Exceedone\Exment\Model\Dashboard;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #1473: the max height of the items of a dashboard row can be set on the dashboard setting page
 * (options.row1_height ...). The stylesheet stops an item at 400px, so a large image scrolled.
 *
 * Without the setting the page is the same as before: no style on the body of the item.
 */
class DashboardRowHeightTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const ITEM_BODY = 'class="box-body-inner-body box-body-inneritem"';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testItemsHaveNoHeightStyleWhenTheHeightIsNotSet(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row2' => 2, 'row3' => 0, 'row4' => 0]);

        $content = $this->getDashboardContent($dashboard);

        // 1 item on the first row and 2 on the second
        $this->assertSame(3, substr_count($content, self::ITEM_BODY));
        $this->assertSame(3, substr_count($content, self::ITEM_BODY . '></div>'));
        $this->assertStringNotContainsString('max-height:', $this->itemBodies($content));
    }

    public function testItemsOfTheRowHaveTheMaxHeight(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row1_height' => '800', 'row2' => 2, 'row3' => 0, 'row4' => 0]);

        $content = $this->getDashboardContent($dashboard);

        // only the item of the first row
        $this->assertSame(1, substr_count($content, self::ITEM_BODY . ' style="max-height: 800px;"></div>'));
        $this->assertSame(2, substr_count($content, self::ITEM_BODY . '></div>'));
    }

    public function testEachRowHasItsOwnMaxHeight(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row1_height' => 800, 'row2' => 2, 'row2_height' => 1200, 'row3' => 0, 'row4' => 0]);

        $content = $this->getDashboardContent($dashboard);

        $this->assertSame(1, substr_count($content, self::ITEM_BODY . ' style="max-height: 800px;"></div>'));
        $this->assertSame(2, substr_count($content, self::ITEM_BODY . ' style="max-height: 1200px;"></div>'));
    }

    /**
     * @param mixed $height
     */
    #[DataProvider('invalidHeightProvider')]
    public function testInvalidHeightIsIgnored($height): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row1_height' => $height, 'row2' => 0, 'row3' => 0, 'row4' => 0]);

        $content = $this->getDashboardContent($dashboard);

        $this->assertSame(1, substr_count($content, self::ITEM_BODY . '></div>'));
        $this->assertStringNotContainsString('max-height:', $this->itemBodies($content));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function invalidHeightProvider(): array
    {
        return [
            'null' => [null],
            'empty' => [''],
            'not a number' => ['800px;color:red'],
            'zero' => [0],
            'negative' => [-800],
            'too low' => [149],
            'too high' => [3001],
        ];
    }

    public function testSettingPageHasTheHeightOfEachRow(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row1_height' => 800, 'row2' => 0, 'row3' => 0, 'row4' => 0]);

        $response = $this->get(admin_urls('dashboard', $dashboard->id, 'edit'));
        $response->assertStatus(200);
        $content = (string) $response->getContent();

        $this->assertStringContainsString('row_setting[row1_height]', $content);
        $this->assertStringContainsString('row_setting[row2_height]', $content);
        $this->assertMatchesRegularExpression('/<input[^>]*name="row_setting\[row1_height\]"[^>]*value="800"|<input[^>]*value="800"[^>]*name="row_setting\[row1_height\]"/', $content);
    }

    public function testHeightIsSavedFromTheSettingPage(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0]);

        $response = $this->put(admin_urls('dashboard', $dashboard->id), [
            // as the setting page posts
            'dashboard_view_name' => $dashboard->dashboard_view_name,
            'dashboard_type' => (string) DashboardType::USER,
            'default_flg' => '0',
            'filter_bar_table' => '',
            'row_setting' => [
                'row1' => '1',
                'row1_height' => '900',
                'row2' => '2',
                'row2_height' => '',
                'row3' => '0',
                'row3_height' => '',
                'row4' => '0',
                'row4_height' => '',
            ],
        ]);
        $response->assertSessionHasNoErrors();

        $saved = Dashboard::find($dashboard->id);
        $this->assertInstanceOf(Dashboard::class, $saved);
        $this->assertEquals(900, $saved->getOption('row1_height'));
        $this->assertEquals(2, $saved->getOption('row2'));
        $this->assertTrue(is_nullorempty($saved->getOption('row2_height')));
    }

    public function testHeightOutOfTheRangeIsNotSaved(): void
    {
        $dashboard = $this->createDashboard(['row1' => 1, 'row2' => 0, 'row3' => 0, 'row4' => 0]);

        $this->put(admin_urls('dashboard', $dashboard->id), [
            // as the setting page posts
            'dashboard_view_name' => $dashboard->dashboard_view_name,
            'dashboard_type' => (string) DashboardType::USER,
            'default_flg' => '0',
            'filter_bar_table' => '',
            'row_setting' => [
                'row1' => '1',
                'row1_height' => '99999',
                'row2' => '0',
                'row3' => '0',
                'row4' => '0',
            ],
        ]);

        $saved = Dashboard::find($dashboard->id);
        $this->assertInstanceOf(Dashboard::class, $saved);
        $this->assertTrue(is_nullorempty($saved->getOption('row1_height')));
    }

    /**
     * @param array<string, mixed> $options
     */
    private function createDashboard(array $options): Dashboard
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);

        $dashboard = new Dashboard();
        $dashboard->dashboard_type = DashboardType::USER;
        $dashboard->dashboard_name = 'issue1473_' . short_uuid();
        $dashboard->dashboard_view_name = 'issue 1473 dashboard';
        $dashboard->created_user_id = $admin->base_user_id;
        $dashboard->options = $options;
        $dashboard->save();

        return $dashboard;
    }

    private function getDashboardContent(Dashboard $dashboard): string
    {
        $response = $this->followingRedirects()->get(admin_url('?dashboard=' . $dashboard->suuid));
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /**
     * The opening tags of the bodies of the items, where the height is set.
     */
    private function itemBodies(string $content): string
    {
        preg_match_all('/<div ' . preg_quote(self::ITEM_BODY, '/') . '[^>]*>/', $content, $matches);

        return implode("\n", $matches[0]);
    }
}
