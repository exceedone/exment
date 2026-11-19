<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\URL;

class SafetyCheckWebAnswerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        auth('admin')->logout();
    }

    /**
     * @param int|string $eventId
     */
    protected function signedAnswerUrl($eventId, int $userId): string
    {
        return URL::signedRoute('exment.safety_answer', ['event' => $eventId, 'user' => $userId]);
    }

    public function testGetShowsFormWithValidSignature(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.status_safe'));
        $response->assertSee(exmtrans('safety.status_minor_injury'));
        $response->assertSee(exmtrans('safety.status_need_help'));
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testAnswerFormUsesBootstrap5Controls(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertStatus(200);
        $response->assertSee('class="form-check-input"', false);
        $response->assertSee('class="form-check-label"', false);
        $response->assertSee('btn btn-primary w-100', false);
        $response->assertDontSee('class="radio"', false);
    }

    public function testAnswerRadiosAreRequiredSoEmptySubmitStaysOnForm(): void
    {
        // Without `required` the browser submits an empty answer and the user lands on a bare 422 page.
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $html = (string) $this->get($this->signedAnswerUrl($event->id, $userId))->getContent();

        preg_match_all('/<input[^>]*type="radio"[^>]*name="st"[^>]*>/', $html, $radios);
        $this->assertCount(3, $radios[0], 'one radio per answer status (safe / minor_injury / need_help)');
        foreach ($radios[0] as $radio) {
            $this->assertMatchesRegularExpression('/\srequired[\s>]/', $radio);
        }
    }

    public function testInvalidSignatureRejected(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $url = $this->signedAnswerUrl($event->id, $userId);
        $tampered = str_replace('user=' . $userId, 'user=' . ((int) TestDefine::TESTDATA_USER_LOGINID_USER1), $url);

        $this->get($tampered)->assertStatus(403)->assertSee(exmtrans('safety.answer_invalid_link'));
        $this->get('admin/safety/answer?event=' . $event->id . '&user=' . $userId)->assertStatus(403);
    }

    public function testMailLinkExpiresAfterTheSetDays(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = SafetyCheckSender::answerUrl($event->id, $userId);

        $this->get($url)->assertStatus(200);

        Carbon::setTestNow(now()->addDays(7)->addMinute());
        try {
            $this->get($url)->assertStatus(403)->assertSee(exmtrans('safety.answer_link_expired'));
            $this->post($url, ['st' => 'safe'])->assertStatus(403);
        } finally {
            Carbon::setTestNow();
        }
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'), 'An expired link must not answer.');
    }

    public function testMailLinkExpiryFollowsTheSetting(): void
    {
        System::safety_check_answer_url_expire_days(2);
        System::clearCache();

        parse_str((string) parse_url(SafetyCheckSender::answerUrl(1, 1), PHP_URL_QUERY), $query);

        $this->assertEqualsWithDelta(now()->addDays(2)->getTimestamp(), (int) array_get($query, 'expires'), 5);
    }

    public function testEmptyExpirySettingFallsBackToTheDefaultNotToNoExpiry(): void
    {
        // a field left empty is saved as null
        System::safety_check_answer_url_expire_days(null);
        System::clearCache();

        parse_str((string) parse_url(SafetyCheckSender::answerUrl(1, 1), PHP_URL_QUERY), $query);

        $this->assertEqualsWithDelta(now()->addDays(7)->getTimestamp(), (int) array_get($query, 'expires'), 5);
    }

    public function testHugeExpirySettingIsCappedInsteadOfExpiringEveryLink(): void
    {
        System::safety_check_answer_url_expire_days(99999999);
        System::clearCache();

        parse_str((string) parse_url(SafetyCheckSender::answerUrl(1, 1), PHP_URL_QUERY), $query);

        $this->assertEqualsWithDelta(now()->addDays(SafetyCheckSender::ANSWER_URL_MAX_EXPIRE_DAYS)->getTimestamp(), (int) array_get($query, 'expires'), 5);
    }

    public function testMailLinkOfDeletedUserNoLongerAnswers(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);
        getModelName(SystemTableName::USER)::find($userId)->delete();

        $this->get($url)->assertStatus(404)->assertSee(exmtrans('safety.answer_invalid_link'));
        $this->post($url, ['st' => 'safe'])->assertStatus(404);
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'), 'A deleted user must not answer, as on LINE.');
    }

    public function testMailLinkHasNoExpiryWhenSetToZero(): void
    {
        System::safety_check_answer_url_expire_days(0);
        System::clearCache();
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $url = SafetyCheckSender::answerUrl($event->id, $userId);

        $this->assertStringNotContainsString('expires=', $url);
        Carbon::setTestNow(now()->addYear());
        try {
            $this->get($url)->assertStatus(200);
        } finally {
            Carbon::setTestNow();
        }
    }

    public function testPostRecordsAnswerWithChannelMail(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $response = $this->post($this->signedAnswerUrl($event->id, $userId), [
            'st' => 'minor_injury',
            'comment' => 'leg injured',
        ]);

        $response->assertStatus(200);
        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('minor_injury', $fresh->getValue('answer_status'));
        $this->assertEquals('mail', $fresh->getValue('channel'));
        $this->assertNotNull($fresh->getValue('answered_at'));
        $this->assertStringContainsString('leg injured', (string) $fresh->getValue('comment'));
    }

    public function testPostAgainUpdatesAnswer(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $this->post($url, ['st' => 'safe']);
        $this->post($url, ['st' => 'need_help']);

        $this->assertEquals('need_help', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testClosedEventBlocksGetAndPost(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent(['event_status' => 'closed']);
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $this->get($url)->assertStatus(200)->assertSee(exmtrans('safety.answer_closed'));
        $this->post($url, ['st' => 'safe'])->assertStatus(200)->assertSee(exmtrans('safety.answer_closed'));
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testInvalidStatusRejected(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $response = $this->post($this->signedAnswerUrl($event->id, $userId), ['st' => 'hacked']);
        $response->assertStatus(422);
        $response->assertSee(exmtrans('safety.answer_invalid_status'));
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testMissingRowShowsError(): void
    {
        $event = $this->createEvent();
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;

        $this->post($this->signedAnswerUrl($event->id, $userId), ['st' => 'safe'])->assertStatus(404);
    }

    public function testAnsweredRowShowsCurrentAnswerAndAnsweredAt(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId, [
            'answer_status' => 'safe',
            'answered_at'   => '2026-08-15 10:30:00',
        ]);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.answer_current'));
        $response->assertSee(exmtrans('safety.status_safe'));
        $response->assertSee((string) $this->freshAnswerRow($row->id)->getValue('answered_at'));
    }

    public function testSubmitDoesNotClaimSuccessWhenRecordAnswerFails(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $deleted = false;
        $model = getModelName('safety_check_answer');
        $model::retrieved(function ($row_model) use (&$deleted, $row) {
            if (!$deleted && (int) $row_model->getKey() === (int) $row->id) {
                $deleted = true;
                \DB::table($row_model->getTable())->where($row_model->getKeyName(), $row_model->getKey())->delete();
            }
        });

        $response = $this->post($url, ['st' => 'safe']);

        $response->assertStatus(404);
        $response->assertSee(exmtrans('safety.answer_invalid_link'));
        $response->assertDontSee(exmtrans('safety.answer_done_title'));
    }

    public function testSignedUrlBypassesWebIpFilter(): void
    {
        System::web_ip_filters('203.0.113.5');

        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.answer_submit'));
        $response->assertDontSee(exmtrans('error.ip_address_filtered'));
    }

    public function testLoggedInNonAdminUserGetsAnswerFormAndCanAnswer(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $loginUser = LoginUser::find($userId);
        $this->assertNotNull($loginUser, 'Fixture: a non-admin login user is required.');
        $this->be($loginUser, 'admin');

        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $response = $this->get($url);
        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.answer_submit'));
        $response->assertDontSee(trans('admin.deny'));

        $response = $this->post($url, ['st' => 'safe']);
        $response->assertStatus(200);
        $response->assertDontSee(trans('admin.deny'));
        $response->assertSee(exmtrans('safety.answer_done_title'));

        $fresh = getModelName('safety_check_answer')::withoutGlobalScope(CustomValueModelScope::class)->find($row->id);
        $this->assertEquals('safe', $fresh->getValue('answer_status'));
        $this->assertEquals('mail', $fresh->getValue('channel'));
    }

    public function testEventMissingReturns404ForGetAndPost(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $url = $this->signedAnswerUrl(999999999, $userId);

        $this->get($url)->assertStatus(404);
        $this->post($url, ['st' => 'safe'])->assertStatus(404);
    }

    public function testMissingRowOnGetShowsError(): void
    {
        $event = $this->createEvent();
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;

        $this->get($this->signedAnswerUrl($event->id, $userId))->assertStatus(404);
    }

    public function testCsrfMiddlewareExemptsSafetyAnswerPath(): void
    {
        $middleware = new \Exceedone\Exment\Middleware\VerifyCsrfToken(app(), app('encrypter'));
        $request = \Illuminate\Http\Request::create(admin_url('safety/answer'), 'POST');

        $method = new \ReflectionMethod($middleware, 'inExceptArray');
        $method->setAccessible(true);

        $this->assertTrue(
            $method->invoke($middleware, $request),
            'safety/answer must be exempted from CSRF verification (see VerifyCsrfToken::$except).'
        );
    }

    public function testResponsesCarryNoStoreAndNoindexHeaders(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }
    public function testAnswerPageIsNotWrittenToOperationLog(): void
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);
        $before = OperationLog::count();

        $this->get($url)->assertStatus(200);
        $this->post($url, ['st' => 'safe', 'comment' => 'secret detail'])->assertStatus(200);

        $this->assertEquals($before, OperationLog::count(), 'safety/answer requests must not be written to admin_operation_log.');
        $this->assertEquals(0, OperationLog::where('path', 'like', '%safety/answer%')->count());
    }
}
