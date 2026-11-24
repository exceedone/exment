<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Model\ApiClientRepository;
use Exceedone\Exment\Model\ApiRateRaise;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\ApiRateRaises;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Temporary raises of the API rate limit (ISS00442): the "api" rate limiter uses a raise of
 * the user or of the API client until its time, never over the ceiling of a SaaS; the
 * others keep the limit of the site; the page is for the system administrators only.
 */
class ApiRateRaiseTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const ROUTE = 'exment-api-rate-raise-test';

    /** @var string[] the keys of the rate limiter the test used */
    protected array $limiterKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config([
            'exment.features_locked' => '',
            'exment.api_max_rate_limit' => 2,
            'exment.api_max_rate_limit_ceiling' => null,
            'exment.api_rate_raise_max_hours' => 24,
        ]);
        $this->useFeature(true);
        ApiRateRaise::query()->delete();
        ApiRateRaises::forget();
        Route::middleware('api')->get(static::ROUTE, function () {
            return 'ok';
        });
    }

    protected function tearDown(): void
    {
        foreach ($this->limiterKeys as $key) {
            RateLimiter::clear($key);
        }
        ApiRateRaises::forget();
        Features::reset();
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function useFeature(bool $enabled): void
    {
        $settings = Features::settings();
        $settings[ApiRateRaises::FEATURE] = ['enabled' => $enabled, 'role_groups' => null, 'menu' => true];
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
        ApiRateRaises::forget();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        // the counts of the "api" limiter of the user (ThrottleRequests: md5 of the name and the key)
        $this->limiterKeys[] = md5('api' . $user->base_user_id);
        RateLimiter::clear(md5('api' . $user->base_user_id));
        return $user;
    }

    protected function userId(string $loginId): int
    {
        return intval(LoginUser::findOrFail($loginId)->base_user_id);
    }

    protected function raise(string $type, int|string $id, int $perMinute, int $hours = 1): ApiRateRaise
    {
        return ApiRateRaises::add([
            'target_type' => $type,
            'target_id' => $id,
            'per_minute' => $perMinute,
            'hours' => $hours,
            'reason' => 'test',
        ], null);
    }

    /**
     * Calls the API route until it is refused: how many passed (at most $max).
     *
     * @param array<string, string> $headers
     */
    protected function passed(int $max, array $headers = []): int
    {
        for ($i = 0; $i < $max; $i++) {
            $status = $this->get(static::ROUTE, $headers)->getStatusCode();
            if ($status === 429) {
                return $i;
            }
            $this->assertSame(200, $status);
        }
        return $max;
    }

    public function testTheRaisedLimitUntilItsTime(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->raise(ApiRateRaise::TARGET_USER, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 5);
        $this->assertSame(5, $this->passed(10));

        // after its time (and the minute of the counts): the limit of the site again
        Carbon::setTestNow(Carbon::now()->addHours(2));
        $this->assertSame(2, $this->passed(10));
        // it stays in the list
        $this->assertSame(1, ApiRateRaise::query()->count());
    }

    public function testTheOtherUsersKeepTheLimit(): void
    {
        $this->raise(ApiRateRaise::TARGET_USER, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 5);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertSame(2, $this->passed(10));
        $this->assertSame(2, ApiRateRaises::perMinute(2, $this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));
        $this->assertSame(5, ApiRateRaises::perMinute(2, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1)));
    }

    public function testNotUsedWhileTheFeatureIsOff(): void
    {
        $this->raise(ApiRateRaise::TARGET_USER, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 5);
        $this->useFeature(false);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertSame(2, $this->passed(10));

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_rate_raises'))->assertNotFound();
        $this->post(admin_url('api_rate_raises'), ['target_type' => 'user', 'user_id' => 1, 'per_minute' => 10, 'hours' => 1])->assertNotFound();
    }

    public function testARaiseOfTheUserOfAnApiKey(): void
    {
        [, $plain] = UserApiKeys::create($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'raise test', UserApiKeys::readOnlyScopes());
        $this->limiterKeys[] = md5('api' . 'apikey-ip:' . UserApiKeys::siteHash() . ':127.0.0.1');
        $this->limiterKeys[] = md5('api' . UserApiKeys::limiterKey($plain));
        foreach ($this->limiterKeys as $key) {
            RateLimiter::clear($key);
        }
        $headers = ['Authorization' => 'Bearer ' . $plain];

        $this->assertNull(ApiRateRaises::userOfApiKey($plain), 'no raise of a user: the key is not read');
        $this->raise(ApiRateRaise::TARGET_USER, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 4);
        $this->assertSame($this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), ApiRateRaises::userOfApiKey($plain));
        $this->assertSame(4, $this->passed(10, $headers));
    }

    public function testARaiseOfAnApiClient(): void
    {
        $client = (new ApiClientRepository())->createApiKey(1, 'raise test client', 'http://localhost');
        $this->raise(ApiRateRaise::TARGET_CLIENT, $client->id, 30);
        $this->assertSame(30, ApiRateRaises::perMinute(2, null, $client->id));
        $this->assertSame(2, ApiRateRaises::perMinute(2, null, 'another-client'));
        // the higher of the user and the client
        $this->raise(ApiRateRaise::TARGET_USER, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 50);
        $this->assertSame(50, ApiRateRaises::perMinute(2, $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), $client->id));
    }

    public function testTheCeiling(): void
    {
        $userId = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        config(['exment.api_max_rate_limit_ceiling' => 100]);
        $input = ['target_type' => 'user', 'target_id' => $userId, 'per_minute' => 101, 'hours' => 1];
        $this->assertSame(strval(exmtrans('api_rate_raise.errors.ceiling', ['ceiling' => 100])), ApiRateRaises::validate($input));
        $input['per_minute'] = 100;
        $this->assertNull(ApiRateRaises::validate($input));

        // refused on the page too
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->post(admin_url('api_rate_raises'), ['target_type' => 'user', 'user_id' => $userId, 'per_minute' => 101, 'hours' => 1])
            ->assertRedirect(admin_url('api_rate_raises'));
        $this->assertSame(0, ApiRateRaise::query()->count());

        // a raise made before the ceiling was lowered gives no more than it
        config(['exment.api_max_rate_limit_ceiling' => null]);
        $this->raise(ApiRateRaise::TARGET_USER, $userId, 500);
        config(['exment.api_max_rate_limit_ceiling' => 100]);
        $this->assertSame(100, ApiRateRaises::perMinute(2, $userId));

        // no ceiling (on premises): as the administrator chose
        config(['exment.api_max_rate_limit_ceiling' => '']);
        $this->assertSame(500, ApiRateRaises::perMinute(2, $userId));
    }

    public function testWhatARaiseMustBe(): void
    {
        $userId = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $ok = ['target_type' => 'user', 'target_id' => $userId, 'per_minute' => 10, 'hours' => 24];
        $this->assertNull(ApiRateRaises::validate($ok));
        // not more than the limit of the site
        $this->assertNotNull(ApiRateRaises::validate(['per_minute' => 2] + $ok));
        $this->assertNotNull(ApiRateRaises::validate(['per_minute' => 'x'] + $ok));
        // not longer than the most hours
        $this->assertNotNull(ApiRateRaises::validate(['hours' => 25] + $ok));
        $this->assertNotNull(ApiRateRaises::validate(['hours' => 0] + $ok));
        // a user or a client that is there
        $this->assertNotNull(ApiRateRaises::validate(['target_id' => 999999] + $ok));
        $this->assertNotNull(ApiRateRaises::validate(['target_type' => 'client', 'target_id' => 'no-such-client'] + $ok));
        $this->assertNotNull(ApiRateRaises::validate(['target_type' => 'other'] + $ok));
    }

    public function testThePageAddsListsAndDeletes(): void
    {
        Log::spy();
        $userId = $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_rate_raises'))->assertOk()->assertSee('api-rate-raise-empty', false);
        // the link on the page of the API settings
        $this->get(admin_url('api_setting'))->assertOk()->assertSee(admin_url('api_rate_raises'), false);

        $this->post(admin_url('api_rate_raises'), [
            'target_type' => 'user', 'user_id' => $userId, 'per_minute' => 600, 'hours' => 3, 'reason' => 'import from Backlog',
        ])->assertRedirect(admin_url('api_rate_raises'));
        /** @var ApiRateRaise $raise */
        $raise = ApiRateRaise::query()->firstOrFail();
        $this->assertSame(600, $raise->per_minute);
        $this->assertSame(strval($userId), $raise->target_id);
        $this->assertSame($this->userId(TestDefine::TESTDATA_USER_LOGINID_ADMIN), $raise->created_user_id);
        $this->assertEqualsWithDelta(Carbon::now()->addHours(3)->getTimestamp(), $raise->expires_at->getTimestamp(), 5);
        $this->assertSame(600, ApiRateRaises::perMinute(2, $userId));
        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return str_contains(strval($message), 'API rate limit raise added');
        })->once();

        $page = $this->get(admin_url('api_rate_raises'))->assertOk();
        $page->assertSee('class="api-rate-raise api-rate-raise-active"', false);
        $page->assertSee('import from Backlog');

        // past its time: still in the list
        Carbon::setTestNow(Carbon::now()->addHours(4));
        $this->get(admin_url('api_rate_raises'))->assertOk()->assertSee('api-rate-raise-expired', false);
        $this->assertSame(2, ApiRateRaises::perMinute(2, $userId));
        Carbon::setTestNow();

        $this->post(admin_urls('api_rate_raises', $raise->id, 'delete'))->assertRedirect(admin_url('api_rate_raises'));
        $this->assertSame(0, ApiRateRaise::query()->count());
        $this->assertSame(2, ApiRateRaises::perMinute(2, $userId));
        Log::shouldHaveReceived('info')->withArgs(function ($message) {
            return str_contains(strval($message), 'API rate limit raise deleted');
        })->once();
    }

    public function testOnlyTheAdministrators(): void
    {
        // (ajax: admin-core answers 403; without it, it sends the page and stops)
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('api_rate_raises'), $ajax)->assertForbidden();
        $this->post(admin_url('api_rate_raises'), ['target_type' => 'user', 'user_id' => $this->userId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'per_minute' => 600, 'hours' => 1], $ajax)->assertForbidden();
        $this->assertSame(0, ApiRateRaise::query()->count());
    }
}
