<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\DataItems\Grid\GanttGrid;
use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\UserTimezone;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The time zone of the user on the other screens (ISS02835): the gantt and kanban views, the inline edit of
 * the grid, the comments and histories, the task list, the header, and the date groups of the summaries.
 *
 * The site is in Asia/Tokyo; now is 2026-11-23 10:00 there, which is 2026-11-22 20:00 in America/New_York.
 */
class UserTimezoneScreensTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const SITE_TZ = 'Asia/Tokyo';
    protected const USER_TZ = 'America/New_York';
    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

    /** @var array<string, mixed> */
    protected $configBefore = [];
    /** @var string */
    protected $phpTimezoneBefore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        $this->configBefore = [
            'exment.timezone' => config('exment.timezone'),
            'app.timezone' => config('app.timezone'),
        ];
        $this->phpTimezoneBefore = date_default_timezone_get();
        config(['exment.timezone' => static::SITE_TZ, 'app.timezone' => static::SITE_TZ]);
        date_default_timezone_set(static::SITE_TZ);
        Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', static::SITE_TZ));
        UserTimezone::setCurrent(null);

        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $login);
        $this->be($login, 'admin');
        System::clearRequestSession();
        System::clearCache();
    }

    protected function tearDown(): void
    {
        UserTimezone::setCurrent(null);
        Carbon::setTestNow();
        date_default_timezone_set($this->phpTimezoneBefore);
        config($this->configBefore);

        parent::tearDown();
    }

    // the service ----------------------------------------------------

    public function testDisplayOfTheScreens(): void
    {
        // the same time zone: as the screens printed it before
        $carbon = Carbon::parse('2026-11-23 10:00:00', static::SITE_TZ);
        $this->assertSame($carbon, UserTimezone::display($carbon));
        $this->assertSame('2026/11/23 10:00', UserTimezone::display($carbon, 'Y/m/d H:i'));
        $this->assertNull(UserTimezone::display(null));
        $this->assertSame('2026-11-23', UserTimezone::displayDate('2026-11-23 10:00:00'));

        UserTimezone::setCurrent(static::USER_TZ);
        $this->assertSame('2026-11-22 20:00:00', UserTimezone::display($carbon));
        $this->assertSame('2026-11-22 20:00:00', UserTimezone::display('2026-11-23 10:00:00'));
        $this->assertSame('2026/11/22 20:00', UserTimezone::display('2026-11-23 10:00:00', 'Y/m/d H:i'));
        $this->assertSame('', UserTimezone::display(''));

        // a date and time is the day of the user; a date is never converted
        $this->assertSame('2026-11-22', UserTimezone::displayDate('2026-11-23 10:00:00'));
        $this->assertSame('2026-11-23', UserTimezone::displayDate('2026-11-23', false));
        $this->assertNull(UserTimezone::displayDate('not a date', false));
    }

    // gantt ----------------------------------------------------

    public function testGanttDaysAndTodayAreTheDaysOfTheUser(): void
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $datetime = $this->column('datetime');
        $date = $this->column('date');
        $view = $this->createView(ViewKindType::GANTT, [
            'gantt_start_column_id' => $datetime->id,
            'gantt_end_column_id' => $date->id,
        ]);
        $record = $this->record();
        // only in the memory: the chart reads the values of the records it is given
        $record->setValue('datetime', '2026-11-23 10:00:00');
        $record->setValue('date', '2026-11-23');

        $grid = new GanttGrid($table, $view);
        $chart = $this->invoke($grid, 'buildChart', [collect([$record]), $datetime, $date]);
        $this->assertSame('2026-11-23', $chart['today']);
        $this->assertSame(['2026-11-23', '2026-11-23'], [$chart['rows'][0]['start'], $chart['rows'][0]['end']]);

        UserTimezone::setCurrent(static::USER_TZ);
        $chart = $this->invoke($grid, 'buildChart', [collect([$record]), $datetime, $date]);
        $this->assertSame('2026-11-22', $chart['today'], 'today in New York');
        $this->assertSame('2026-11-22', $chart['rows'][0]['start'], '10:00 of the 23rd in Tokyo is the 22nd in New York');
        $this->assertSame('2026-11-23', $chart['rows'][0]['end'], 'a date is not converted');
    }

    // kanban ----------------------------------------------------

    public function testKanbanCardsAndNowAreInTheTimeOfTheUser(): void
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $record = $this->record();
        $record->setValue('datetime', '2026-11-23 10:00:00')->save();
        $view = $this->createView(ViewKindType::KANBAN, [
            'kanban_group_column_id' => $this->column('select')->id,
            'kanban_limit_column_id' => $this->column('datetime')->id,
            'kanban_editable' => '1',
            'kanban_max_count' => 100,
        ]);

        $card = $this->cardOf($view, $record->id);
        $this->assertSame('2026-11-23 10:00:00', $card['values']['datetime']);

        UserTimezone::setCurrent(static::USER_TZ);
        $grid = new KanbanGrid($table, $this->reloadView($view));
        $records = $table->getValueModel()->query()->whereKey($record->id)->get();
        $group_column = $this->invoke($grid, 'getGroupColumn');
        $board = $this->invoke($grid, 'buildBoard', [$group_column, null, $records, []]);
        $this->assertSame('2026-11-22 20:00:00', $board['now'], 'now in New York');
        $card = collect((array) $board['cards'])->firstWhere('id', $record->id);
        $this->assertNotNull($card);
        $this->assertSame('2026-11-22 20:00:00', $card['values']['datetime'], 'the deadline in New York, as its editor opens');
        $this->assertSame('2026-11-22 20:00:00', $card['entered'], 'saved at 10:00 in Tokyo');
        $this->assertSame('2026-11-23 10:00:00', $card['updated_at'], 'the edit lock stays the value of the database');
    }

    // the inline edit ----------------------------------------------------

    public function testInlineEditOfADatetimeForAUserInAnotherTimezone(): void
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $datetime = $this->column('datetime');
        $record = $this->record();
        $record->setValue('datetime', '2026-11-23 10:00:00')->save();

        UserTimezone::setCurrent(static::USER_TZ);
        $this->assertTrue(GridInlineEditor::isEditable($datetime), 'not turned off for a user in another time zone');

        // the editor opens on the time of the user
        $item = $datetime->column_item->setCustomValue($record);
        $this->assertStringContainsString('data-v="2026-11-22 20:00:00"', GridInlineEditor::rawValueTag($item));

        // a write of the screen is typed in the time of the user, and saved in the time of the site
        $this->assertSame(200, $this->callScreenWrite($table, $record->id, ['datetime' => '2026-11-22 21:30:00'], true));
        $this->assertSame('2026-11-23 11:30:00', $this->storedValue($table, $record->id, 'datetime'));

        // a call of the API stays in the time of the site
        $this->assertSame(200, $this->callScreenWrite($table, $record->id, ['datetime' => '2026-11-22 21:30:00'], false));
        $this->assertSame('2026-11-22 21:30:00', $this->storedValue($table, $record->id, 'datetime'));

        // the same time zone as the site: as typed
        UserTimezone::setCurrent(null);
        $this->assertSame(200, $this->callScreenWrite($table, $record->id, ['datetime' => '2026-11-24 08:00:00'], true));
        $this->assertSame('2026-11-24 08:00:00', $this->storedValue($table, $record->id, 'datetime'));
    }

    // comments, histories, tasks, header ----------------------------------------------------

    public function testHistoriesAndCommentsShowTheTimeOfTheUser(): void
    {
        $at = Carbon::parse('2026-11-23 10:00:00', static::SITE_TZ);
        $revision = (object) ['updated_at' => $at, 'user' => 'admin'];
        $workflow = (object) ['workflow_status_name' => 'status', 'created_at' => $at, 'created_user_avatar' => '', 'latest_flg' => false];
        $comment = new class ($at) {
            /** @var Carbon */
            public $created_at;
            /** @var string */
            public $created_user_avatar = '';
            public function __construct(Carbon $at)
            {
                $this->created_at = $at;
            }
            /**
             * @return string
             */
            public function getValue(string $key)
            {
                return 'comment';
            }
        };

        $render = function () use ($revision, $workflow, $comment, $at) {
            return [
                view('exment::form.field.commentline', ['comment' => $comment, 'isAbleRemove' => false, 'deleteUrl' => ''])->render(),
                view('exment::form.field.revisionlink', ['revision' => $revision, 'link' => '#', 'index' => 1])->render(),
                view('exment::form.field.workflowline', ['workflow' => $workflow])->render(),
                view('exment::custom-value.revision-compare-info', ['revinfo' => ['updated_at' => $at, 'deleted_at' => $at], 'trashed' => true])->render(),
            ];
        };

        foreach ($render() as $html) {
            $this->assertStringContainsString('2026-11-23 10:00:00', $html);
        }

        UserTimezone::setCurrent(static::USER_TZ);
        foreach ($render() as $html) {
            $this->assertStringContainsString('2026-11-22 20:00:00', $html);
            $this->assertStringNotContainsString('2026-11-23 10:00:00', $html);
        }
    }

    public function testTaskListFilterIsTheDaysOfTheUser(): void
    {
        $method = new \ReflectionMethod(WorkflowTaskService::class, 'dayStart');
        $method->setAccessible(true);
        $this->assertSame('2026-11-22 00:00:00', $method->invoke(null, '2026-11-22'));

        UserTimezone::setCurrent(static::USER_TZ);
        // 00:00 of the 22nd in New York is 14:00 of the 22nd in Tokyo
        $this->assertSame('2026-11-22 14:00:00', $method->invoke(null, '2026-11-22'));
    }

    public function testHeaderUserInfoShowsTheTimeOfTheUser(): void
    {
        System::header_user_info([SystemColumn::CREATED_AT]);
        System::clearCache();
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $login);
        $created = Carbon::parse($login->base_user->created_at, static::SITE_TZ);

        $this->assertStringContainsString($created->format('Y-m-d H:i:s'), $login->getHeaderInfo());

        UserTimezone::setCurrent(static::USER_TZ);
        $this->assertStringContainsString($created->copy()->setTimezone(static::USER_TZ)->format('Y-m-d H:i:s'), $login->getHeaderInfo());
    }

    // summaries and charts ----------------------------------------------------

    public function testDateGroupsSayTheTimeZoneOfTheSite(): void
    {
        $datetime = $this->column('datetime')->column_item;
        $date = $this->column('date')->column_item;
        $created_at = \Exceedone\Exment\ColumnItems\SystemItem::getItem(CustomTable::getEloquent(static::TABLE), SystemColumn::CREATED_AT);

        // the same time zone: the labels as before
        $this->assertSame('label', UserTimezone::siteGroupLabel('label', $datetime));

        UserTimezone::setCurrent(static::USER_TZ);
        $expected = exmtrans('common.site_timezone_label', ['label' => 'label', 'timezone' => static::SITE_TZ]);
        $this->assertStringContainsString(static::SITE_TZ, $expected);
        $this->assertSame($expected, UserTimezone::siteGroupLabel('label', $datetime));
        $this->assertSame($expected, UserTimezone::siteGroupLabel('label', $created_at));
        $this->assertSame('label', UserTimezone::siteGroupLabel('label', $date), 'a date has no time zone');
    }

    // ----------------------------------------------------

    protected function column(string $name): CustomColumn
    {
        $column = CustomColumn::getEloquent($name, CustomTable::getEloquent(static::TABLE));
        $this->assertNotNull($column, "column $name");
        return $column;
    }

    protected function record(): CustomValue
    {
        /** @var CustomValue|null $record */
        $record = CustomTable::getEloquent(static::TABLE)->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($record);
        return $record;
    }

    /**
     * @param string $kind
     * @param array<string, mixed> $options
     * @return CustomView
     */
    protected function createView(string $kind, array $options)
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'timezone-unittest-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => $kind,
            'options' => $options,
        ]);
        return $this->reloadView($view);
    }

    /**
     * @param CustomView $view
     * @return CustomView
     */
    protected function reloadView(CustomView $view)
    {
        System::clearRequestSession();
        System::clearCache();
        /** @var CustomView $fresh */
        $fresh = CustomView::query()->findOrFail($view->id);
        return $fresh;
    }

    /**
     * The card of a record on the board of the view.
     *
     * @return array<string, mixed>
     */
    protected function cardOf(CustomView $view, int $id): array
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $grid = new KanbanGrid($table, $this->reloadView($view));
        $records = $table->getValueModel()->query()->whereKey($id)->get();
        $group_column = $this->invoke($grid, 'getGroupColumn');
        $board = $this->invoke($grid, 'buildBoard', [$group_column, null, $records, []]);
        $card = collect((array) $board['cards'])->firstWhere('id', $id);
        $this->assertNotNull($card);
        return $card;
    }

    /**
     * Write a record through the webapi the way the screens do (or as a plain API call).
     *
     * @param array<string, mixed> $values
     */
    protected function callScreenWrite(CustomTable $table, int $id, array $values, bool $screen): int
    {
        $params = ['value' => $values];
        if ($screen) {
            $params[GridInlineEditor::SCREEN_WRITE_KEY] = 1;
        }
        $request = Request::create('/admin/webapi/data/' . $table->table_name . '/' . $id, 'PUT', $params);
        $this->app->instance('request', $request);
        Facade::clearResolvedInstance('request');
        System::clearRequestSession();
        System::clearCache();

        $controller = new ApiDataController($table, $request);
        $result = $controller->dataUpdate($request, $table->table_name, $id);
        if ($result instanceof SymfonyResponse) {
            $this->assertLessThan(300, $result->getStatusCode(), (string) $result->getContent());
            return $result->getStatusCode();
        }
        return 200;
    }

    /**
     * @return mixed
     */
    protected function storedValue(CustomTable $table, int $id, string $column_name)
    {
        $value = \Illuminate\Support\Facades\DB::table(getDBTableName($table))->where('id', $id)->value('value');
        return array_get(json_decode(strval($value), true) ?? [], $column_name);
    }

    /**
     * @param object $object
     * @param array<int, mixed> $args
     * @return mixed
     */
    protected function invoke($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }
}
