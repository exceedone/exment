<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\DefaultTableProvider;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Step 2 (ImportValidationDescriptors) compatibility: the whole pipeline
 * getDataObject -> validateImportData -> dataProcessing -> importData is run over several tables
 * (create + update, valid + invalid sheets, alternating tables) with and without the descriptors,
 * in both orders, each run rolled back to a savepoint. Saved rows, validation errors, converted data and
 * the sequence of Eloquent events (incl. the installed TestPluginEventSaved / TestPluginValidator test plugins) must be identical.
 */
class ImportStep2CompatibilityTest extends UnitTestBase
{
    protected const VOLATILE = ['id', 'suuid', 'created_at', 'updated_at', 'deleted_at', 'created_user_id', 'updated_user_id', 'auto_number'];

    protected ?array $queryLog = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        DB::listen(function ($query) {
            if ($this->queryLog !== null) {
                $this->queryLog[] = $query->sql;
            }
        });
    }

    /**
     * @return array<int, array{table: string, pk: string, headers: array<int, string>, rows: array<int, array<int, mixed>>}>
     */
    protected function phases(): array
    {
        $aHeaders = ['value.text', 'value.integer', 'value.decimal', 'value.currency', 'value.date', 'value.time', 'value.select',
            'value.select_valtext', 'value.yesno', 'value.boolean', 'value.email', 'value.url', 'value.textarea'];
        $bHeaders = ['value.text', 'value.index_text', 'value.integer', 'value.decimal', 'value.currency', 'value.date',
            'value.email', 'value.multiples_of_3', 'value.init_text', 'value.odd_even'];

        $a = fn (array $rows) => ['table' => TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, 'pk' => 'value.text', 'headers' => $aHeaders, 'rows' => $rows];
        $b = fn (array $rows) => ['table' => TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL, 'pk' => 'value.index_text', 'headers' => $bHeaders, 'rows' => $rows];

        return [
            'A create' => $a([
                ['s2-a-1', '10', '1.5', '1,000', '2020-01-01', '12:30', 'foo', 'FOO', '1', 'ok', 'a1@example.com', 'http://example.com/1', "l1\\nl2"],
                ['s2-a-2', '20', '2.25', '2000', '2020-02-02', '0830', 'bar', 'BAR', '0', 'ng', 'a2@example.com', null, null],
                ['s2-a-3', null, null, null, null, null, null, null, null, null, null, null, null],
            ]),
            'B create' => $b([
                ['s2-b-1', 's2-bi-1', '5', '0.5', '100', '2021-03-03', 'b1@example.com', '1', 'init1', 'odd'],
                ['s2-b-2', 's2-bi-2', '6', '1.5', '200', '2021-04-04', 'b2@example.com', '0', 'init2', 'even'],
            ]),
            'A update + new' => $a([
                ['s2-a-1', '11', '1.75', '1,500', '2020-01-09', '13:30', 'baz', 'BAZ', '0', 'ng', 'a1x@example.com', 'http://example.com/1x', 'changed'],
                ['s2-a-2', '21', null, null, null, null, 'foo', 'FOO', '1', 'ok', null, null, null],
                ['s2-a-4', '40', '4', '4', '2020-04-04', '04:40', 'bar', 'BAR', '1', 'ok', 'a4@example.com', null, null],
            ]),
            'B update + new' => $b([
                ['s2-b-1u', 's2-bi-1', '55', '5.5', '1100', '2021-03-09', 'b1u@example.com', '0', 'init1', 'even'],
                ['s2-b-3', 's2-bi-3', '7', '2.5', '300', '2021-05-05', 'b3@example.com', '1', 'init3', 'odd'],
            ]),
            'A invalid' => $a([
                ['s2-a-5', 'abc', '1.5.5', 'x', '2020-13-45', '25:61', 'qux', 'NOPE', 'maybe', 'zz', 'not-mail', 'not-url', null],
                ['s2-a-6', '12345678901234567890123', '1', '1', '2020-01-01', '12:30', 'foo', 'FOO', '1', 'ok', 'a6@example.com', null, null],
                ['s2-a-1', '99', null, null, null, null, null, null, null, null, null, null, null],
            ]),
            'B invalid' => $b([
                [null, 's2-bi-7', 'x', 'y', 'z', '2021-99-99', 'bad', 'maybe', null, null],
                ['s2-b-8', 's2-bi-8', '1', '1', '1', '2021-01-01', 'b8@example.com', '1', null, null],
            ]),
            'B plugin saved + validator' => $b([
                ['s2-b-9', 's2-bi-9', '3', null, '30', '2021-06-06', 'b9@example.com', '1', 'init9', 'odd'],
                ['s2-b-10', 's2-bi-10', null, null, '31', '2021-06-07', 'b10@example.com', '0', 'init10', 'even'],
            ]),
            'A create again' => $a([
                ['s2-a-7', '70', '7', '7', '2020-07-07', '07:07', 'baz', 'BAZ', '0', 'ng', 'a7@example.com', null, null],
            ]),
        ];
    }

    protected function provider(bool $descriptors, CustomTable $table, string $pk): DefaultTableProvider
    {
        $args = ['custom_table' => $table, 'primary_key' => $pk];
        if ($descriptors) {
            return new DefaultTableProvider($args);
        }

        // the pre-Step-2 per-row rule build: the descriptors stay null for every row
        return new class($args) extends DefaultTableProvider {
            public function validateDataRow($line_no, $dataAndModel, $validate_columns, $dataObjects)
            {
                $this->validationDescriptors = null;
                return parent::validateDataRow($line_no, $dataAndModel, $validate_columns, $dataObjects);
            }
        };
    }

    protected function normalize($value)
    {
        if ($value instanceof \Illuminate\Contracts\Support\Arrayable) {
            $value = $value->toArray();
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) {
                if (is_string($k) && in_array($k, static::VOLATILE, true)) {
                    continue;
                }
                $out[$k] = $this->normalize($v);
            }
            return $out;
        }
        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function runPipeline(bool $descriptors): array
    {
        System::clearCache();
        $result = ['phases' => [], 'hooks' => [], 'saved' => []];

        $listener = function ($eventName, $payload) use (&$result) {
            if (!preg_match('/^eloquent\.(saving|saved|creating|created|updating|updated|deleting|deleted): /', $eventName)) {
                return;
            }
            $model = $payload[0] ?? null;
            if (!($model instanceof \Exceedone\Exment\Model\CustomValue)) {
                return;
            }
            $result['hooks'][] = [
                preg_replace('/: .*/', '', $eventName),
                $model->custom_table_name ?? null,
                $this->normalize(is_string($raw = $model->getAttributes()['value'] ?? null) ? json_decode($raw, true) : $raw),
            ];
        };
        Event::listen('eloquent.*: *', $listener);
        $this->queryLog = [];

        DB::beginTransaction();
        try {
            // rows that TestPluginEventSaved ("integer" + 100 on custom_value_view_all with the same id) can modify
            $viewTable = getDBTableName('custom_value_view_all');
            $editTable = getDBTableName(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
            $nextId = (int)DB::selectOne('select max(id) as m from ' . $editTable)->m + 1;
            if (\Exceedone\Exment\Services\Storage\PhysicalSchemaManager::driverFamily() === 'sqlsrv') {
                // next identity value of the table (SQL Server has no information_schema.tables.auto_increment)
                $nextId = max($nextId, (int)DB::selectOne('select IDENT_CURRENT(?) + IDENT_INCR(?) as a', [$editTable, $editTable])->a);
            } else {
                $nextId = max($nextId, (int)DB::selectOne('select auto_increment as a from information_schema.tables where table_schema = database() and table_name = ?', [$editTable])->a);
            }
            // explicit ids into an identity column: SQL Server needs IDENTITY_INSERT (this is inside the test's transaction)
            $identity = \Exceedone\Exment\Services\Storage\PhysicalSchemaManager::driverFamily() === 'sqlsrv';
            if ($identity) {
                DB::unprepared("SET IDENTITY_INSERT [$viewTable] ON");
            }
            for ($i = 0; $i < 12; $i++) {
                DB::table($viewTable)->insert(['id' => $nextId + $i, 'suuid' => 's2probe' . $i . substr(md5((string)$nextId), 0, 8), 'value' => json_encode(['integer' => 1]), 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($identity) {
                DB::unprepared("SET IDENTITY_INSERT [$viewTable] OFF");
            }
            foreach ($this->phases() as $name => $phase) {
                $table = CustomTable::getEloquent($phase['table']);
                $provider = $this->provider($descriptors, $table, $phase['pk']);

                $raw = array_merge([$phase['headers'], $phase['headers']], $phase['rows']);

                $dataObject = $provider->getDataObject($raw, []);
                $converted = $this->normalize(array_map(fn ($o) => $o['data'], $dataObject));
                [$success, $errors] = $provider->validateImportData($dataObject);

                $saved = 0;
                if (empty($errors)) {
                    foreach ($success as $row) {
                        $row['data'] = $provider->dataProcessing($row['data']);
                        $provider->importData($row);
                        $saved++;
                    }
                }
                $result['phases'][$name] = [
                    'converted' => $converted,
                    'errors' => $errors,
                    'success' => count($success),
                    'saved' => $saved,
                ];
            }

            foreach ([TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST, TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL] as $tableName) {
                $rows = CustomTable::getEloquent($tableName)->getValueModel()->query()
                    ->where('value->text', 'like', 's2-%')->get()
                    ->map(fn ($m) => $this->normalize($m->getAttributes()['value'] ? json_decode($m->getAttributes()['value'], true) : null))
                    ->sortBy(fn ($v) => json_encode($v))->values()->all();
                $result['saved'][$tableName] = $rows;
            }
            $result['saved']['plugin_effect'] = DB::table($viewTable)->where('id', '>=', $nextId)->where('id', '<', $nextId + 12)
                ->orderBy('id')->pluck('value')->map(fn ($v) => json_decode($v, true)['integer'] ?? null)->all();
        } finally {
            DB::rollBack();
            Event::forget('eloquent.*: *');
            $result['queries'] = $this->queryLog;
            $this->queryLog = null;
        }

        return $result;
    }

    /**
     * Make TestPluginValidator (validator plugin of the test data) active for custom_value_edit_all inside the test transaction.
     */
    protected function targetValidatorPluginAtEditAll(): void
    {
        $plugin = DB::table('plugins')->where('plugin_name', 'TestPluginValidator')->first();
        $this->assertNotNull($plugin, 'TestPluginValidator must exist in the test data.');
        $options = json_decode($plugin->options, true) ?: [];
        $options['target_tables'] = [TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL];
        DB::table('plugins')->where('id', $plugin->id)->update(['options' => json_encode($options)]);
        System::clearCache();
    }

    public function testPipelineGivesIdenticalResultsWithAndWithoutDescriptors()
    {
        $this->targetValidatorPluginAtEditAll();

        $this->runPipeline(true); // warm-up: class boot / first-use caches are not part of the comparison
        $withFirst = $this->runPipeline(true);
        $without = $this->runPipeline(false);
        $withSecond = $this->runPipeline(true);
        $withoutSecond = $this->runPipeline(false);

        // sanity: the scenario really exercises create, update, errors and the plugins
        $this->assertGreaterThan(0, $withFirst['phases']['A create']['saved'], json_encode($withFirst['phases']['A create']['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertGreaterThan(0, $withFirst['phases']['B update + new']['saved'], json_encode($withFirst['phases']['B update + new']['errors'], JSON_UNESCAPED_UNICODE));
        $this->assertNotEmpty($withFirst['phases']['A invalid']['errors']);
        $this->assertNotEmpty($withFirst['phases']['B invalid']['errors']);
        $this->assertSame(0, $withFirst['phases']['A invalid']['saved']);
        $this->assertNotEmpty($withFirst['hooks']);
        $this->assertNotEmpty($withFirst['phases']['B plugin saved + validator']['errors'], 'TestPluginValidator must have run for custom_value_edit_all.');
        $this->assertContains('101', array_map('strval', $withFirst['saved']['plugin_effect']), 'TestPluginEventSaved must have run for custom_value_edit_all saves: ' . json_encode($withFirst['saved']['plugin_effect']));

        foreach (['phases', 'saved', 'hooks'] as $key) {
            foreach (['descriptors first' => $withFirst, 'descriptors after legacy' => $withSecond, 'legacy repeated' => $withoutSecond] as $label => $run) {
                $this->assertSame(json_encode($without[$key]), json_encode($run[$key]), "$key differ ($label): " . $this->firstDifference($without[$key], $run[$key]));
            }
        }

        // Step 2 must not add or remove queries; only the order in which lazily-built metadata is first read may move.
        $expectedQueries = array_count_values($without['queries']);
        ksort($expectedQueries);
        foreach (['descriptors first' => $withFirst, 'descriptors after legacy' => $withSecond, 'legacy repeated' => $withoutSecond] as $label => $run) {
            $actualQueries = array_count_values($run['queries']);
            ksort($actualQueries);
            $this->assertSame(count($without['queries']), count($run['queries']), "query count differs ($label)");
            $this->assertSame($expectedQueries, $actualQueries, "query statements differ ($label)");
        }
    }

    protected function firstDifference(array $expected, array $actual): string
    {
        foreach ($expected as $i => $value) {
            if (!array_key_exists($i, $actual) || json_encode($value) !== json_encode($actual[$i])) {
                return "at #$i expected " . substr(json_encode($value, JSON_UNESCAPED_UNICODE), 0, 600)
                    . ' actual ' . substr(json_encode($actual[$i] ?? null, JSON_UNESCAPED_UNICODE), 0, 600);
            }
        }
        return 'actual has ' . (count($actual) - count($expected)) . ' extra entries';
    }
}
