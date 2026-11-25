<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValidateCalledType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\ImportValidationDescriptors;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Validator;
use Illuminate\Http\Request;

class ImportValidationDescriptorsTest extends UnitTestBase
{
    protected const SAMPLE_VALUES = [
        null, '', ' ', '0', 0, 1, '1', -5, 12345678901, '12345678901234567890', '1,000', '1.5', 'abc', 'ABC', 'a@b.com', 'http://example.com',
        '2020-01-01', '2020-13-45', '12:30', '25:61', 'x x', "line1\nline2", 'ほげ',
    ];

    /**
     * @return array<int, string>
     */
    protected function immutableRuleClasses(): array
    {
        $constant = new \ReflectionClassConstant(ImportValidationDescriptors::class, 'IMMUTABLE_RULES');
        return $constant->getValue();
    }

    /**
     * @return array<class-string, object>
     */
    protected function sampleRules(): array
    {
        return [
            Validator\StringNumericRule::class => new Validator\StringNumericRule(),
            Validator\MaxLengthExRule::class => new Validator\MaxLengthExRule(5),
            Validator\NumberMinRule::class => new Validator\NumberMinRule(1),
            Validator\NumberMaxRule::class => new Validator\NumberMaxRule(10),
            Validator\IntegerCommaRule::class => new Validator\IntegerCommaRule(),
            Validator\DecimalCommaRule::class => new Validator\DecimalCommaRule(),
            Validator\SelectRule::class => new Validator\SelectRule(['a', 'b', 1]),
            Validator\SelectValTextRule::class => new Validator\SelectValTextRule(['a' => 'A', 'b' => 'B']),
            Validator\YesNoRule::class => new Validator\YesNoRule(),
            Validator\BooleanRule::class => new Validator\BooleanRule(['0' => 'no', '1' => 'yes']),
            Validator\TimeRule::class => new Validator\TimeRule(),
        ];
    }

    /**
     * Every rule class that may be reused between rows must not change its own state while validating.
     */
    public function testImmutableRulesKeepTheirStateWhenEvaluated()
    {
        $samples = $this->sampleRules();
        $classes = $this->immutableRuleClasses();
        $this->assertEqualsCanonicalizing(array_keys($samples), $classes, 'Add a sample instance for every class of IMMUTABLE_RULES.');

        foreach ($samples as $class => $rule) {
            $before = serialize($rule);
            foreach (static::SAMPLE_VALUES as $value) {
                $rule->passes('attr', $value);
                $rule->message();
                $this->assertSame($before, serialize($rule), "$class changed its state.");
            }
        }
    }

    public function testFreshRulesNeverShareObjects()
    {
        $rule = new Validator\MaxLengthExRule(5);
        $rules = ['value.text' => ['nullable', $rule]];

        $first = ImportValidationDescriptors::freshRules($rules);
        $second = ImportValidationDescriptors::freshRules($rules);

        $this->assertSame('nullable', $first['value.text'][0]);
        $this->assertEquals($rule, $first['value.text'][1]);
        $this->assertNotSame($rule, $first['value.text'][1]);
        $this->assertNotSame($first['value.text'][1], $second['value.text'][1]);
        $this->assertSame($rule, $rules['value.text'][1], 'the described array must not be changed');
    }

    public function testOnlyListedRulesAreImmutable()
    {
        $this->assertTrue(ImportValidationDescriptors::isImmutableRule('max:10'));
        $this->assertTrue(ImportValidationDescriptors::isImmutableRule(new Validator\StringNumericRule()));
        $this->assertFalse(ImportValidationDescriptors::isImmutableRule(new Validator\EmptyRule()));
        $this->assertFalse(ImportValidationDescriptors::isImmutableRule(new Validator\CustomValueRule(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE)));
        $this->assertFalse(ImportValidationDescriptors::isImmutableRule(function () {
            return true;
        }));
    }

    public function testKeyIsolatesTableUserMethodAndLocale()
    {
        $descriptors = new ImportValidationDescriptors();
        $a = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $b = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);

        $original = $this->app->make('request');
        $locale = app()->getLocale();
        try {
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
            $this->app->instance('request', Request::create('/', 'POST'));
            $base = $descriptors->key($a, 'columns', ['value.']);

            $this->assertSame($base, $descriptors->key($a, 'columns', ['value.']));
            $this->assertNotSame($base, $descriptors->key($b, 'columns', ['value.']));
            $this->assertNotSame($base, $descriptors->key($a, 'columns', ['other.']));
            $this->assertNotSame($base, $descriptors->key($a, 'attributes', ['value.']));

            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
            $this->assertNotSame($base, $descriptors->key($a, 'columns', ['value.']));
            $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

            $this->app->instance('request', Request::create('/', 'PUT'));
            $this->assertNotSame($base, $descriptors->key($a, 'columns', ['value.']));
            $this->app->instance('request', Request::create('/', 'POST'));

            app()->setLocale($locale === 'en' ? 'ja' : 'en');
            $this->assertNotSame($base, $descriptors->key($a, 'columns', ['value.']));
        } finally {
            app()->setLocale($locale);
            $this->app->instance('request', $original);
        }
    }

    /**
     * Described rules + messages must equal the per-row build, with ONE descriptor object shared by two tables, three users,
     * create/update, three request methods and two locales interleaved in one process.
     */
    public function testDescriptorsGiveTheSameRulesAndMessagesAsPerRowBuild()
    {
        mt_srand(20260101);
        $tables = [
            CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST),
            CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE),
            CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL),
        ];
        $users = [TestDefine::TESTDATA_USER_LOGINID_ADMIN, TestDefine::TESTDATA_USER_LOGINID_USER1, TestDefine::TESTDATA_USER_LOGINID_USER2];
        $methods = ['POST', 'PUT', 'GET'];
        $optionSets = [
            ['systemColumn' => true, 'column_name_prefix' => 'value.', 'appendKeyName' => true, 'checkCustomValueExists' => false],
            ['systemColumn' => true, 'column_name_prefix' => 'value.', 'appendKeyName' => true, 'checkCustomValueExists' => true],
            ['systemColumn' => false, 'column_name_prefix' => 'value.', 'appendKeyName' => false, 'checkCustomValueExists' => true],
        ];

        $descriptors = new ImportValidationDescriptors();
        $original = $this->app->make('request');
        $locale = app()->getLocale();
        $compared = 0;
        $described = 0;
        $notDescribed = 0;

        try {
            for ($i = 0; $i < 240; $i++) {
                $table = $tables[$i % count($tables)];
                $this->be(LoginUser::find($users[mt_rand(0, count($users) - 1)]));
                $this->app->instance('request', Request::create('/', $methods[mt_rand(0, 2)]));
                app()->setLocale(mt_rand(0, 1) ? 'ja' : 'en');
                $options = $optionSets[mt_rand(0, count($optionSets) - 1)] + ['validateLock' => false, 'calledType' => ValidateCalledType::IMPORT];

                $model = mt_rand(0, 1) ? $table->getValueModel(1) : $table->getValueModel();
                $input = ['value' => []];
                foreach ($table->custom_columns_cache as $column) {
                    if (mt_rand(0, 9) > 0) {
                        // Attachment fields only accept file objects/arrays in their own getValidator (out of scope, never described).
                        $pool = ColumnType::isAttachment($column->column_type) ? [null, ''] : static::SAMPLE_VALUES;
                        $input['value'][$column->column_name] = $pool[mt_rand(0, count($pool) - 1)];
                    }
                }
                $data = array_dot_reverse($input);

                $plain = $table->getValidateRules($data, $model, $options);
                $cached = $table->getValidateRules($data, $model, $options + ['validationDescriptors' => $descriptors]);
                $this->assertSame(array_keys($plain), array_keys($cached), "rule key order differs at #$i");
                $this->assertEquals($this->normalize($plain), $this->normalize($cached), "rules differ at #$i");

                $v1 = $table->validateValue($data, $model, $options);
                $v2 = $table->validateValue($data, $model, $options + ['validationDescriptors' => $descriptors]);
                $this->assertSame($v1->fails(), $v2->fails(), "result differs at #$i");
                $this->assertSame(json_encode($v1->getMessages()), json_encode($v2->getMessages()), "messages differ at #$i");
                $compared++;
            }

            foreach ($tables as $table) {
                foreach ($descriptors->columns($table, 'value.') as $descriptor) {
                    is_null($descriptor['rules']) ? $notDescribed++ : $described++;
                }
            }
        } finally {
            app()->setLocale($locale);
            $this->app->instance('request', $original);
        }

        $this->assertSame(240, $compared);
        $this->assertGreaterThan(0, $described, 'no column was described: the cached path was not exercised');
        $this->assertGreaterThan(0, $notDescribed, 'no column fell back: the per-row path was not exercised');
    }

    /**
     * init_only depends on the stored value of the row: it must never be described, and an update of a row that has a value must still validate as before.
     */
    public function testInitOnlyColumnIsNotDescribed()
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $column = $table->custom_columns_cache->first(function ($column) {
            return $column->column_type == 'text';
        });
        $this->assertNotNull($column);

        $options = $column->options;
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        try {
            $column->options = array_merge($options, ['init_only' => 1]);
            $descriptors = new ImportValidationDescriptors();
            $described = collect($descriptors->columns($table, 'value.'))->first(function ($d) use ($column) {
                return $d['column']->id == $column->id;
            });
            $this->assertNull($described['rules']);

            $update = $table->getValueModel(1);
            $data = array_dot_reverse(['value' => [$column->column_name => 'changed']]);
            $opts = ['systemColumn' => true, 'column_name_prefix' => 'value.', 'checkCustomValueExists' => false, 'validateLock' => false];
            $v1 = $table->validateValue($data, $update, $opts);
            $v2 = $table->validateValue($data, $update, $opts + ['validationDescriptors' => $descriptors]);
            $this->assertSame(json_encode($v1->getMessages()), json_encode($v2->getMessages()));
        } finally {
            $column->options = $options;
        }
    }

    /**
     * @param mixed $rules
     * @return mixed
     */
    protected function normalize($rules)
    {
        if (is_array($rules)) {
            return array_map(function ($rule) {
                return $this->normalize($rule);
            }, $rules);
        }
        if (is_object($rules)) {
            return in_array(get_class($rules), $this->immutableRuleClasses(), true) ? [get_class($rules), (array)$rules] : get_class($rules);
        }
        return $rules;
    }
}
