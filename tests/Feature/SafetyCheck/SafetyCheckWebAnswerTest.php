<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\URL;

/**
 * Mail-fallback web answer page: a signed URL (no login) shows the 3-status form
 * on GET and records the answer on POST. GET must never write (mail scanners
 * prefetch links); the signature pins (event, user); a closed event blocks both.
 */
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
        // KHÔNG login user nào — trang này phục vụ user chưa có login
        auth('admin')->logout();
    }

    protected function signedAnswerUrl($eventId, int $userId): string
    {
        return URL::signedRoute('exment.safety_answer', ['event' => $eventId, 'user' => $userId]);
    }

    public function testGetShowsFormWithValidSignature()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.status_safe'));
        $response->assertSee(exmtrans('safety.status_minor_injury'));
        $response->assertSee(exmtrans('safety.status_need_help'));
        // GET không được ghi gì
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testInvalidSignatureRejected()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $url = $this->signedAnswerUrl($event->id, $userId);
        // đổi user id -> chữ ký sai
        $tampered = str_replace('user=' . $userId, 'user=' . ((int) TestDefine::TESTDATA_USER_LOGINID_USER1), $url);

        $this->get($tampered)->assertStatus(403);
        $this->get('admin/safety/answer?event=' . $event->id . '&user=' . $userId)->assertStatus(403);
    }

    public function testPostRecordsAnswerWithChannelMail()
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

    public function testPostAgainUpdatesAnswer()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $this->post($url, ['st' => 'safe']);
        $this->post($url, ['st' => 'need_help']);

        $this->assertEquals('need_help', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testClosedEventBlocksGetAndPost()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent(['event_status' => 'closed']);
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        $this->get($url)->assertStatus(200)->assertSee(exmtrans('safety.answer_closed'));
        $this->post($url, ['st' => 'safe'])->assertStatus(200)->assertSee(exmtrans('safety.answer_closed'));
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testInvalidStatusRejected()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $response = $this->post($this->signedAnswerUrl($event->id, $userId), ['st' => 'hacked']);
        $response->assertStatus(422);
        $response->assertSee(exmtrans('safety.answer_invalid_status'));
        $this->assertEquals('not_answered', $this->freshAnswerRow($row->id)->getValue('answer_status'));
    }

    public function testMissingRowShowsError()
    {
        $event = $this->createEvent();
        // không tạo answer row cho user này
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;

        $this->post($this->signedAnswerUrl($event->id, $userId), ['st' => 'safe'])->assertStatus(404);
    }

    /**
     * Fix round 1, item 2: an already-answered row must preselect AND show the
     * previous answered_at on the form, not just silently pre-check a radio.
     */
    public function testAnsweredRowShowsCurrentAnswerAndAnsweredAt()
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

    /**
     * Fix round 1, item 3: recordAnswer's return value must be checked. If the
     * row disappears between resolve()'s gate check (currentAnswer) and
     * submit()'s own write (recordAnswer) — e.g. a concurrent delete — the
     * controller must NOT render the "done" page; it must surface an error
     * instead of silently claiming success.
     */
    public function testSubmitDoesNotClaimSuccessWhenRecordAnswerFails()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        $url = $this->signedAnswerUrl($event->id, $userId);

        // Simulate the race: delete the underlying row the instant it is first
        // retrieved (that first retrieval is resolve()'s own currentAnswer()
        // gate check), so it is already gone by the time submit()'s callback
        // calls recordAnswer()'s own (separate, locked) lookup.
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

    /**
     * Fix round 1, item 1: admin_anonymous includes admin.web-ipfilter, which
     * would block exactly the users this page exists for (answering from home
     * or mobile, off any office network, during a disaster). The signed URL is
     * the gate here, not the IP allowlist — confirm a configured filter that
     * excludes the test client's IP does NOT block this route.
     */
    public function testSignedUrlBypassesWebIpFilter()
    {
        // Test client requests default to REMOTE_ADDR 127.0.0.1; allow only an
        // unrelated address so the filter would reject 127.0.0.1 if it applied.
        System::web_ip_filters('203.0.113.5');

        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        // WebIPFilter::returnError() renders exment::exception.ipfilter with
        // HTTP 200 (not an error status), so assertStatus(200) alone is a false
        // green here — it would pass identically whether the filter blocked the
        // request or not. Assert the actual form rendered, and that the
        // ipfilter error page did NOT, to make the assertion discriminating.
        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.answer_submit'));
        $response->assertDontSee(exmtrans('error.ip_address_filtered'));
    }

    /**
     * Fix round 2, item 1: a visitor who happens to carry an Exment session must
     * still get the answer form. The route used to include 'admin.permission';
     * once Admin::user() resolves, laravel-admin's Permission middleware asks
     * Exment's Checker, which has NO pass-through case for the "safety"
     * endpoint (see Auth\Permission::hasPermissionByEndpoint) — so every
     * non-system user, and any user holding zero permission objects, got
     * trans('admin.deny') instead of the form, on GET and on POST alike. The
     * signed URL is the gate on this page; the permission system has no say.
     *
     * Note: Checker::error() renders the deny page with HTTP 200 (Pjax::respond
     * on an Admin::content()->withError()), exactly like the ipfilter page
     * above — so assertStatus(200) alone is a false green. Assert the form
     * really rendered AND the deny page did not.
     */
    public function testLoggedInNonAdminUserGetsAnswerFormAndCanAnswer()
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

        // NOT freshAnswerRow(): that helper reads through CustomValueModelScope,
        // which — with user2 still authenticated in this test process, holding no
        // role on safety_check_answer — rewrites the query to `id < 0` and hides
        // the row. The controller path is unaffected (SafetyCheckAction reads
        // withoutGlobalScope, exactly as resolve() does for the event), so read
        // the same way here to verify what was actually written.
        $fresh = getModelName('safety_check_answer')::withoutGlobalScope(CustomValueModelScope::class)->find($row->id);
        $this->assertEquals('safe', $fresh->getValue('answer_status'));
        $this->assertEquals('mail', $fresh->getValue('channel'));
    }

    /**
     * Coverage rider: an event id that does not exist at all (not just closed)
     * must 404 on both verbs, without ever touching answer-row lookup.
     */
    public function testEventMissingReturns404ForGetAndPost()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $url = $this->signedAnswerUrl(999999999, $userId);

        $this->get($url)->assertStatus(404);
        $this->post($url, ['st' => 'safe'])->assertStatus(404);
    }

    /**
     * Coverage rider: testMissingRowShowsError above only covers POST; GET with
     * no answer row for the (event, user) pair must also 404, not render a form
     * with nothing to preselect.
     */
    public function testMissingRowOnGetShowsError()
    {
        $event = $this->createEvent();
        // không tạo answer row cho user này
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;

        $this->get($this->signedAnswerUrl($event->id, $userId))->assertStatus(404);
    }

    /**
     * Fix round 1, item 4 (CSRF ruling): the signed URL is itself the
     * authenticator, so a 419 dead-end here is unacceptable for an emergency
     * answer. A live end-to-end request can't prove this: PHPUnit runs with
     * Laravel's own runningUnitTests() bypass active, which skips CSRF for
     * EVERY route regardless of $except, masking whether the route is really
     * exempted. So this exercises the middleware's own except-list matching
     * directly instead — the same inExceptArray() the real request pipeline
     * uses outside of tests.
     */
    public function testCsrfMiddlewareExemptsSafetyAnswerPath()
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

    /**
     * Fix round 1, item "headers": every response from this controller must be
     * uncacheable and unindexable — it renders personal safety-status data
     * behind nothing but a signed link.
     *
     * Note: the controller sets Cache-Control to exactly 'no-store', but
     * Symfony's ResponseHeaderBag::computeCacheControlValue() deterministically
     * appends ', private' at output time whenever neither 'public'/'private'
     * nor 's-maxage' is present (see vendor/symfony/http-foundation) — so the
     * value actually observed on the response is 'no-store, private'. That is
     * strictly MORE restrictive than plain 'no-store', not a contradiction of
     * the "must never be cached" requirement.
     */
    public function testResponsesCarryNoStoreAndNoindexHeaders()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $event = $this->createEvent();
        $this->createAnswerRow($event->id, $userId);

        $response = $this->get($this->signedAnswerUrl($event->id, $userId));

        $response->assertHeader('Cache-Control', 'no-store, private');
        $response->assertHeader('X-Robots-Tag', 'noindex');
    }
}
