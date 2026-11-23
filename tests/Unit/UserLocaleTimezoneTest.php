<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Form\Field\Datetime as DatetimeField;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserSetting;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\UserLocale;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;

/**
 * The language and the time zone of each user (ISS02835).
 *
 * The site is in Asia/Tokyo; now is 2026-11-23 10:00 there, which is 2026-11-22 20:00 in America/New_York.
 */
class UserLocaleTimezoneTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const SITE_TZ = 'Asia/Tokyo';
    protected const USER_TZ = 'America/New_York';

    /** @var array<string, mixed> */
    protected $configBefore = [];
    /** @var string */
    protected $phpTimezoneBefore;
    /** @var string */
    protected $localeBefore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->configBefore = [
            'exment.timezone' => config('exment.timezone'),
            'app.timezone' => config('app.timezone'),
            'exment.locale' => config('exment.locale'),
            'exment.locale_from_browser' => config('exment.locale_from_browser'),
            'exment.locale_from_browser_in_tests' => config('exment.locale_from_browser_in_tests'),
        ];
        $this->phpTimezoneBefore = date_default_timezone_get();
        $this->localeBefore = \App::getLocale();

        // the language of the browser as on a site (the tests leave it out unless asked); no env override
        config(['exment.timezone' => static::SITE_TZ, 'app.timezone' => static::SITE_TZ, 'exment.locale' => 'ja',
            'exment.locale_from_browser' => null, 'exment.locale_from_browser_in_tests' => true]);
        date_default_timezone_set(static::SITE_TZ);
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', static::SITE_TZ));
        UserTimezone::setCurrent(null);
        UserLocale::clearCache();
    }

    protected function tearDown(): void
    {
        UserTimezone::setCurrent(null);
        Carbon::setTestNow();
        date_default_timezone_set($this->phpTimezoneBefore);
        config($this->configBefore);
        \App::setLocale($this->localeBefore);
        UserLocale::clearCache();

        parent::tearDown();
    }

    // the language ----------------------------------------------------

    public function testAcceptLanguageOnlyAmongAllowed(): void
    {
        $this->assertSame('en', UserLocale::fromAcceptLanguage('fr-FR,en-US;q=0.8,ja;q=0.5', ['ja', 'en']));
        $this->assertSame('ja', UserLocale::fromAcceptLanguage('fr-FR,ja;q=0.9,en;q=0.5', ['ja', 'en']));
        $this->assertNull(UserLocale::fromAcceptLanguage('fr-FR,de;q=0.5', ['ja', 'en']));
        // "-" and "_" are the same
        $this->assertSame('zh_CN', UserLocale::fromAcceptLanguage('zh-CN,zh;q=0.9', ['ja', 'en', 'zh_CN']));
        $this->assertSame('zh_CN', UserLocale::match('zh', ['ja', 'en', 'zh_CN']));
    }

    public function testInstalledLocalesAreTheLangFolders(): void
    {
        $installed = UserLocale::installed();
        $this->assertContains('ja', $installed);
        $this->assertContains('en', $installed);
        foreach ($installed as $locale) {
            $this->assertFileExists(exment_package_path("resources/lang/$locale/exment.php"));
        }
        // each by its own name
        $this->assertSame('English', UserLocale::label('en'));
    }

    /**
     * user setting → cookie of the login page → Accept-Language → the site.
     */
    public function testLocaleResolutionOrder(): void
    {
        $user = $this->getUser(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $other = $this->getUser(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->setUserSetting($user->id, ['locale' => 'en']);
        $this->setUserSetting($other->id, []);

        $request = $this->makeRequest('ja', 'ja');
        $this->assertSame('en', UserLocale::resolve($user->id, $request), 'the user setting first');

        $this->assertSame('en', UserLocale::resolve($other->id, $this->makeRequest('en', 'ja')), 'then the cookie of the login page');
        $this->assertSame('en', UserLocale::resolve($other->id, $this->makeRequest(null, 'fr,en;q=0.5')), 'then the browser');
        $this->assertSame('ja', UserLocale::resolve($other->id, $this->makeRequest(null, 'fr')), 'then the site');
        $this->assertSame('ja', UserLocale::resolve(null, null));
    }

    /**
     * The system setting "Use the browser's language when a user has not chosen one": on by default.
     * EXMENT_LOCALE_FROM_BROWSER wins over it when set.
     */
    public function testBrowserLanguageSetting(): void
    {
        $other = $this->getUser(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->setUserSetting($other->id, []);
        $english = $this->makeRequest(null, 'en-US,en;q=0.9');

        // default: on → an English browser on a ja site gets English
        System::where('system_name', 'locale_from_browser')->delete();
        System::clearCache();
        $this->assertTrue(UserLocale::useBrowserLanguage());
        $this->assertSame('en', UserLocale::resolve($other->id, $english));

        // off → the language of the site
        System::locale_from_browser(false);
        System::clearCache();
        try {
            $this->assertSame('ja', UserLocale::resolve($other->id, $english));
            // the env wins over the setting
            config(['exment.locale_from_browser' => true]);
            $this->assertSame('en', UserLocale::resolve($other->id, $english));
        } finally {
            config(['exment.locale_from_browser' => null]);
            System::locale_from_browser(true);
            System::clearCache();
        }
    }

    public function testAllowedLocales(): void
    {
        $user = $this->getUser(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->setUserSetting($user->id, ['locale' => 'en']);

        // a language the administrator does not allow is not used
        System::allowed_locales(['ja']);
        System::clearCache();
        try {
            $this->assertSame(['ja'], UserLocale::allowed());
            $this->assertSame('ja', UserLocale::resolve($user->id, $this->makeRequest(null, 'en')));
        } finally {
            System::allowed_locales([]);
            System::clearCache();
        }
    }

    /**
     * A mail is made in the language and the time zone of the user who receives it.
     */
    public function testMailUsesTheRecipientLocaleAndTimezone(): void
    {
        $user = $this->getUser(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->setUserSetting($user->id, ['locale' => 'en', 'timezone' => static::USER_TZ]);

        \App::setLocale('ja');
        $sender = new class (null, []) extends MailSender {
            /** @var array<int, string> */
            public $seen = [];

            protected function sendMail()
            {
                $this->seen = [\App::getLocale(), UserTimezone::user(), UserTimezone::formatToUser('2026-11-23 10:00:00')];
            }
        };
        $sender->user($user)->send();

        $this->assertSame(['en', static::USER_TZ, '2026-11-22 20:00:00'], $sender->seen);
        // and the page goes on in its own language and time zone
        $this->assertSame('ja', \App::getLocale());
        $this->assertSame(static::SITE_TZ, UserTimezone::user());
    }

    // the time zone ----------------------------------------------------

    public function testDatetimeDisplayAndInputRoundTrip(): void
    {
        // the same time zone: nothing changes
        $this->assertSame('2026-11-23 10:00:00', UserTimezone::formatToUser('2026-11-23 10:00:00'));
        $this->assertSame('2026-11-23 10:00:00', UserTimezone::formatFromUser('2026-11-23 10:00:00'));

        UserTimezone::setCurrent(static::USER_TZ);
        $this->assertSame('2026-11-22 20:00:00', UserTimezone::formatToUser('2026-11-23 10:00:00'));
        $this->assertSame('2026-11-23 10:00:00', UserTimezone::formatFromUser('2026-11-22 20:00:00'));

        // the input of a form: shown in the time zone of the user, saved in the time zone of the site
        $field = new DatetimeField('value.datetime', ['datetime']);
        $field->fill(['value' => ['datetime' => '2026-11-23 10:00:00']]);
        $this->assertSame('2026-11-22 20:00:00', $field->value());
        $this->assertSame('2026-11-23 10:00:00', $field->prepare($field->value()));

        // the text of a date and time column
        $item = $this->getColumn('datetime')->column_item;
        $this->assertStringStartsWith('2026-11-22 20:00', (string) $this->callProtectedMethod($item, '_text', '2026-11-23 10:00:00'));
    }

    public function testDateColumnsAreNotConverted(): void
    {
        UserTimezone::setCurrent(static::USER_TZ);

        $item = $this->getColumn('date')->column_item;
        $this->assertStringStartsWith('2026-11-23', (string) $this->callProtectedMethod($item, '_text', '2026-11-23'));

        // "today" of a date column: the date of the user, without a time
        $query = $this->getQuery()->whereDateExment('value->date', UserTimezone::today(), false);
        $this->assertSame(['2026-11-22', '2026-11-22'], array_slice($query->getQuery()->getBindings(), -2));
    }

    /**
     * "Today" for a user in New York (2026-11-22 there): from 00:00 of New York, in the time of Tokyo.
     */
    public function testTodayFilterForAUserInAnotherTimezone(): void
    {
        UserTimezone::setCurrent(static::USER_TZ);
        $this->assertSame('2026-11-22', UserTimezone::today()->format('Y-m-d'));

        $item = $this->getColumn('datetime')->column_item;
        $query = $this->getQuery();
        $filter = ViewFilterBase::make(FilterOption::DAY_TODAY, $item);
        $this->assertNotNull($filter);
        $filter->setFilter($query, null);
        $this->assertSame(['2026-11-22 14:00:00', '2026-11-23 14:00:00'], array_slice($query->getQuery()->getBindings(), -2));

        // the conditions of the memory (forms, workflows, notifications) too
        $this->assertTrue($filter->compareValue('2026-11-23 10:00:00', null), '20:00 of the 22nd in New York');
        $this->assertFalse($filter->compareValue('2026-11-23 15:00:00', null), '01:00 of the 23rd in New York');

        // the same time zone as the site: as before
        UserTimezone::setCurrent(null);
        $query = $this->getQuery();
        ViewFilterBase::make(FilterOption::DAY_TODAY, $item)->setFilter($query, null);
        $this->assertSame(['2026-11-23', '2026-11-24'], array_slice($query->getQuery()->getBindings(), -2));
    }

    // ----------------------------------------------------

    /**
     * @return \Exceedone\Exment\Model\CustomValue
     */
    protected function getUser(string $loginId)
    {
        $user = \Exceedone\Exment\Model\LoginUser::find($loginId);
        $this->assertNotNull($user);
        return $user->base_user;
    }

    /**
     * @param array<string, mixed> $values
     */
    protected function setUserSetting(int $userId, array $values): void
    {
        /** @var UserSetting $setting */
        $setting = UserSetting::firstOrCreate(['base_user_id' => $userId]);
        $settings = $setting->settings ?? [];
        array_set($settings, 'user_setting', $values);
        $setting->settings = $settings;
        $setting->save();
        UserLocale::clearCache();
    }

    protected function makeRequest(?string $cookie, ?string $acceptLanguage): Request
    {
        return Request::create('/', 'GET', [], $cookie ? [UserLocale::COOKIE_NAME => $cookie] : [], [], $acceptLanguage ? ['HTTP_ACCEPT_LANGUAGE' => $acceptLanguage] : []);
    }

    protected function getColumn(string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST));
        $this->assertNotNull($column, "column $name");
        return $column;
    }

    /**
     * The query of the records (an Eloquent builder with the "Exment" where methods).
     *
     * @return mixed
     */
    protected function getQuery()
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST)->getValueQuery();
    }
}
