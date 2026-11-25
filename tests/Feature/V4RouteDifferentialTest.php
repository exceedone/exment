<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Hybrid storage P1 verification V4: representative routes through the HTTP kernel, recorded so
 * that the same test on the pre-P1 baseline and on the P1 tree can be diffed:
 * HTTP status, the SQL (and bindings) that touched value tables, the record ids a list shows,
 * and what a write stored. Admin and a restricted user. Not a browser test.
 *
 * ONE request per test case: Laravel keeps the controller instance on the Route object, and
 * Exment's data controllers bind their table in the constructor, so a second request to another
 * table inside one test would be served for the first table (a harness artifact, not production
 * behaviour, where every request is a new process).
 *
 * Writes its record to the file named by the V4_OUT environment variable (nothing otherwise).
 */
class V4RouteDifferentialTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /** @var array<string, mixed> */
    private array $record = [];
    /** @var array<string, string> created ids/suuids => placeholder */
    private array $placeholders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->withoutMiddleware(VerifyCsrfToken::class);
    }

    protected function tearDown(): void
    {
        $out = getenv('V4_OUT');
        if ($out && !empty($this->record)) {
            $all = is_file($out) ? (json_decode((string)file_get_contents($out), true) ?: []) : [];
            foreach ($this->record as $key => $value) {
                $all[$key] = $value;
            }
            ksort($all);
            file_put_contents($out, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        System::clearCache();
        parent::tearDown();
    }

    private function as(?int $loginId): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        if ($loginId === null) {
            return;
        }
        $this->actingAs(LoginUser::find($loginId), 'admin');
    }

    /** Placeholder for a value created inside the test (id, suuid) so both runs compare equal. */
    private function hold(string $value, string $name): void
    {
        $this->placeholders[$value] = "<$name>";
    }

    private function normalize(string $text): string
    {
        $text = (string)preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(\.\d+)?/', '<datetime>', $text);
        foreach ($this->placeholders as $value => $name) {
            $text = str_replace($value, $name, $text);
        }
        return $text;
    }

    /**
     * Run one request and record status, value-table SQL (normalized), list ids and page markers.
     *
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function flow(string $key, \Closure $request)
    {
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $response = $request();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        $sql = [];
        foreach ($log as $q) {
            if (!preg_match('/`(exm__|pivot__)[0-9a-f]+/', $q['query'])) {
                continue;
            }
            $bindings = array_map(function ($b) {
                return is_object($b) ? (string)$b : $b;
            }, $q['bindings']);
            $sql[] = $this->normalize($q['query'] . ' | ' . json_encode($bindings, JSON_UNESCAPED_UNICODE));
        }
        $content = (string)$response->baseResponse->getContent();
        preg_match_all('/data-(?:key|id)="(\d+)"/', $content, $m);
        preg_match('/<title>\s*([^<]*)<\/title>/', $content, $title);
        preg_match_all('/class="[^"]*\b(alert-(?:danger|success|warning)|help-block|box-title)\b[^"]*"[^>]*>\s*(?:<[^>]+>\s*)*([^<]{0,80})/', $content, $marks, PREG_SET_ORDER);
        $this->record[$key] = [
            'status' => $response->getStatusCode(),
            'title' => trim($title[1] ?? ''),
            'marks' => array_values(array_unique(array_filter(array_map(function ($mk) {
                return $mk[1] . ':' . trim($mk[2]);
            }, $marks)))),
            'location' => $this->normalize((string)$response->headers->get('Location')),
            'ids' => array_values(array_unique(array_map('intval', $m[1]))),
            'sql' => $sql,
        ];
        return $response;
    }

    private function stored(CustomTable $table, int $id): array
    {
        $value = json_decode((string)\DB::table(getDBTableName($table))->where('id', $id)->value('value'), true) ?? [];
        ksort($value);
        return $value;
    }

    // ------------------------------------------------------------------ lists

    /** @return array<string, array{string, string, bool}> */
    public static function listCases(): array
    {
        $cases = [];
        foreach (['admin', 'user2'] as $who) {
            foreach (['list_default', 'list_joined_filter_sort', 'list_header_sort_own_index', 'summary_view'] as $name) {
                // the page, then the deferred row fragment its script requests (lazy grid)
                $cases["$who.$name"] = [$who, $name, false];
                $cases["$who.$name.rows"] = [$who, $name, true];
            }
        }
        return $cases;
    }

    #[DataProvider('listCases')]
    public function testList(string $who, string $name, bool $rows): void
    {
        $child = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE_SELECT);
        switch ($name) {
            case 'list_default':
                $url = admin_url("data/{$child->table_name}?per_page=100");
                break;
            case 'list_joined_filter_sort':
                $view = $this->joinedView($child);
                $url = admin_url("data/{$child->table_name}?view={$view->suuid}&per_page=100");
                break;
            case 'list_header_sort_own_index':
                $col = CustomColumn::getEloquent('index_text', $child);
                $url = admin_url("data/{$child->table_name}?per_page=100&_sort[column]=column_{$col->suuid}&_sort[type]=desc");
                break;
            default:
                $summary = CustomView::where('custom_table_id', CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->id)
                    ->where('view_kind_type', ViewKindType::AGGREGATE)->orderBy('id')->first();
                $this->assertNotNull($summary, 'seeded summary view');
                $url = admin_url("data/" . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL . "?view={$summary->suuid}");
        }
        $login = $who === 'admin' ? TestDefine::TESTDATA_USER_LOGINID_ADMIN : TestDefine::TESTDATA_USER_LOGINID_USER2;
        $this->as((int)$login);
        $response = $this->flow("$who.$name" . ($rows ? '.rows' : ''), function () use ($url, $rows) {
            return $rows ? $this->get($url . '&lazy_grid_rows=1', ['X-Requested-With' => 'XMLHttpRequest']) : $this->get($url);
        });
        $this->assertSame(200, $response->getStatusCode());
    }

    /** A view of the child with a joined parent column, a filter on its own column, a sort on the parent. */
    private function joinedView(CustomTable $child): CustomView
    {
        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE_SELECT);
        $pivot = CustomColumn::getEloquent('parent_select_table', $child);
        $parentIndex = CustomColumn::getEloquent('index_text', $parent);
        $own = CustomColumn::getEloquent('odd_even', $child);

        $view = CustomView::create([
            'custom_table_id' => $child->id,
            'view_view_name' => 'v4 joined',
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);
        $this->hold((string)$view->suuid, 'view');
        $order = 1;
        foreach ([[$child, CustomColumn::getEloquent('index_text', $child), null], [$parent, $parentIndex, $pivot]] as [$table, $column, $via]) {
            $vc = new CustomViewColumn();
            $vc->custom_view_id = $view->id;
            $vc->view_column_type = ConditionType::COLUMN;
            $vc->view_column_table_id = $table->id;
            $vc->view_column_target_id = $column->id;
            $vc->order = $order++;
            if ($via) {
                $vc->setOption('view_pivot_table_id', $child->id);
                $vc->setOption('view_pivot_column_id', $via->id);
            }
            $vc->save();
        }
        $filter = new CustomViewFilter();
        $filter->custom_view_id = $view->id;
        $filter->view_column_type = ConditionType::COLUMN;
        $filter->view_column_table_id = $child->id;
        $filter->view_column_target_id = $own->id;
        $filter->view_filter_condition = FilterOption::EQ;
        $filter->view_filter_condition_value_text = 'odd';
        $filter->save();
        $sort = new CustomViewSort();
        $sort->custom_view_id = $view->id;
        $sort->view_column_type = ConditionType::COLUMN;
        $sort->view_column_table_id = $parent->id;
        $sort->view_column_target_id = $parentIndex->id;
        $sort->sort = -1;
        $sort->priority = 1;
        $sort->setOption('view_pivot_table_id', $child->id);
        $sort->setOption('view_pivot_column_id', $pivot->id);
        $sort->save();
        return $view;
    }

    // ----------------------------------------------------------------- writes

    private function firstId(CustomTable $table): int
    {
        return (int)\DB::table(getDBTableName($table))->whereNull('deleted_at')->orderBy('id')->value('id');
    }

    /** The screens' own api (session): partial update. */
    public function testAdminWebApiPut(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $id = $this->firstId($table);
        $this->as((int)TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->flow('admin.webapi_put', function () use ($table, $id) {
            return $this->put(admin_url("webapi/data/{$table->table_name}/{$id}"), ['value' => ['text' => 'v4 api', 'integer' => '1,234']], ['X-Requested-With' => 'XMLHttpRequest']);
        });
        $this->record['admin.webapi_put']['stored'] = $this->stored($table, $id);
        $this->assertSame(200, $this->record['admin.webapi_put']['status']);
    }

    /** Admin form save (exment-admin-core Form::update). */
    public function testAdminFormUpdate(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $id = $this->firstId($table);
        $form = CustomForm::getDefault($table);
        $this->as((int)TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->flow('admin.form_update', function () use ($table, $id, $form) {
            return $this->post(admin_url("data/{$table->table_name}/{$id}?formid={$form->suuid}"), [
                '_method' => 'PUT',
                'value' => ['text' => 'v4 form', 'index_text' => 'v4 form index'],
            ]);
        });
        $this->record['admin.form_update']['stored'] = $this->stored($table, $id);
        $this->assertNotEmpty($this->record['admin.form_update']);
    }

    /** A restricted user writing a record of custom_value_view_all through the screens' api. */
    public function testRestrictedUserWebApiPut(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $id = $this->firstId($table);
        $before = $this->stored($table, $id);
        $this->as((int)TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->flow('user2.webapi_put_view_all', function () use ($table, $id) {
            return $this->put(admin_url("webapi/data/{$table->table_name}/{$id}"), ['value' => ['text' => 'v4 user2']], ['X-Requested-With' => 'XMLHttpRequest']);
        });
        $this->record['user2.webapi_put_view_all']['unchanged'] = $before === $this->stored($table, $id);
        $this->assertNotEmpty($this->record['user2.webapi_put_view_all']);
    }

    public function testPublicFormSave(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $form = CustomForm::getDefault($table);
        $publicForm = PublicForm::query()->where('custom_form_id', $form->id)->where('active_flg', 1)->orderBy('id')->first();
        $this->assertNotNull($publicForm);
        $this->hold((string)$publicForm->getUrl('create'), 'create_url');
        $this->hold((string)$publicForm->getUrl(), 'form_url');
        $maxBefore = (int)\DB::table(getDBTableName($table))->max('id');

        // the payload a browser sends: the page's token and formkey first, then the form's fields
        // (a bare ['value' => [two fields]] is stored without its value - same on the baseline)
        $this->as(null);
        $html = (string)$this->get($publicForm->getUrl())->baseResponse->getContent();
        preg_match('/name="formkey"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="formkey"/', $html, $fk);
        $payload = [
            '_token' => csrf_token(),
            'formkey' => ($fk[1] ?? '') ?: ($fk[2] ?? ''),
            'value' => ['text' => 'v4 public', 'index_text' => 'v4 public index', 'odd_even' => 'odd', 'multiples_of_3' => '1', 'integer' => '5', 'init_text' => 'x', 'email' => 'v4@example.com'],
        ];
        $this->flow('public.form_create', function () use ($publicForm, $payload) {
            return $this->post($publicForm->getUrl('create'), $payload);
        });
        $new = \DB::table(getDBTableName($table))->where('id', '>', $maxBefore)->orderBy('id')->get();
        $this->record['public.form_create']['created'] = $new->count();
        if ($new->count() === 1) {
            $value = json_decode((string)$new->first()->value, true);
            if (is_array($value)) {
                ksort($value);
            }
            $this->record['public.form_create']['stored'] = $value;
        }
        $this->assertNotEmpty($this->record['public.form_create']);
    }
}
