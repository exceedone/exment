<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\DashboardBoxItems\SystemItems\News;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * The news box when the server cannot reach exment.net (a closed network): the box says so, and
 * the log gets one warning line an hour instead of an error with a stack trace on every view.
 */
class NewsUnreachableTest extends FeatureTestBase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::outside_api(true);
        System::clearCache();
        Cache::forget('exment_news_unreachable_logged');
        session()->forget([News::SESSION_KEY_FAILED, \Exceedone\Exment\Model\Define::SYSTEM_KEY_SESSION_UPDATE_NEWS]);
    }

    public function testTheBoxSaysItCouldNotReadAndTheLogHasOneLine(): void
    {
        // an address that cannot be resolved: the same as a server without the internet
        $mock = new \GuzzleHttp\Handler\MockHandler([
            new \GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host: exment.net', new \GuzzleHttp\Psr7\Request('GET', 'https://exment.net')),
            new \GuzzleHttp\Exception\ConnectException('cURL error 6: Could not resolve host: exment.net', new \GuzzleHttp\Psr7\Request('GET', 'https://exment.net')),
        ]);
        $this->app->instance(\GuzzleHttp\Client::class, new \GuzzleHttp\Client(['handler' => \GuzzleHttp\HandlerStack::create($mock)]));

        $log = Log::spy();
        $html = strval((new News())->body());
        $this->assertStringContainsString(e(exmtrans('error.news_unreachable')), $html);
        $log->shouldHaveReceived('warning')->once();
        $log->shouldNotHaveReceived('error');

        // asked again in the same session: not asked (the time out), the same message, no more lines
        $html = strval((new News())->body());
        $this->assertStringContainsString(e(exmtrans('error.news_unreachable')), $html);
        $log->shouldHaveReceived('warning')->once();

        // another session within the hour: asked again, but no more lines in the log
        session()->forget(News::SESSION_KEY_FAILED);
        $html = strval((new News())->body());
        $this->assertStringContainsString(e(exmtrans('error.news_unreachable')), $html);
        $log->shouldHaveReceived('warning')->once();
    }
}
