<?php

namespace Exceedone\Exment\Tests\Unit;

use Carbon\Carbon;
use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Support\DriverSql;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

/**
 * Hybrid storage P1 W1: single-record writes of the JSON `value`, through their production
 * entry points, and the save lifecycle around them (V-3).
 *
 * Pinned: omitted keys are taken back from the original row on save (V-3) and an explicit null
 * clears a key (V-2); forgetIfNull on one key drops it in memory only, so the save takes the
 * original back; a key list ignores forgetIfNull; column normalization (saving()) and Carbon
 * conversion; the stored JSON, the one UPDATE and its bindings; event order and count; and the
 * bypasses (disable_saving_event, saveQuietly, restore_revision) that skip the merge.
 */
class ValueWriteLifecycleTest extends UnitTestBase
{
    use DatabaseTransactions;
    use DriverSql;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        System::clearRequestSession();
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
    }

    protected function db(): string
    {
        return getDBTableName($this->table());
    }

    /** A record holding text, index_text, odd_even, date and integer. */
    protected function record(): CustomValue
    {
        $id = \DB::table($this->db())->whereNull('deleted_at')->orderBy('id')->get()->first(function ($row) {
            $v = json_decode($row->value, true);
            return isset($v['text'], $v['index_text'], $v['odd_even']);
        })->id;
        $model = $this->table()->getValueModel($id);
        $this->assertInstanceOf(CustomValue::class, $model);
        return $model;
    }

    protected function stored(int $id): array
    {
        return json_decode(\DB::table($this->db())->where('id', $id)->value('value'), true);
    }

    /**
     * @return array{0: mixed, 1: array<int, array{query: string, bindings: array<mixed>}>, 2: array<int, string>}
     */
    protected function observed(CustomValue $model, \Closure $callback): array
    {
        $events = [];
        $class = get_class($model);
        foreach (['saving', 'creating', 'updating', 'created', 'updated', 'saved'] as $event) {
            Event::listen("eloquent.$event: $class", function () use (&$events, $event) {
                $events[] = $event;
            });
        }
        \DB::flushQueryLog();
        \DB::enableQueryLog();
        try {
            $result = $callback();
        } finally {
            $log = \DB::getQueryLog();
            \DB::disableQueryLog();
        }
        return [$result, $log, $events];
    }

    /**
     * @return array<int, array{query: string, bindings: array<mixed>}>
     */
    protected function writes(array $log): array
    {
        $db = $this->db();
        $quoted = static::q($db);
        return array_values(array_filter($log, function ($q) use ($quoted) {
            return strpos($q['query'], "update $quoted") === 0 || strpos($q['query'], "insert into $quoted") === 0;
        }));
    }

    // -------------------------------------------------- production caller: API

    public function testApiUpdateKeepsOmittedKeysClearsExplicitNullAndNormalizes(): void
    {
        $model = $this->record();
        $before = $this->stored($model->id);
        $request = Request::create('/w1', 'PUT', ['value' => ['text' => 'w1 api', 'integer' => '1,234', 'index_text' => null]]);
        app()->instance('request', $request);

        [$response, $log, $events] = $this->observed($model, function () use ($request, $model) {
            return (new ApiDataController($this->table(), $request))->dataUpdate($request, $this->table()->table_name, $model->id);
        });
        $this->assertNotInstanceOf(\Symfony\Component\HttpFoundation\Response::class, $response, is_object($response) && method_exists($response, 'getContent') ? (string)$response->getContent() : '');

        $after = $this->stored($model->id);
        $expected = $before;
        $expected['text'] = 'w1 api';
        $expected['integer'] = '1234';
        unset($expected['index_text']);
        $this->assertEquals($expected, $after, 'omitted keys kept, explicit null cleared, integer normalized');
        $this->assertSame('1234', $after['integer']);

        $writes = $this->writes($log);
        $this->assertCount(1, $writes, 'one UPDATE');
        $this->assertStringStartsWith('update ' . static::q($this->db()) . ' set ' . static::q('value') . ' = ?', $writes[0]['query']);
        $this->assertSame(json_decode($writes[0]['bindings'][0], true), $after);
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $events);
    }

    // ----------------------------------------------- model entry points

    public function testPartialUpdateTakesOmittedKeysFromTheOriginal(): void
    {
        $model = $this->record();
        $before = $this->stored($model->id);

        $model->setValue('text', 'w1 partial');
        $this->assertSame(['value'], array_keys($model->getDirty()));
        [, $log, $events] = $this->observed($model, function () use ($model) {
            return $model->save();
        });
        $expected = array_merge($before, ['text' => 'w1 partial']);
        $this->assertEquals($expected, $this->stored($model->id));
        $this->assertCount(1, $this->writes($log));
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $events);

        // a save with nothing changed: no UPDATE, no updating/updated
        [, $log, $events] = $this->observed($model, function () use ($model) {
            return $model->save();
        });
        $this->assertSame([], $this->writes($log));
        $this->assertSame(['saving', 'saved'], $events);
    }

    public function testFullAssignmentIsMergedWithTheOriginalOnSave(): void
    {
        $model = $this->record();
        $before = $this->stored($model->id);

        $model->value = ['text' => 'w1 full'];
        $this->assertSame(['text' => 'w1 full'], $model->value, 'in memory: only what was assigned');
        $model->save();
        $this->assertEquals(array_merge($before, ['text' => 'w1 full']), $this->stored($model->id));

        $model->value = ['index_text' => null];
        $model->save();
        $expected = array_merge($before, ['text' => 'w1 full']);
        unset($expected['index_text']);
        $this->assertEquals($expected, $this->stored($model->id), 'explicit null clears, omitted keys kept');
    }

    public function testForgetIfNullAndExplicitNull(): void
    {
        $model = $this->record();
        $before = $this->stored($model->id);

        // one key with forgetIfNull: dropped in memory, taken back from the original on save
        $model->setValue('text', null, true);
        $this->assertArrayNotHasKey('text', $model->value);
        $model->save();
        $this->assertSame($before['text'], $this->stored($model->id)['text']);

        // a key list ignores forgetIfNull: null in memory, cleared on save
        $model->setValue(['text' => null], null, true);
        $this->assertArrayHasKey('text', $model->value);
        $this->assertNull($model->value['text']);
        $model->save();
        $this->assertArrayNotHasKey('text', $this->stored($model->id));

        // setValue ignores a key that is no column; setValueDirectly does not
        $model->setValue('w1_no_column', 'x');
        $this->assertArrayNotHasKey('w1_no_column', $model->value);
        $model->setValueDirectly('w1_no_column', 'x');
        $this->assertSame('x', $model->value['w1_no_column']);

        // Carbon is kept as its array form
        $model->setValue('date', Carbon::parse('2026-01-02 03:04:05'));
        $this->assertIsArray($model->value['date']);
        $this->assertSame('2026-01-02 03:04:05.000000', $model->value['date']['date']);

        // returns the model
        $this->assertSame($model, $model->setValue('text', 'r'));
        $this->assertSame($model, $model->setValueDirectly('text', 'r'));
    }

    public function testCreateRunsTheSameMergeAndEvents(): void
    {
        $model = $this->table()->getValueModel();
        $model->setValue(['text' => 'w1 create', 'integer' => '2,000', 'index_text' => null]);
        [, $log, $events] = $this->observed($model, function () use ($model) {
            return $model->save();
        });
        // the yes/no column's saving() fills its default on create
        $this->assertSame(['text' => 'w1 create', 'integer' => '2000', 'multiples_of_3' => 0], $this->stored($model->id));
        $writes = $this->writes($log);
        $this->assertCount(1, $writes);
        $this->assertStringStartsWith('insert into ' . static::q($this->db()), $writes[0]['query']);
        $this->assertSame(['saving', 'creating', 'created', 'saved'], $events);
    }

    // ----------------------------------------------------------- bypasses

    public function testDisableSavingEventSkipsTheMerge(): void
    {
        $model = $this->record();
        $model->value = ['text' => 'w1 raw', 'index_text' => null];
        $model->disable_saving_event(true);
        [, , $events] = $this->observed($model, function () use ($model) {
            return $model->save();
        });
        $this->assertSame(['text' => 'w1 raw', 'index_text' => null], $this->stored($model->id), 'no merge, null kept');
        $this->assertSame(['saving', 'updating', 'updated', 'saved'], $events);
    }

    public function testSaveQuietlySkipsEventsAndTheMerge(): void
    {
        $model = $this->record();
        $model->setValue('text', 'w1 quiet');
        $model->value = ['text' => 'w1 quiet'];
        [, $log, $events] = $this->observed($model, function () use ($model) {
            return $model->saveQuietly();
        });
        $this->assertSame(['text' => 'w1 quiet'], $this->stored($model->id));
        $this->assertSame([], $events);
        $this->assertCount(1, $this->writes($log));
    }

    public function testRestoreRevisionReplacesTheWholeValue(): void
    {
        $model = $this->record();
        $model->setValue('text', 'w1 rev 1')->save();
        $model->setValue('text', 'w1 rev 2')->save();
        $revision = $model->revisionHistory()->orderBy('revision_no', 'desc')->get()->first(function ($r) {
            $v = json_decode($r->new_value, true);
            return array_get($v, 'text') === 'w1 rev 1';
        });
        $this->assertNotNull($revision, 'revision of the first update');
        $revisionValue = json_decode($revision->new_value, true);

        $restored = $this->table()->getValueModel($model->id);
        $restored->setRevision($revision->suuid);
        $this->assertSame($revisionValue, $restored->value, 'setRevision assigns the whole revision value');
        $restored->restore_revision()->save();
        $this->assertEquals($revisionValue, $this->stored($model->id));
    }
}
