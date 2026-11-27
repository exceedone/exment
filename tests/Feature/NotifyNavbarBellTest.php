<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\ApiController;
use Exceedone\Exment\Form\Navbar\NotifyNav;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The bell (ISS00360): the unread notifications first, then the ones read lately, greyed - a read
 * notification stays in the list to be looked at again. The badge counts the unread ones only.
 * "Read all" greys them, nothing is deleted. An item opens the record (notify_navbar/{id}: the
 * screen of the plugin that shows it, when one does).
 */
class NotifyNavbarBellTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var int */
    protected $userId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->userId = intval(\Exment::getUserId());
        // only the notifications of this test
        NotifyNavbar::where('target_user_id', $this->userId)->delete();
        NotifyNavbar::navbarCacheForget($this->userId);
    }

    protected function notification(string $subject, bool $read, int $daysAgo = 0, ?int $userId = null): NotifyNavbar
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = 1;
        $notify->target_user_id = $userId ?? $this->userId;
        $notify->trigger_user_id = $this->userId;
        $notify->read_flg = $read;
        $notify->notify_subject = $subject;
        $notify->notify_body = 'bell body';
        $notify->created_at = Carbon::now()->subDays($daysAgo);
        $notify->save();
        return $notify;
    }

    /**
     * @return array<string, mixed>
     */
    protected function bell(): array
    {
        System::clearRequestSession();
        NotifyNavbar::navbarCacheForget($this->userId);
        $response = $this->get(admin_url('webapi/notifyPage'), ['X-Requested-With' => 'XMLHttpRequest']);
        $response->assertOk();
        return (array) $response->json();
    }

    public function testTheUnreadOnesFirstThenTheReadOnesOfTheLastDays(): void
    {
        $unreadNew = $this->notification('unread new', false, 0);
        $this->notification('unread old', false, 20);
        $readLately = $this->notification('read lately', true, 2);
        $this->notification('read long ago', true, ApiController::NOTIFY_PAGE_READ_DAYS + 3);
        $this->notification('of somebody else', false, 0, LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id);

        $bell = $this->bell();
        $this->assertSame(2, $bell['count'], 'the badge: the unread ones');
        $this->assertSame(['unread new', 'unread old', 'read lately'], array_column($bell['items'], 'label'));
        $this->assertSame([false, false, true], array_column($bell['items'], 'read'));
        $this->assertSame(admin_urls('notify_navbar', $unreadNew->id), $bell['items'][0]['href']);
        $this->assertSame(admin_urls('notify_navbar', $readLately->id), $bell['items'][2]['href']);
    }

    public function testAtMostTwentyAreListed(): void
    {
        for ($i = 0; $i < ApiController::NOTIFY_PAGE_MAX_ITEMS + 5; $i++) {
            $this->notification('n' . $i, false, 0);
        }
        $this->notification('read', true, 0);

        $bell = $this->bell();
        $this->assertSame(ApiController::NOTIFY_PAGE_MAX_ITEMS + 5, $bell['count']);
        $this->assertCount(ApiController::NOTIFY_PAGE_MAX_ITEMS, $bell['items']);
        $this->assertNotContains(true, array_column($bell['items'], 'read'), 'the unread ones come first');
    }

    public function testReadAllGreysThemAndDeletesNothing(): void
    {
        $first = $this->notification('first', false, 0);
        $second = $this->notification('second', false, 1);

        $this->post(admin_url('notify_navbar/batchAll/read'))->assertOk()->assertJson(['result' => true]);

        $bell = $this->bell();
        $this->assertSame(0, $bell['count']);
        $this->assertSame(['first', 'second'], array_column($bell['items'], 'label'));
        $this->assertSame([true, true], array_column($bell['items'], 'read'));
        $this->assertNotNull(NotifyNavbar::find($first->id));
        $this->assertNotNull(NotifyNavbar::find($second->id));
    }

    public function testOpeningOneKeepsItInTheBellGreyed(): void
    {
        $notify = $this->notification('opened', false, 0);
        // the notification itself (no record): it is read
        $this->get(admin_url('notify_navbar/' . $notify->id) . '?content=1')->assertOk();

        $bell = $this->bell();
        $this->assertSame(0, $bell['count']);
        $this->assertSame([['opened', true]], array_map(function ($item) {
            return [$item['label'], $item['read']];
        }, $bell['items']));
    }

    public function testTheBellHasReadAllAndTheList(): void
    {
        $html = (new NotifyNav())->render();
        $this->assertStringContainsString('class="notify-navbar-read-all" data-url="' . esc_html(admin_url('notify_navbar/batchAll/read')) . '"', $html);
        $this->assertStringContainsString(esc_html(exmtrans('notify_navbar.read_all')), $html);
        $this->assertStringContainsString('href="' . admin_url('notify_navbar') . '"', $html);

        $js = (string) file_get_contents(exment_package_path('public/vendor/exment/js/notify_navbar.js'));
        $this->assertStringContainsString("'.notify-navbar-read-all'", $js);
        $this->assertStringContainsString('notify-navbar-read', $js);
    }
}
