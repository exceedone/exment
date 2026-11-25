<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SafetyCheck\EarthquakeFeedInterface;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/**
 * Who receives a safety check: the 送信対象 settings (GitHub #2180).
 */
class SafetyCheckTargetTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        \Exceedone\Exment\Services\Features::save([\Exceedone\Exment\Services\Features::SAFETY_CHECK => ['enabled' => true]]);
        Bus::fake([LineSendJob::class]);
        Notification::fake();

        System::organization_available(true);
        System::safety_check_target_organizations(null);
        System::safety_check_target_view(null);
        System::safety_check_excluded_users(null);
        $this->clearSettings();
    }

    protected function clearSettings(): void
    {
        System::clearCache();
        System::clearRequestSession();
    }

    /**
     * @return array<int, int>
     */
    protected function allUserIds(): array
    {
        return $this->sorted(getModelName(SystemTableName::USER)::query()->pluck('id')->all());
    }

    /**
     * @param array<int, mixed> $ids
     * @return array<int, int>
     */
    protected function sorted(array $ids): array
    {
        $ids = array_map('intval', array_values($ids));
        sort($ids);
        return array_values(array_unique($ids));
    }

    /**
     * @param int|string $eventId
     * @return array<int, int>
     */
    protected function answeredUserIds($eventId): array
    {
        return $this->sorted($this->answerRows($eventId)->map(function ($row) {
            return (int) array_get($row->value, 'user');
        })->all());
    }

    protected function organizationId(string $code): int
    {
        $id = getModelName(SystemTableName::ORGANIZATION)::query()->where('value->organization_code', $code)->value('id');
        $this->assertNotNull($id, "Fixture: organization $code is required.");
        return (int) $id;
    }

    /**
     * @param array<int, string> $codes
     * @return array<int, int>
     */
    protected function usersOfOrganizations(array $codes): array
    {
        $orgIds = array_map(function ($code) {
            return $this->organizationId($code);
        }, $codes);
        $pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        return $this->sorted(\DB::table($pivot)->whereIn('parent_id', $orgIds)->pluck('child_id')->all());
    }

    /**
     * A shared view of the user table: the users whose code contains $like.
     */
    protected function createUserView(string $like): CustomView
    {
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $view = CustomView::create([
            'custom_table_id' => $userTable->id,
            'view_view_name' => 'safety-target-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        CustomViewFilter::create([
            'custom_view_id' => $view->id,
            'view_column_type' => ConditionType::COLUMN,
            'view_column_table_id' => $userTable->id,
            'view_column_target_id' => CustomColumn::getEloquent('user_code', $userTable)->id,
            'view_filter_condition' => FilterOption::LIKE,
            'view_filter_condition_value_text' => $like,
        ]);
        $this->clearSettings();
        return $view;
    }

    /**
     * @return array<int, int>
     */
    protected function usersWithCodeLike(string $like): array
    {
        return $this->sorted(getModelName(SystemTableName::USER)::query()
            ->where('value->user_code', 'like', '%' . $like . '%')->pluck('id')->all());
    }

    public function testEmptySettingsSendToEveryUser(): void
    {
        $event = $this->createEvent();

        $result = SafetyCheckSender::send($event);

        $this->assertSame($this->allUserIds(), $this->answeredUserIds($event->id));
        $this->assertSame($this->totalUserCount(), $result['target']);
    }

    public function testOrganizationsSendToTheirUsersAndChildOrganizations(): void
    {
        // dev has the child organizations dev1 and dev2
        System::safety_check_target_organizations([$this->organizationId('dev')]);
        $this->clearSettings();
        $expected = $this->usersOfOrganizations(['dev', 'dev1', 'dev2']);
        $this->assertNotEmpty($this->usersOfOrganizations(['dev1']), 'Fixture: dev1 has users.');
        $this->assertNotSame($this->allUserIds(), $expected);

        $event = $this->createEvent();
        $result = SafetyCheckSender::send($event);

        $this->assertSame($expected, $this->answeredUserIds($event->id), 'Only the users of the organization and its children get an answer row.');
        $this->assertSame(count($expected), $result['target']);
        $this->assertSame(count($expected), (int) $this->freshEvent($event->id)->getValue('target_count'));
    }

    public function testOrganizationsAreIgnoredWhenOrganizationsAreNotUsed(): void
    {
        System::safety_check_target_organizations([$this->organizationId('dev')]);
        System::organization_available(false);
        $this->clearSettings();

        $this->assertSame($this->allUserIds(), $this->sorted(SafetyCheckSender::targetUserIds()));
    }

    public function testExcludedUsersGetNoAnswerRowAndNoMessage(): void
    {
        $excluded = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        System::safety_check_excluded_users([$excluded]);
        $this->clearSettings();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->linkUser($excluded);

        $event = $this->createEvent();
        SafetyCheckSender::send($event);

        $answered = $this->answeredUserIds($event->id);
        $this->assertNotContains($excluded, $answered);
        $this->assertSame(array_values(array_diff($this->allUserIds(), [$excluded])), $answered);
        Bus::assertDispatchedTimes(LineSendJob::class, 1);
        Bus::assertNotDispatched(LineSendJob::class, function (LineSendJob $job) use ($excluded) {
            return (int) ($job->getContext()['user_id'] ?? 0) === $excluded;
        });
    }

    public function testResendSkipsUsersExcludedAfterTheFirstSend(): void
    {
        $user = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $this->linkUser($user);
        $event = $this->createEvent();
        SafetyCheckSender::send($event);
        Bus::assertDispatchedTimes(LineSendJob::class, 1);

        System::safety_check_excluded_users([$user]);
        $this->clearSettings();
        $result = SafetyCheckSender::send($event, true);

        Bus::assertDispatchedTimes(LineSendJob::class, 1);
        $this->assertSame($this->totalUserCount() - 1, $result['target']);
    }

    public function testViewSendsOnlyToTheUsersItShows(): void
    {
        $view = $this->createUserView('dev');
        System::safety_check_target_view($view->id);
        $this->clearSettings();
        $expected = $this->usersWithCodeLike('dev');
        $this->assertNotEmpty($expected);
        $this->assertNotSame($this->allUserIds(), $expected);

        $event = $this->createEvent();
        SafetyCheckSender::send($event);

        $this->assertSame($expected, $this->answeredUserIds($event->id));
    }

    public function testViewOfAnotherTableIsIgnored(): void
    {
        $otherView = CustomView::query()->where('custom_table_id', '<>', CustomTable::getEloquent(SystemTableName::USER)->id)->first();
        $this->assertNotNull($otherView);
        System::safety_check_target_view($otherView->id);
        $this->clearSettings();

        $this->assertNull(SafetyCheckSender::targetView());
        $this->assertSame($this->allUserIds(), $this->sorted(SafetyCheckSender::targetUserIds()));
    }

    public function testCombinedSettingsIntersectAndExclude(): void
    {
        // dev and its children, shown by the view (codes with "dev1"), without dev1-userD
        System::safety_check_target_organizations([$this->organizationId('dev')]);
        $view = $this->createUserView('dev1');
        System::safety_check_target_view($view->id);
        System::safety_check_excluded_users([(int) TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD]);
        $this->clearSettings();

        $expected = array_values(array_diff(
            array_intersect($this->usersOfOrganizations(['dev', 'dev1', 'dev2']), $this->usersWithCodeLike('dev1')),
            [(int) TestDefine::TESTDATA_USER_LOGINID_DEV1_USERD]
        ));
        $this->assertSame([(int) TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC], $this->sorted($expected), 'Fixture: dev1-userC is left.');

        $event = $this->createEvent();
        SafetyCheckSender::send($event);

        $this->assertSame([(int) TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC], $this->answeredUserIds($event->id));
    }

    public function testAutoSendUsesTheSameTargets(): void
    {
        $excluded = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        System::safety_check_auto_enabled(true);
        System::safety_check_min_scale(45);
        System::safety_check_cooldown_minutes(60);
        System::safety_check_excluded_users([$excluded]);
        $this->clearSettings();

        $feed = new class () implements EarthquakeFeedInterface {
            /** @var array<int, array<string, mixed>> */
            public $items = [];

            /**
             * @return array<int, array<string, mixed>>
             */
            public function fetchRecent(int $limit = 10): array
            {
                return $this->items;
            }
        };
        $time = Carbon::now()->subMinute();
        $feed->items = [[
            'id' => 'quake-target',
            'time' => $time,
            'received_at' => $time->copy(),
            'hypocenter' => 'Test Hypocenter',
            'max_scale' => 50,
            'points' => [['pref' => 'Tokyo', 'scale' => 50]],
        ]];
        $this->app->singleton(EarthquakeFeedInterface::class, function () use ($feed) {
            return $feed;
        });

        \Artisan::call('exment:safetywatch');

        $event = CustomTable::getEloquent('safety_check_event')->getValueQuery()->where('value->jma_event_id', 'quake-target')->first();
        $this->assertNotNull($event);
        $this->assertSame(array_values(array_diff($this->allUserIds(), [$excluded])), $this->answeredUserIds($event->id));
    }

    public function testNewSendFormShowsTheTargetCount(): void
    {
        System::safety_check_excluded_users([(int) TestDefine::TESTDATA_USER_LOGINID_USER2]);
        $this->clearSettings();
        $loginUser = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull($loginUser);
        $this->be($loginUser, 'admin');

        $summary = SafetyCheckSender::targetSummary();
        $this->assertSame($this->totalUserCount() - 1, $summary['count']);

        $response = $this->get('admin/safety_check');
        $response->assertStatus(200);
        $response->assertSee(exmtrans('safety.target_summary', $summary));
        $response->assertSee(exmtrans('safety.target_settings_link'));
    }
}
