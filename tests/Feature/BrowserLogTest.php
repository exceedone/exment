<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\LogManagement\BrowserLogs;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The log of the browsers (ISS02904): off, nothing is sent and nothing taken; on, an error and a
 * failed request are kept (written after the response) and seen in the log management, without a
 * value typed, a mail address or the values of a query; the pages opened only when chosen; at most
 * MAX_ROWS a user.
 */
class BrowserLogTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        BrowserLog::resetTableExists();
        LogCatalog::reset();
        $this->setFeature(false);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        RateLimiter::clear('exment-browser-log:' . strval($this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id));
    }

    protected function tearDown(): void
    {
        $this->setFeature(false);
        System::browser_log_pages(false);
        System::clearCache();
        parent::tearDown();
    }

    protected function setFeature(bool $on): void
    {
        config(['exment.features_locked' => '']);
        System::features([BrowserLogs::FEATURE => ['enabled' => $on, 'role_groups' => null]]);
        System::clearRequestSession();
        Features::reset();
    }

    /**
     * @param int|string $id
     */
    protected function user($id): LoginUser
    {
        /** @var LoginUser $user */
        $user = LoginUser::findOrFail($id);
        return $user;
    }

    /**
     * @param int|string $id
     */
    protected function loginAs($id): void
    {
        $this->be($this->user($id));
        System::clearRequestSession();
    }

    /**
     * @param array<int, array<string, mixed>> $events
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function send(array $events): \Illuminate\Testing\TestResponse
    {
        return $this->post(admin_urls('webapi', 'browser_log'), [
            'events' => json_encode($events),
            'env' => json_encode(['tab' => '0123456789abcdef', 'browser' => 'Chrome 131', 'os' => 'Windows 10.0', 'screen' => '1920x1080 1280x720', 'lang' => 'ja-JP']),
        ], static::AJAX);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function events(): array
    {
        return [
            [
                'kind' => 'error',
                'path' => 'https://example.com/admin/data/user?q=TypedValue7319&page=2',
                'message' => "Uncaught TypeError: Cannot read properties of undefined (reading 'value') near \"TypedValue7319 here\" for hanako7319@example.com id 98765432",
                'source' => "https://example.com/vendor/exment/js/common.js?ver=7.3.0:10:5\n    at f (https://example.com/vendor/exment/js/getbox.js:1:2)",
            ],
            ['kind' => 'request', 'path' => '/admin/data/user?q=TypedValue7319&page=2', 'status' => 500, 'duration' => 120, 'method' => 'POST'],
            ['kind' => 'page', 'path' => '/admin/data/user', 'duration' => 340],
        ];
    }

    public function testOffNothingIsSentNorTaken(): void
    {
        $this->get(admin_url(''))->assertOk()->assertDontSee('browser_log.js', false);
        $before = BrowserLog::query()->count();
        $this->send($this->events())->assertStatus(403);
        $this->assertSame($before, BrowserLog::query()->count());
    }

    public function testAnErrorIsKeptAndSeen(): void
    {
        $this->setFeature(true);
        $this->get(admin_url(''))->assertOk()->assertSee('browser_log.js', false);

        $last = intval(BrowserLog::query()->max('id'));
        $this->send($this->events())->assertStatus(204);
        $rows = BrowserLog::query()->where('id', '>', $last)->orderBy('id')->get();
        // the page opened: not chosen
        $this->assertSame([BrowserLog::KIND_ERROR, BrowserLog::KIND_REQUEST], $rows->pluck('kind')->all());

        /** @var BrowserLog $error */
        $error = $rows[0];
        $this->assertSame('/admin/data/user?q=***&page=2', $error->path);
        $this->assertStringContainsString("reading 'value'", strval($error->message), 'a name of the code stays');
        $this->assertSame('/vendor/exment/js/common.js:10:5 < /vendor/exment/js/getbox.js:1:2', $error->source);
        $this->assertSame('Chrome 131', $error->browser);
        $this->assertSame('0123456789abcdef', $error->tab);
        $this->assertEquals($this->user(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id, $error->base_user_id);

        /** @var BrowserLog $request */
        $request = $rows[1];
        $this->assertSame(500, $request->status);
        $this->assertSame(120, $request->duration_ms);
        $this->assertNull($request->message);

        $text = strval(json_encode($rows->toArray(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        foreach (['TypedValue7319', 'hanako7319@example.com', '98765432', 'example.com/', 'ver=7.3.0'] as $secret) {
            $this->assertStringNotContainsString($secret, $text, 'not kept: ' . $secret);
        }

        // seen in the log management
        $this->get(admin_urls('log_management', 'browser'))->assertOk()->assertSee("reading 'value'")->assertSee('/vendor/exment/js/common.js:10:5', false);
        $this->get(admin_urls_query('log_management', 'browser', ['kind' => 'request']))->assertOk()->assertDontSee("reading 'value'");
        $this->get(admin_url('log_management'))->assertOk()->assertSee(admin_urls('log_management', 'browser'), false);

        // not for a user without Audit
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $denied = $this->get(admin_urls('log_management', 'browser'), static::AJAX);
        $denied->assertStatus(403);
        $denied->assertDontSee("reading 'value'");
    }

    public function testThePagesWhenChosen(): void
    {
        $this->setFeature(true);
        System::browser_log_pages(true);
        System::clearCache();
        $last = intval(BrowserLog::query()->max('id'));
        $this->send($this->events())->assertStatus(204);
        $this->assertSame(1, BrowserLog::query()->where('id', '>', $last)->where('kind', BrowserLog::KIND_PAGE)->count());
        $this->assertSame(340, BrowserLog::query()->where('id', '>', $last)->where('kind', BrowserLog::KIND_PAGE)->value('duration_ms'));
    }

    public function testAtMostTheRowsOfAUser(): void
    {
        $this->setFeature(true);
        $last = intval(BrowserLog::query()->max('id'));
        $error = ['kind' => 'error', 'path' => '/admin', 'message' => 'Uncaught Error: x'];
        $sendings = intdiv(BrowserLogs::MAX_ROWS, BrowserLogs::MAX_BATCH) + 1;
        for ($i = 0; $i < $sendings; $i++) {
            // more than a sending takes: cut
            $this->send(array_fill(0, BrowserLogs::MAX_BATCH + 5, $error))->assertStatus(204);
        }
        $this->assertSame(BrowserLogs::MAX_ROWS, BrowserLog::query()->where('id', '>', $last)->count());
    }

    public function testTheSettings(): void
    {
        $this->post(admin_urls('log_management', 'browser', 'setting'), ['browser_log_pages' => '1', 'log_retention_browser_log' => '7'])->assertRedirect();
        System::clearCache();
        LogCatalog::reset();
        $this->assertTrue(BrowserLogs::keepsPages());
        $this->assertSame(7, LogCatalog::types()['browser_log']['retention_days']);

        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->post(admin_urls('log_management', 'browser', 'setting'), ['browser_log_pages' => '0'], static::AJAX)->assertStatus(403);
        System::clearCache();
        $this->assertTrue(BrowserLogs::keepsPages(), 'not changed');
    }
}
