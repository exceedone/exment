<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Grid\KanbanGrid;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * GitHub #2185: the filters of a kanban board that loads a few cards per
 * column run on the server, so a card that is not loaded yet is found too.
 *
 * Every test makes its own records in the columns fixture table and keeps the
 * board on them with a view filter on a tag in the text column. The board
 * loads one or two cards per column; a card the test looks for always stands
 * behind a card that does not match, so it is not in the first slice of its
 * column until the filter is set. Everything is rolled back.
 */
class KanbanServerFilterTest extends UnitTestBase
{
    use DatabaseTransactions;

    /** fixture table with select, select_valtext, select_multiple, user and date columns */
    protected const TABLE = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

    /** @var int counter that makes the text of each record different */
    protected $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    // ------------------------------------------------------------ panel filter

    /**
     * A filter of the panel on a user column finds the cards of that user
     * behind the first slice of their board column, and no one else's.
     */
    public function testPanelFilterOnUserColumnFindsCardsBehindTheFirstSlice(): void
    {
        $me = $this->adminId();
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $r1 = $this->card($tag, ['select' => 'foo', 'user' => $me]);
        $r2 = $this->card($tag, ['select' => 'foo', 'user' => $me]);
        $r3 = $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $r4 = $this->card($tag, ['select' => 'bar', 'user' => $me]);
        $r5 = $this->card($tag, ['select' => 'bar', 'user' => $other]);
        $view = $this->boardView($tag, ['kanban_col_count' => 1]);

        $this->assertIds([$r1, $r4], $this->boardIds($this->gridOf($view)), 'Precondition: the first slice holds the first card of each column.');

        $grid = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]);
        $this->assertTrue($grid->hasBoardState());
        $this->assertIds([$r3, $r5], $this->boardIds($grid), 'The cards of the other user are found, whatever their place in the column.');
        $this->assertNotContains($r2, $this->boardIds($grid));
    }

    /**
     * A filter of the panel on a choice column that is not the board column
     * finds the cards that hold the value.
     */
    public function testPanelFilterOnChoiceColumnFindsCardsBehindTheFirstSlice(): void
    {
        $tag = $this->newTag();
        $r1 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $r2 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'bar']);
        $r3 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'foo']);
        $r4 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'bar']);
        $view = $this->boardView($tag, ['kanban_col_count' => 1]);

        $this->assertIds([$r1, $r3], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['filters' => ['select_valtext' => 'bar']]);
        $this->assertIds([$r2, $r4], $this->boardIds($grid));
    }

    // -------------------------------------------------------------------- over

    /**
     * "Only overdue" finds a card whose due date is past, behind a card that is
     * not, and leaves out one past due in a column that means finished.
     */
    public function testOnlyOverFindsPastDueCardsAndLeavesOutDoneOnes(): void
    {
        $tag = $this->newTag();
        $future = $this->card($tag, ['select' => 'foo', 'date' => '2099-01-01']);
        $past = $this->card($tag, ['select' => 'foo', 'date' => '2000-01-01']);
        $no_date = $this->card($tag, ['select' => 'foo']);
        $done_past = $this->card($tag, ['select' => 'baz', 'date' => '2000-01-01']);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_limit_column_id' => $this->column('date')->id,
            'kanban_done_keys' => [$this->column('select')->id . '::baz'],
        ]);

        $before = $this->boardIds($this->gridOf($view));
        $this->assertIds([$future, $done_past], $before, 'Precondition: the overdue card is behind the first slice.');

        $grid = $this->gridOf($view)->setBoardState(['only' => ['over' => 1]]);
        $this->assertIds([$past], $this->boardIds($grid), 'Past due and not finished: the finished one and the others are left out.');
        $this->assertNotContains($no_date, $this->boardIds($grid));
    }

    // -------------------------------------------------------------- unassigned

    /**
     * "Only unassigned" finds a card nobody holds, behind a card somebody does.
     */
    public function testOnlyUnassignedFindsCardsWithoutAssignee(): void
    {
        $me = $this->adminId();
        $tag = $this->newTag();
        $u1 = $this->card($tag, ['select' => 'foo', 'user' => $me]);
        $u2 = $this->card($tag, ['select' => 'foo']);
        $u3 = $this->card($tag, ['select' => 'bar', 'user' => $me]);
        $u4 = $this->card($tag, ['select' => 'bar']);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_assignee_column_id' => $this->column('user')->id,
        ]);

        $this->assertIds([$u1, $u3], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['only' => ['unassigned' => 1]]);
        $this->assertIds([$u2, $u4], $this->boardIds($grid));
    }

    // -------------------------------------------------------------------- mine

    /**
     * "Only mine" finds the cards of the logged in user behind the cards of
     * somebody else.
     */
    public function testOnlyMineFindsCardsOfTheLoggedInUser(): void
    {
        $me = $this->adminId();
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $m1 = $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $m2 = $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $m3 = $this->card($tag, ['select' => 'foo', 'user' => $me]);
        $m4 = $this->card($tag, ['select' => 'bar', 'user' => $other]);
        $m5 = $this->card($tag, ['select' => 'bar', 'user' => $me]);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_assignee_column_id' => $this->column('user')->id,
        ]);

        $this->assertIds([$m1, $m4], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['only' => ['mine' => 1]]);
        $this->assertIds([$m3, $m5], $this->boardIds($grid));
        $this->assertNotContains($m2, $this->boardIds($grid));
    }

    // ----------------------------------------------------------------- blocked

    /**
     * "Only blocked" finds a card that holds a value the view marks as
     * blocking, behind cards that do not.
     */
    public function testOnlyBlockedFindsCardsMarkedByTheViewOption(): void
    {
        $tag = $this->newTag();
        $b1 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $b2 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $b3 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'bar']);
        $b4 = $this->card($tag, ['select' => 'baz', 'select_valtext' => 'foo']);
        $b5 = $this->card($tag, ['select' => 'baz', 'select_valtext' => 'bar']);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_blocked_keys' => [$this->column('select_valtext')->id . '::bar'],
        ]);

        $this->assertIds([$b1, $b4], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['only' => ['blocked' => 1]]);
        $this->assertIds([$b3, $b5], $this->boardIds($grid));
        $this->assertNotContains($b2, $this->boardIds($grid));
    }

    // ---------------------------------------------------------------- expedite

    /**
     * "Only expedite" finds a card that holds any of the values the view marks,
     * each in a column of its own, one of them a column of several values.
     */
    public function testOnlyExpediteFindsCardsHoldingAnyMarkedValue(): void
    {
        $tag = $this->newTag();
        $e1 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $e2 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'baz']);
        $e3 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'foo']);
        $e4 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'foo', 'select_multiple' => ['foo', 'bar']]);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_expedite_keys' => [
                $this->column('select_valtext')->id . '::baz',
                $this->column('select_multiple')->id . '::bar',
            ],
        ]);

        $this->assertIds([$e1, $e3], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['only' => ['expedite' => 1]]);
        $this->assertIds([$e2, $e4], $this->boardIds($grid));
    }

    // ----------------------------------------------------------------- keyword

    /**
     * A keyword matches the label of a choice, not the value it stores: the
     * card is found by "Alpha" though it stores "kfa".
     */
    public function testKeywordMatchesTheLabelOfAChoice(): void
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $valtext = CustomColumn::getEloquent('select_valtext', $table);
        $this->assertInstanceOf(CustomColumn::class, $valtext);
        $valtext->setOption('select_item_valtext', "kfa,Alpha team\r\nkfb,Beta team\r\nkfc,Gamma team");
        $valtext->save();
        System::clearCache();

        $tag = $this->newTag();
        $a1 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'kfb']);
        $a2 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'kfb']);
        $a3 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'kfa']);
        $a4 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'kfb']);
        $a5 = $this->card($tag, ['select' => 'bar', 'select_valtext' => 'kfa']);
        $view = $this->boardView($tag, ['kanban_col_count' => 1]);

        $this->assertIds([$a1, $a4], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['keyword' => 'Alpha']);
        $this->assertIds([$a3, $a5], $this->boardIds($grid));
        $this->assertNotContains($a2, $this->boardIds($grid));
    }

    /**
     * A keyword matches a label held by a column of several values, behind a
     * card that does not hold it.
     */
    public function testKeywordMatchesALabelOfAColumnOfSeveralValues(): void
    {
        $tag = $this->newTag();
        $s1 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $s2 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo']);
        $s3 = $this->card($tag, ['select' => 'foo', 'select_valtext' => 'foo', 'select_multiple' => ['baz', 'bar']]);
        $s4 = $this->card($tag, ['select' => 'baz', 'select_valtext' => 'foo']);
        $s5 = $this->card($tag, ['select' => 'baz', 'select_valtext' => 'foo', 'select_multiple' => ['bar']]);
        // the label strip of the cards: a column the board reads
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_labels_column_id' => $this->column('select_multiple')->id,
        ]);

        $this->assertIds([$s1, $s4], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['keyword' => 'bar']);
        $this->assertIds([$s3, $s5], $this->boardIds($grid));
        $this->assertNotContains($s2, $this->boardIds($grid));
    }

    /**
     * A keyword matches the name of the person a user column holds, not the
     * id it stores.
     */
    public function testKeywordMatchesTheNameOfAUser(): void
    {
        $me = $this->adminId();
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $user_column = $this->column('user');

        $probe = $this->gridOf($this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_assignee_column_id' => $user_column->id,
        ]));
        /** @var array<int|string, string> $names */
        $names = $this->invoke($probe, 'boardValueOptions', [$user_column]);
        $my_name = strval($names[$me] ?? '');
        $other_name = strval($names[$other] ?? '');
        $this->assertNotSame('', $my_name, 'Precondition: the board knows the name of the logged in user.');
        $this->assertFalse(mb_stripos($other_name, $my_name) !== false, 'Precondition: the other name does not hold my name.');
        $this->assertFalse(ctype_digit($my_name), 'Precondition: the name is not the id.');

        $k1 = $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $k2 = $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $k3 = $this->card($tag, ['select' => 'foo', 'user' => $me]);
        $k4 = $this->card($tag, ['select' => 'bar', 'user' => $other]);
        $k5 = $this->card($tag, ['select' => 'bar', 'user' => $me]);
        $view = $this->boardView($tag, [
            'kanban_col_count' => 1,
            'kanban_assignee_column_id' => $user_column->id,
        ]);

        $this->assertIds([$k1, $k4], $this->boardIds($this->gridOf($view)));

        $grid = $this->gridOf($view)->setBoardState(['keyword' => $my_name]);
        $this->assertIds([$k3, $k5], $this->boardIds($grid));
        $this->assertNotContains($k2, $this->boardIds($grid));
    }

    // ------------------------------------------------------------------ counts

    /**
     * The column figures follow the filter: the totals are the number of
     * matching records of each column, and differ from the unfiltered ones.
     */
    public function testColumnTotalsFollowTheFilter(): void
    {
        $me = $this->adminId();
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $specs = [
            ['select' => 'foo', 'user' => $me],
            ['select' => 'foo', 'user' => $other],
            ['select' => 'foo', 'user' => $other],
            ['select' => 'foo', 'user' => $me],
            ['select' => 'bar', 'user' => $other],
            ['select' => 'bar', 'user' => $me],
            ['select' => 'bar', 'user' => $me],
        ];
        foreach ($specs as $spec) {
            $this->card($tag, $spec);
        }
        $view = $this->boardView($tag, ['kanban_col_count' => 1]);

        $expected_all = [];
        $expected_other = [];
        foreach ($specs as $spec) {
            $expected_all[$spec['select']] = ($expected_all[$spec['select']] ?? 0) + 1;
            if ($spec['user'] === $other) {
                $expected_other[$spec['select']] = ($expected_other[$spec['select']] ?? 0) + 1;
            }
        }
        ksort($expected_all);
        ksort($expected_other);

        $all = $this->totals($this->gridOf($view));
        $this->assertSame($expected_all, $all, 'The totals of the board without a filter.');

        $filtered = $this->totals($this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]));
        $this->assertSame($expected_other, $filtered, 'The totals are the matching records of each column.');
        $this->assertNotSame($all, $filtered);

        // the figures on their own follow the state as well
        $grid = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]);
        $stats = $this->plain($grid->columnStats()['flat']);
        $this->assertSame(2, intval($stats['foo']['total']));
        $this->assertSame(1, intval($stats['bar']['total']));
    }

    // --------------------------------------------------------------- load more

    /**
     * "Load more" under a filter pages inside the matching cards only, and the
     * first slice with the pages covers every matching card of the column once.
     */
    public function testLoadMorePagesInsideTheFilteredSet(): void
    {
        $me = $this->adminId();
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $owners = [$me, $other, $me, $other, $other, $me, $other, $other];
        $ids = [];
        foreach ($owners as $owner) {
            $ids[] = $this->card($tag, ['select' => 'foo', 'user' => $owner]);
        }
        $matching = [$ids[1], $ids[3], $ids[4], $ids[6], $ids[7]];
        $view = $this->boardView($tag, ['kanban_col_count' => 2]);

        $grid = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]);
        $first = $this->boardIds($grid);
        $this->assertSame([$ids[1], $ids[3]], $first, 'The first slice: the first two matching cards.');

        $page2 = $grid->moreCards('foo', 2);
        $this->assertSame([$ids[4], $ids[6]], $this->cardIds($page2['cards']));
        $this->assertTrue($page2['has_more'], 'One matching card is left.');

        $page3 = $grid->moreCards('foo', 4);
        $this->assertSame([$ids[7]], $this->cardIds($page3['cards']));
        $this->assertFalse($page3['has_more']);

        $seen = array_merge($first, $this->cardIds($page2['cards']), $this->cardIds($page3['cards']));
        $this->assertIds($matching, $seen, 'Every matching card exactly once.');
        $this->assertSame(count($matching), count($seen));
    }

    // ----------------------------------------------------------------- junk in

    /**
     * A state that names no column of the table, holds no value or only
     * switched-off flags narrows nothing.
     */
    public function testEmptyAndUnknownStateIsIgnored(): void
    {
        $other = $this->otherUserId();
        $tag = $this->newTag();
        $this->card($tag, ['select' => 'foo', 'user' => $this->adminId()]);
        $this->card($tag, ['select' => 'foo', 'user' => $other]);
        $this->card($tag, ['select' => 'bar', 'user' => $other]);
        $view = $this->boardView($tag, ['kanban_col_count' => 1]);

        $plain = $this->boardIds($this->gridOf($view));
        $this->assertFalse($this->gridOf($view)->hasBoardState(), 'A board that was given nothing is not narrowed.');

        $grid = $this->gridOf($view)->setBoardState([
            'filters' => ['no_such_column' => 'x', 'select' => ''],
            'keyword' => '   ',
            'only' => ['bogus' => 1, 'over' => 0],
            'group' => 'no_such_column',
        ]);
        $this->assertFalse($grid->hasBoardState());
        $this->assertSame($plain, $this->boardIds($grid), 'The board is the one without a state.');

        // an empty state given after a real one takes the narrowing off again
        $grid = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]);
        $this->assertTrue($grid->hasBoardState());
        $grid->setBoardState([]);
        $this->assertFalse($grid->hasBoardState());
        $this->assertSame($plain, $this->boardIds($grid));

        // a known filter next to an unknown one: only the known one counts
        $mixed = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other, 'no_such_column' => 'v']]);
        $known = $this->gridOf($view)->setBoardState(['filters' => ['user' => $other]]);
        $this->assertSame($this->boardIds($known), $this->boardIds($mixed));
    }

    // ----------------------------------------------------------------- helpers

    /**
     * @return string
     */
    protected function adminId(): string
    {
        $id = \Exment::getUserId();
        $this->assertNotNull($id);
        return strval($id);
    }

    /**
     * The id of a user other than the logged in one.
     *
     * @return string
     */
    protected function otherUserId(): string
    {
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertInstanceOf(LoginUser::class, $login);
        $id = strval($login->base_user_id);
        $this->assertNotSame($this->adminId(), $id);
        return $id;
    }

    /**
     * @return string a text no other record of the table holds
     */
    protected function newTag(): string
    {
        return 'ksf' . strtolower(uniqid());
    }

    /**
     * @param string $name
     * @return CustomColumn
     */
    protected function column(string $name)
    {
        $column = CustomColumn::getEloquent($name, CustomTable::getEloquent(static::TABLE));
        $this->assertInstanceOf(CustomColumn::class, $column);
        return $column;
    }

    /**
     * A record of the table, as the board's card.
     *
     * @param string $tag
     * @param array<string, mixed> $values
     * @return string the id
     */
    protected function card(string $tag, array $values): string
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $record = $table->getValueModel();
        $record->setValue(array_merge(['text' => $tag . '-' . (++$this->sequence)], $values));
        $record->save();
        System::clearRequestSession();

        return strval($record->id);
    }

    /**
     * A board of the records with the tag, grouped by the select column.
     *
     * @param string $tag
     * @param array<string, mixed> $options
     * @return CustomView
     */
    protected function boardView(string $tag, array $options)
    {
        $table = CustomTable::getEloquent(static::TABLE);
        $view = CustomView::create([
            'custom_table_id' => $table->id,
            'view_view_name' => 'kanban-server-filter-' . uniqid(),
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::KANBAN,
            'options' => array_merge([
                'kanban_editable' => '1',
                'kanban_group_column_id' => $this->column('select')->id,
            ], $options),
        ]);
        CustomViewFilter::create([
            'custom_view_id' => $view->id,
            'view_column_type' => ConditionType::COLUMN,
            'view_column_table_id' => $table->id,
            'view_column_target_id' => $this->column('text')->id,
            'view_filter_condition' => FilterOption::LIKE,
            'view_filter_condition_value_text' => $tag,
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
        $fresh = CustomView::withoutGlobalScopes()->find($view->id);
        $this->assertInstanceOf(CustomView::class, $fresh);
        return $fresh;
    }

    /**
     * A fresh board object of the view: the view caches the one it made.
     *
     * @param CustomView $view
     * @return KanbanGrid
     */
    protected function gridOf(CustomView $view)
    {
        $view = $this->reloadView($view);
        return new KanbanGrid($view->custom_table, $view);
    }

    /**
     * The ids of the cards of the board's first slice, in board order.
     *
     * @param KanbanGrid $grid
     * @return array<int, string>
     */
    protected function boardIds(KanbanGrid $grid): array
    {
        return $this->cardIds($grid->filteredBoard()['cards']);
    }

    /**
     * @param array<int, array<string, mixed>> $cards
     * @return array<int, string>
     */
    protected function cardIds(array $cards): array
    {
        return array_map('strval', array_column($cards, 'id'));
    }

    /**
     * Column key => total of the board's figures.
     *
     * @param KanbanGrid $grid
     * @return array<string, int>
     */
    protected function totals(KanbanGrid $grid): array
    {
        $totals = [];
        foreach ($this->plain($grid->filteredBoard()['flat']) as $key => $stat) {
            $totals[strval($key)] = intval($stat['total']);
        }
        ksort($totals);
        return $totals;
    }

    /**
     * The same ids, whatever the order.
     *
     * @param array<int, string> $expected
     * @param array<int, string> $actual
     * @param string $message
     * @return void
     */
    protected function assertIds(array $expected, array $actual, string $message = ''): void
    {
        $expected = array_map('strval', $expected);
        $actual = array_map('strval', $actual);
        sort($expected);
        sort($actual);
        $this->assertSame($expected, $actual, $message);
    }

    /**
     * A value the way the browser receives it: objects and lists as json
     * makes them.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    protected function plain($value): array
    {
        $json = json_encode($value);
        $this->assertIsString($json);
        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded);
        return $decoded;
    }

    /**
     * @param object $object
     * @param string $method
     * @param array<int, mixed> $args
     * @return mixed
     */
    protected function invoke($object, string $method, array $args = [])
    {
        $reflection = new \ReflectionMethod($object, $method);
        $reflection->setAccessible(true);
        return $reflection->invokeArgs($object, $args);
    }

    /**
     * @param string $user_id login id
     * @return LoginUser
     */
    protected function login($user_id)
    {
        $login = LoginUser::find($user_id);
        $this->assertInstanceOf(LoginUser::class, $login);
        $this->be($login, 'admin');
        System::clearRequestSession();
        System::clearCache();
        return $login;
    }
}
