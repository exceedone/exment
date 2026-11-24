<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\DataItems\Form\DefaultForm;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\MessageBag;

/**
 * #1720: when a record is saved with its child rows (1:n block), every posted child row is
 * validated with the model of that row. The rows were looked up with findOrNew() on one shared
 * relation object, which adds "where id = ?" to that relation's query each time, so only the
 * first row was found and the following existing rows were validated as new records.
 *
 * The lock check (updated_at) only works for a row that exists, so it tells whether the second
 * and third rows were found.
 */
class DefaultFormChildRowValidationTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const PREFIX = 'issue1720_';

    private const STALE_UPDATED_AT = '2000-01-01 00:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testExistingChildRowsAfterTheFirstAreValidatedAsExisting(): void
    {
        list($parent, $children) = $this->createRows();

        // only the last row was changed by someone else after the form was opened
        $message = $this->validate($parent, $children, [2 => self::STALE_UPDATED_AT]);
        $this->assertTrue($message->has('updated_at'), 'the third child row is validated as the existing record');

        // the same for the second row
        $message = $this->validate($parent, $children, [1 => self::STALE_UPDATED_AT]);
        $this->assertTrue($message->has('updated_at'), 'the second child row is validated as the existing record');
    }

    public function testUnchangedChildRowsPassTheLockCheck(): void
    {
        list($parent, $children) = $this->createRows();

        $message = $this->validate($parent, $children, []);
        $this->assertFalse($message->has('updated_at'));
    }

    /**
     * One parent and three children, saved into the test data's tables and rolled back.
     *
     * @return array{0: CustomValue, 1: array<int, CustomValue>}
     */
    private function createRows(): array
    {
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);

        $parent = $parent_table->getValueModel();
        $parent->setValue(['text' => self::PREFIX . 'parent']);
        $parent->saved_notify(false);
        $parent->save();

        $children = [];
        foreach (['a', 'b', 'c'] as $suffix) {
            $child = $child_table->getValueModel();
            $child->setValue(['text' => self::PREFIX . $suffix, 'index_text' => self::PREFIX . $suffix]);
            $child->parent_id = $parent->id;
            $child->parent_type = $parent_table->table_name;
            $child->saved_notify(false);
            $child->save();
            $children[] = $child;
        }

        return [$parent, $children];
    }

    /**
     * Run the "validator saving" callback DefaultForm registers on the form, with the input the
     * edit form of the parent posts: the parent and its child rows keyed by the child id.
     *
     * @param CustomValue $parent
     * @param array<int, CustomValue> $children
     * @param array<int, string> $updatedAts posted updated_at by row index. Default: the value in the database
     */
    private function validate(CustomValue $parent, array $children, array $updatedAts): MessageBag
    {
        $parent_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $child_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE);
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        $this->assertNotNull($relation, 'seed data has a relation from parent_table to child_table');
        $relation_name = $relation->getRelationName();
        $this->assertTrue(method_exists($parent, $relation_name), "the parent model has the relation method $relation_name");

        $rows = [];
        foreach ($children as $index => $child) {
            // the stored updated_at, as the edit form shows it
            $stored = \Illuminate\Support\Facades\DB::table(getDBTableName($child_table))->where('id', $child->id)->value('updated_at');
            $rows[$child->id] = [
                'id' => $child->id,
                'updated_at' => $updatedAts[$index] ?? \Carbon\Carbon::parse($stored)->format('Y-m-d H:i:s'),
                'value' => ['text' => $child->getValue('text'), 'index_text' => $child->getValue('index_text')],
            ];
        }
        $input = [
            'value' => ['text' => $parent->getValue('text')],
            $relation_name => $rows,
        ];

        // a form with only the 1:n block of child_table
        $block = new class () {
            /** @var bool */
            public $available = true;
            /** @var int */
            public $form_block_type = FormBlockType::ONE_TO_MANY;
            /** @var array<int, mixed> */
            public $relationInfo = [];

            /**
             * @return array<int, mixed>
             */
            public function getRelationInfo(): array
            {
                return $this->relationInfo;
            }
        };
        $block->relationInfo = [$relation, $relation_name, 'child_table'];
        $custom_form = new \stdClass();
        $custom_form->custom_form_blocks = [$block];

        // stands in for the admin form: keeps the callback and hands out the record being edited
        $form = new class () {
            /** @var \Closure|null */
            public $validatorSavingCallback;
            /** @var CustomValue|null */
            public $model;

            public function saving(\Closure $callback): void
            {
            }

            public function validatorSavingCallback(\Closure $callback): void
            {
                $this->validatorSavingCallback = $callback;
            }

            public function prepareCallback(\Closure $callback): void
            {
            }

            public function model(): ?CustomValue
            {
                return $this->model;
            }
        };
        $form->model = $parent_table->getValueModel($parent->id);
        $this->assertNotNull($form->model);

        $item = DefaultForm::getItem($parent_table, $custom_form)->id($parent->id);
        $method = new \ReflectionMethod(DefaultForm::class, 'manageFormSaving');
        $method->setAccessible(true);
        $method->invoke($item, $form);
        $this->assertNotNull($form->validatorSavingCallback, 'DefaultForm registers the validator saving callback');

        $message = new MessageBag();
        call_user_func($form->validatorSavingCallback, $input, $message, $form);

        return $message;
    }
}
