<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\BackButton;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * ISS00363: the data list is remembered in the session, per table, as the user last saw it
 * (the view, the filter, the sort, the page, ...). "Back to the list" on a record and the
 * redirect after a save go back to it, not to the first page of the default view.
 *
 * The tables of the test data with no workflow (a workflow may lock the edit of a record).
 * Everything a test writes is rolled back.
 */
class GridStateBackTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const TABLE_A = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;
    protected const TABLE_B = TestDefine::TESTDATA_TABLE_NAME_VIEW;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // a save notifies the table's notify targets
        Notification::fake();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    /**
     * A new PHP process serves every request in production; here every request gets new
     * controllers (they read the table from the request when they are made) and an empty
     * request cache.
     *
     * @param string $method
     * @param string $uri
     * @param array<mixed> $parameters
     * @param array<mixed> $cookies
     * @param array<mixed> $files
     * @param array<mixed> $server
     * @param string|null $content
     * @return TestResponse<Response>
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }
        System::clearRequestSession();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    protected function table(string $table_name): CustomTable
    {
        System::clearRequestSession();
        $table = CustomTable::getEloquent($table_name);
        $this->assertNotNull($table, $table_name);
        return $table;
    }

    protected function record(string $table_name): CustomValue
    {
        $record = $this->table($table_name)->getValueModel()->query()->orderBy('id')->first();
        $this->assertNotNull($record, $table_name);
        return $record;
    }

    protected function viewSuuid(string $table_name): string
    {
        $view = CustomView::getDefault($this->table($table_name), false);
        $this->assertNotNull($view);
        return $view->suuid;
    }

    /**
     * The query of a list as the user leaves it: a filter, a sort, a page, ...
     * and the keys of the request that are not of the list.
     *
     * @param string $view
     * @param int $page
     * @return array<string, mixed>
     */
    protected function listQuery(string $view, int $page): array
    {
        return [
            'view' => $view,
            'execute_filter' => '1',
            'odd_even' => 'odd',
            '_sort' => ['column' => 'id', 'type' => 'desc'],
            'page' => strval($page),
            'per_page' => '10',
            '_pjax' => '#pjax-container',
            'group_key' => 'x',
        ];
    }

    /**
     * @param string $url
     * @return array<string, mixed> the query of the url
     */
    protected function queryOf(string $url): array
    {
        $query = [];
        parse_str(strval(parse_url($url, PHP_URL_QUERY)), $query);
        return $query;
    }

    protected function openList(string $table_name, string $view, int $page): void
    {
        $this->get(admin_url('data/' . $table_name) . '?' . http_build_query($this->listQuery($view, $page)))
            ->assertOk();
    }

    public function testTheListIsRememberedAndTheBackLinkGoesToIt(): void
    {
        $view = $this->viewSuuid(static::TABLE_A);
        $this->openList(static::TABLE_A, $view, 2);

        $url = $this->table(static::TABLE_A)->getGridUrl(true);
        $this->assertStringStartsWith(admin_url('data/' . static::TABLE_A) . '?', $url);
        $this->assertSame([
            'view' => $view,
            'execute_filter' => '1',
            'odd_even' => 'odd',
            '_sort' => ['column' => 'id', 'type' => 'desc'],
            'page' => '2',
            'per_page' => '10',
        ], $this->queryOf($url), 'the view, the filter, the sort and the page; not _pjax nor group_key');

        // the button of a record goes there
        $record = $this->record(static::TABLE_A);
        $this->get(admin_urls('data', static::TABLE_A, $record->id))
            ->assertOk()
            ->assertSee(BackButton::jsString($url), false)
            ->assertSee(BackButton::jsString(exmtrans('custom_value.back_to_list')), false);

        // and so does the button of the edit page
        $this->get(admin_urls('data', static::TABLE_A, $record->id, 'edit'))
            ->assertOk()
            ->assertSee(BackButton::jsString($url), false);
    }

    public function testASaveGoesBackToTheRememberedList(): void
    {
        $view = $this->viewSuuid(static::TABLE_A);
        $this->openList(static::TABLE_A, $view, 2);
        $expected = $this->table(static::TABLE_A)->getGridUrl(true);
        $this->assertSame('2', $this->queryOf($expected)['page'] ?? null);

        $record = $this->record(static::TABLE_A);
        $this->put(admin_urls('data', static::TABLE_A, $record->id), [
            'value' => [
                'text' => 'iss363 saved',
                'index_text' => strval($record->getValue('index_text')),
                'odd_even' => strval($record->getValue('odd_even')),
            ],
        ])->assertRedirect($expected);

        $this->assertSame('iss363 saved', $this->record(static::TABLE_A)->getValue('text'));
    }

    public function testTheListOfATableIsNotUsedForAnother(): void
    {
        $viewA = $this->viewSuuid(static::TABLE_A);
        $this->openList(static::TABLE_A, $viewA, 2);

        // B has not been opened: its plain list
        $this->assertSame(admin_url('data/' . static::TABLE_B), $this->table(static::TABLE_B)->getGridUrl(true));

        $viewB = $this->viewSuuid(static::TABLE_B);
        $this->openList(static::TABLE_B, $viewB, 3);

        $this->assertSame('3', $this->queryOf($this->table(static::TABLE_B)->getGridUrl(true))['page'] ?? null);
        $this->assertSame('2', $this->queryOf($this->table(static::TABLE_A)->getGridUrl(true))['page'] ?? null);
        $this->assertSame($viewA, $this->queryOf($this->table(static::TABLE_A)->getGridUrl(true))['view'] ?? null);
    }

    public function testAnotherViewGivenWins(): void
    {
        $view = $this->viewSuuid(static::TABLE_A);
        $this->openList(static::TABLE_A, $view, 2);
        $table = $this->table(static::TABLE_A);

        // another view: that view as it is, without the remembered query
        $this->assertSame(
            admin_url('data/' . static::TABLE_A) . '?' . http_build_query(['view' => 'iss363otherview']),
            $table->getGridUrl(true, ['view' => 'iss363otherview'])
        );

        // the remembered view: the remembered list
        $this->assertSame('2', $this->queryOf($table->getGridUrl(true, ['view' => $view]))['page'] ?? null);

        // without the filter: the plain url, as before
        $this->assertSame(admin_url('data/' . static::TABLE_A), $table->getGridUrl());
    }

    public function testSaveGridParameterRemembersInTheSession(): void
    {
        $table = $this->table(static::TABLE_A);
        $before = \Exment::user()->getSettingValue('data/' . static::TABLE_A);

        $this->app['request']->query->replace(['page' => '4', '_token' => 'x']);
        $table->saveGridParameter('data/' . static::TABLE_A);

        $state = $table->getGridState();
        $this->assertNotNull($state);
        $this->assertSame(['page' => '4'], $state['query']);
        $this->assertSame($before, \Exment::user()->getSettingValue('data/' . static::TABLE_A), 'nothing is written in the user setting');
    }
}
