<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The header of a page is in the language of the user (a user who chose German on a Japanese
 * site), as the rest of the page: the controllers are made before the language of the user is
 * applied, so a header read when the controller is made would stay in the language of the site.
 */
class PageHeaderLocaleTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /** @var mixed */
    protected $localeBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->localeBefore = config('exment.locale');
        config(['exment.locale' => 'ja', 'exment.locale_from_browser' => false]);
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        Features::reset();
        config(['exment.locale' => $this->localeBefore, 'exment.locale_from_browser' => null]);
        parent::tearDown();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function pages(): array
    {
        return [
            'system' => ['system', 'system.system_header'],
            'table' => ['table', 'custom_table.header'],
            'role_group' => ['role_group', 'role_group.header'],
            'loginuser' => ['loginuser', 'user.header'],
        ];
    }

    /**
     * @dataProvider pages
     */
    public function testTheHeaderIsInTheLanguageOfTheUser(string $uri, string $key): void
    {
        $this->beGerman();

        $de = trans("exment::exment.$key", [], 'de');
        $ja = trans("exment::exment.$key", [], 'ja');
        $this->assertNotSame($de, $ja, "$key is translated");

        $response = $this->get(admin_url($uri))->assertOk();
        $html = (string) $response->getContent();
        $this->assertStringContainsString(e($de), $html, "$uri: the German header");
        $this->assertStringNotContainsString('<h1>' . e($ja), $html, "$uri: not the Japanese one");
    }

    /**
     * The pages of a table (AdminControllerTableBase): the header and the words of the page.
     *
     * @return array<string, array{0: string, 1: string, 2: string|null}>
     */
    public static function tablePages(): array
    {
        return [
            'data_maintenance' => ['data_maintenance/all_columns_table', 'data_maintenance.header', 'data_maintenance.attachments.header'],
            'column' => ['column/all_columns_table', 'custom_column.header', null],
            'view' => ['view/all_columns_table', 'custom_view.header', null],
        ];
    }

    /**
     * @dataProvider tablePages
     */
    public function testTheHeaderOfATablePageIsInTheLanguageOfTheUser(string $uri, string $key, ?string $bodyKey): void
    {
        Features::save([Features::DATA_MAINTENANCE => ['enabled' => true]]);
        $this->beGerman();

        $de = trans("exment::exment.$key", [], 'de');
        $ja = trans("exment::exment.$key", [], 'ja');
        $this->assertNotSame($de, $ja, "$key is translated");

        $html = (string) $this->get(admin_url($uri))->assertOk()->getContent();
        $this->assertStringContainsString(e($de) . ' : ', $html, "$uri: the German header");
        $this->assertStringNotContainsString(e($ja) . ' : ', $html, "$uri: not the Japanese one");
        if (isset($bodyKey)) {
            $body = trans("exment::exment.$bodyKey", [], 'de');
            $this->assertNotSame(trans("exment::exment.$bodyKey", [], 'en'), $body, "$bodyKey is in German");
            $this->assertStringContainsString(e($body), $html, "$uri: the German words of the page");
        }
    }

    protected function beGerman(): void
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user->setSettingValue(UserLocale::SETTING_KEY, 'de');
        UserLocale::clearCache();
        System::clearRequestSession();
        $this->be($user);
    }
}
