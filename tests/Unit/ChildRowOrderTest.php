<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Form\Field\HasMany;
use Exceedone\Exment\Form\Field\HasManyTable;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Admin;
use ExmentAdminCore\Admin\Form;
use Illuminate\Contracts\View\View;
use Illuminate\Support\ViewErrorBag;

/**
 * #1595: the rows of a child table can be moved up and down, and the order is
 * kept in a number column of the child table (the sort column of the form block).
 *
 * The tests look at the sort column of a form block, at what the field does with
 * the rows (the numbers written on saving, the order they are read in, the
 * buttons and their script), at the data form and a real save, and at the form
 * setting that keeps only a number column. Everything a test writes is rolled back.
 */
class ChildRowOrderTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const PARENT_TABLE = TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE;

    private const CHILD_TABLE = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;

    /** an integer column of child_table (seeded) */
    private const SORT_COLUMN = 'integer';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        System::clearRequestSession();
        System::clearCache();
        $this->clearPageScripts();

        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        view()->share('errors', new ViewErrorBag());
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        System::clearCache();
        $this->clearPageScripts();
        parent::tearDown();
    }

    /**
     * A field puts its script into the static script list of admin-core when it
     * is rendered. In a real request the list starts empty, but in a test run it
     * is kept from one request to the next, so a page would carry the scripts of
     * the fields drawn before it (even by an earlier request of the same test).
     *
     * @return void
     */
    private function clearPageScripts(): void
    {
        Admin::$script = [];
        Admin::$deferredScript = [];
    }

    // ----------------------------------------------------------- the sort column of a block

    /**
     * A 1:n block without the option has no sort column.
     */
    public function testSortColumnIsNullWithoutTheOption(): void
    {
        $block = $this->prepareChildBlock(1, null);

        $this->assertNull($block->getSortColumn());
    }

    /**
     * A text column cannot be the sort column, and neither can a name that is
     * not a column of the child table.
     */
    public function testSortColumnIsNullForATextColumnOrAnUnknownName(): void
    {
        $block = $this->prepareChildBlock(1, 'index_text');
        $this->assertNull($block->getSortColumn(), 'a text column');

        $block = $this->prepareChildBlock(1, 'no_such_column_1595');
        $this->assertNull($block->getSortColumn(), 'not a column');
    }

    /**
     * Only a 1:n block has a sort column, whatever its options say.
     */
    public function testSortColumnIsNullForANonOneToManyBlock(): void
    {
        $child_table = $this->childTable();

        $block = new CustomFormBlock();
        $block->form_block_type = FormBlockType::DEFAULT;
        $block->form_block_target_table_id = $child_table->id;
        $block->options = ['sort_column' => self::SORT_COLUMN];
        $this->assertNull($block->getSortColumn());

        $block = new CustomFormBlock();
        $block->form_block_type = FormBlockType::MANY_TO_MANY;
        $block->form_block_target_table_id = $child_table->id;
        $block->options = ['sort_column' => self::SORT_COLUMN];
        $this->assertNull($block->getSortColumn());
    }

    /**
     * An integer column is the sort column, and so is a decimal column.
     */
    public function testSortColumnIsTheColumnForAnIntegerOrDecimalColumn(): void
    {
        $child_table = $this->childTable();

        $block = $this->prepareChildBlock(1, self::SORT_COLUMN);
        $column = $block->getSortColumn();
        $this->assertNotNull($column);
        $this->assertSame(self::SORT_COLUMN, $column->column_name);
        $this->assertSame(CustomColumn::getEloquent(self::SORT_COLUMN, $child_table)->id, $column->id);

        $block = $this->prepareChildBlock(1, 'decimal');
        $column = $block->getSortColumn();
        $this->assertNotNull($column);
        $this->assertSame('decimal', $column->column_name);
    }

    // ----------------------------------------------------------- prepare()

    /**
     * Saving writes 1, 2, 3... into the sort column in the order the rows were
     * posted, a removed row is left alone (block style).
     */
    public function testPrepareWritesTheOrderOfTheRowsForTheBlockStyle(): void
    {
        $this->assertPrepareWritesTheOrder(HasMany::class);
    }

    /**
     * The same for the table style.
     */
    public function testPrepareWritesTheOrderOfTheRowsForTheTableStyle(): void
    {
        $this->assertPrepareWritesTheOrder(HasManyTable::class);
    }

    /**
     * The numbers go up by the power.
     */
    public function testPrepareGoesUpByThePower(): void
    {
        $field = $this->makeSortField(HasMany::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN, 10);

        $byName = $this->orderByName($field->prepare($this->postedRows()));
        $this->assertSame(10, $byName['a']);
        $this->assertSame(20, $byName['c']);
        $this->assertNull($byName['b']);
    }

    /**
     * Without rowUpDown nothing is written.
     */
    public function testPrepareWritesNothingWithoutRowUpDown(): void
    {
        $field = $this->makeSortField(HasMany::class);

        $byName = $this->orderByName($field->prepare($this->postedRows()));
        $this->assertNull($byName['a']);
        $this->assertNull($byName['c']);
    }

    // ----------------------------------------------------------- sortValueByRowUpDown()

    /**
     * The rows read from the record are put in the order of the column, the
     * rows without a number last.
     */
    public function testSortValueByRowUpDownOrdersTheRows(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN, 1, true);
        $this->setValue($field, [
            $this->valueRow('a', 3),
            $this->valueRow('b', null),
            $this->valueRow('c', 1),
            $this->valueRow('d', 2),
        ]);

        $this->sortValue($field);

        $this->assertSame(['c', 'd', 'a', 'b'], $this->namesOf($field));
    }

    /**
     * Rows with the same number keep the order they came in, and so do rows
     * without a number.
     */
    public function testSortValueByRowUpDownKeepsTheOrderOfTies(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN, 1, true);
        $this->setValue($field, [
            $this->valueRow('a', 2),
            $this->valueRow('b', null),
            $this->valueRow('c', 1),
            $this->valueRow('d', 2),
            $this->valueRow('e', null),
            $this->valueRow('f', 1),
        ]);

        $this->sortValue($field);

        $this->assertSame(['c', 'f', 'a', 'd', 'b', 'e'], $this->namesOf($field));
    }

    /**
     * Without the sort flag the rows stay as they came.
     */
    public function testSortValueByRowUpDownDoesNothingWithoutTheFlag(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN);
        $this->setValue($field, [
            $this->valueRow('a', 3),
            $this->valueRow('b', null),
            $this->valueRow('c', 1),
            $this->valueRow('d', 2),
        ]);

        $this->sortValue($field);

        $this->assertSame(['a', 'b', 'c', 'd'], $this->namesOf($field));
    }

    // ----------------------------------------------------------- rowMoveScript() and the table buttons

    /**
     * Without rowUpDown the block style has no script for the buttons.
     */
    public function testRowMoveScriptIsEmptyWithoutRowUpDown(): void
    {
        $field = $this->makeField(HasMany::class);

        $this->assertSame('', $this->rowMoveScript($field));
        $this->assertStringNotContainsString('rowmove_', $this->scriptOf($field));
    }

    /**
     * With rowUpDown the block style adds the buttons by script and binds the click.
     */
    public function testRowMoveScriptHasButtonsAndClickWithRowUpDown(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN);

        $script = $this->rowMoveScript($field);
        $this->assertStringContainsString('.row-move', $script);
        $this->assertStringContainsString('row-move-up', $script);
        $this->assertStringContainsString('row-move-down', $script);
        $this->assertStringContainsString('click.admin_row_move', $script);
        $this->assertStringContainsString('rowmove_', $script);

        // and the script of the field carries it
        $this->assertStringContainsString('click.admin_row_move', $this->scriptOf($field));
    }

    /**
     * The table style draws the up and down buttons only with rowUpDown.
     */
    public function testHasManyTableDrawsTheButtonsOnlyWithRowUpDown(): void
    {
        $field = $this->makeField(HasManyTable::class);
        $this->assertStringNotContainsString('row-move', $this->renderField($field));

        $field = $this->makeField(HasManyTable::class);
        $field->rowUpDown('value.' . self::SORT_COLUMN, 1, true);
        $html = $this->renderField($field);
        $this->assertStringContainsString('row-move-up', $html);
        $this->assertStringContainsString('row-move-down', $html);
    }

    // ----------------------------------------------------------- the data form

    /**
     * The table style of the data form has the buttons when the block has a sort
     * column, and none without.
     */
    public function testDataFormTableStyle(): void
    {
        $this->prepareChildBlock(1, self::SORT_COLUMN);
        $content = $this->getCreatePage();
        $this->assertStringContainsString('has-many-table-', $content, 'precondition: the table style is drawn');
        $this->assertStringContainsString('row-move row-move-up', $content);
        $this->assertStringContainsString('row-move row-move-down', $content);

        $this->prepareChildBlock(1, null);
        $content = $this->getCreatePage();
        $this->assertStringContainsString('has-many-table-', $content, 'precondition: the table style is drawn');
        // the click handler of the table style is always there (it was before #1595), the buttons are not
        $this->assertStringNotContainsString('row-move row-move-up', $content);
        $this->assertStringNotContainsString('row-move row-move-down', $content);
    }

    /**
     * The block style of the data form has the script of the buttons when the
     * block has a sort column, and none without.
     */
    public function testDataFormBlockStyle(): void
    {
        $this->prepareChildBlock(0, self::SORT_COLUMN);
        $content = $this->getCreatePage();
        $this->assertStringNotContainsString('has-many-table-', $content, 'precondition: the block style is drawn');
        $this->assertStringContainsString('rowmove_', $content);
        $this->assertStringContainsString('click.admin_row_move', $content);

        $this->prepareChildBlock(0, null);
        $content = $this->getCreatePage();
        $this->assertStringNotContainsString('has-many-table-', $content, 'precondition: the block style is drawn');
        $this->assertStringNotContainsString('rowmove_', $content);
        $this->assertStringNotContainsString('click.admin_row_move', $content);
    }

    // ----------------------------------------------------------- the hidden input

    /**
     * When the block does not show the sort column, each row holds it as a
     * hidden input (table style).
     */
    public function testHiddenSortInputInTheTableStyle(): void
    {
        $this->prepareChildBlock(1, self::SORT_COLUMN, false);
        $content = $this->getCreatePage();
        $this->assertGreaterThanOrEqual(1, $this->countHiddenInputs($content, self::SORT_COLUMN));

        // without the option there is no such input
        $this->prepareChildBlock(1, null, false);
        $this->assertSame(0, $this->countHiddenInputs($this->getCreatePage(), self::SORT_COLUMN));
    }

    /**
     * The block style draws its rows with gridEmbeds, which draws only the
     * columns placed in the grid of the block, so the hidden input is not on the
     * page. It is not needed there: prepare() writes the order into each posted
     * row, and the saving test of the block style checks that the numbers are
     * saved without it.
     */
    public function testNoHiddenSortInputInTheBlockStyle(): void
    {
        $this->prepareChildBlock(0, self::SORT_COLUMN, false);
        $this->assertSame(0, $this->countHiddenInputs($this->getCreatePage(), self::SORT_COLUMN));

        $this->prepareChildBlock(0, null, false);
        $this->assertSame(0, $this->countHiddenInputs($this->getCreatePage(), self::SORT_COLUMN));
    }

    /**
     * When the block shows the sort column, no hidden input is added: the
     * column has its own input.
     */
    public function testNoHiddenSortInputWhenTheBlockShowsTheColumn(): void
    {
        $this->prepareChildBlock(1, self::SORT_COLUMN, true);

        $this->assertSame(0, $this->countHiddenInputs($this->getCreatePage(), self::SORT_COLUMN));
    }

    // ----------------------------------------------------------- saving

    /**
     * Saving a parent record with two child rows writes 1 and 2 into the sort
     * column in the order the rows were posted, also when the block does not
     * show the column (table style).
     */
    public function testSavingWritesTheOrderOfTheRowsForTheTableStyle(): void
    {
        $this->assertSavingWritesTheOrder(1);
    }

    /**
     * The same for the block style.
     */
    public function testSavingWritesTheOrderOfTheRowsForTheBlockStyle(): void
    {
        $this->assertSavingWritesTheOrder(0);
    }

    // ----------------------------------------------------------- the form setting

    /**
     * Saving the form setting keeps the sort column when it is a number column
     * of the child table, and drops anything else.
     */
    public function testFormSettingKeepsOnlyANumberColumn(): void
    {
        $block = $this->prepareChildBlock(1, null);

        $this->assertSame(self::SORT_COLUMN, $this->patchSortColumn($block, self::SORT_COLUMN), 'integer column is kept');
        $this->assertSame('decimal', $this->patchSortColumn($block, 'decimal'), 'decimal column is kept');
        $this->assertNull($this->patchSortColumn($block, 'index_text'), 'text column is dropped');

        $this->patchSortColumn($block, self::SORT_COLUMN);
        $this->assertNull($this->patchSortColumn($block, 'index_text'), 'text column replaces the old one with null');
        $this->assertNull($this->patchSortColumn($block, 'no_such_column_1595'), 'unknown name is dropped');

        $this->patchSortColumn($block, self::SORT_COLUMN);
        $this->assertNull($this->patchSortColumn($block, ''), 'empty clears it');
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return CustomTable
     */
    private function childTable(): CustomTable
    {
        $child_table = CustomTable::getEloquent(self::CHILD_TABLE);
        $this->assertNotNull($child_table);
        return $child_table;
    }

    /**
     * @return CustomTable
     */
    private function parentTable(): CustomTable
    {
        $parent_table = CustomTable::getEloquent(self::PARENT_TABLE);
        $this->assertNotNull($parent_table);
        return $parent_table;
    }

    /**
     * Rows as posted: new_1, 5 (removed) and new_2 in this order.
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function postedRows(): array
    {
        return [
            'new_1' => ['value' => ['index_text' => 'a'], '_remove_' => 0],
            '5' => ['value' => ['index_text' => 'b'], '_remove_' => 1],
            'new_2' => ['value' => ['index_text' => 'c'], '_remove_' => 0],
        ];
    }

    /**
     * @param class-string<HasMany> $class
     * @return void
     */
    private function assertPrepareWritesTheOrder(string $class): void
    {
        $field = $this->makeSortField($class);
        $field->rowUpDown('value.' . self::SORT_COLUMN);

        $prepared = $field->prepare($this->postedRows());
        $this->assertIsArray($prepared);

        $byName = $this->orderByName($prepared);
        $this->assertSame(['a', 'b', 'c'], array_keys($byName), 'every row is kept');
        $this->assertSame(1, $byName['a'], 'the first row');
        $this->assertSame(2, $byName['c'], 'the next row, the removed one is skipped');
        $this->assertNull($byName['b'], 'the removed row is left alone');
    }

    /**
     * index_text of each row of a prepared value => the number written into the
     * sort column (null when none).
     *
     * @param mixed $prepared
     * @return array<string, int|null>
     */
    private function orderByName($prepared): array
    {
        $this->assertIsArray($prepared);
        $result = [];
        foreach ($prepared as $row) {
            $this->assertIsArray($row);
            $name = array_get($row, 'value.index_text');
            $this->assertIsString($name);
            $number = array_get($row, 'value.' . self::SORT_COLUMN);
            $result[$name] = is_null($number) ? null : intval($number);
        }
        return $result;
    }

    /**
     * @param string $name
     * @param int|null $number
     * @return array<string, mixed>
     */
    private function valueRow(string $name, ?int $number): array
    {
        $value = ['index_text' => $name];
        if (!is_null($number)) {
            $value[self::SORT_COLUMN] = $number;
        }
        return ['value' => $value];
    }

    /**
     * @param HasMany $field
     * @param array<int, mixed> $rows
     * @return void
     */
    private function setValue($field, array $rows): void
    {
        $property = new \ReflectionProperty($field, 'value');
        $property->setAccessible(true);
        $property->setValue($field, $rows);
    }

    /**
     * @param HasMany $field
     * @return void
     */
    private function sortValue($field): void
    {
        $method = new \ReflectionMethod($field, 'sortValueByRowUpDown');
        $method->setAccessible(true);
        $method->invoke($field);
    }

    /**
     * @param HasMany $field
     * @return array<int, mixed> index_text of the rows of the value, in order
     */
    private function namesOf($field): array
    {
        $property = new \ReflectionProperty($field, 'value');
        $property->setAccessible(true);
        $rows = $property->getValue($field);
        $this->assertIsArray($rows);

        $names = [];
        foreach ($rows as $row) {
            $names[] = array_get($row, 'value.index_text');
        }
        return $names;
    }

    /**
     * @param HasMany $field
     * @return string
     */
    private function rowMoveScript($field): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
        $method = new \ReflectionMethod($field, 'rowMoveScript');
        $method->setAccessible(true);
        return strval($method->invoke($field, $flags));
    }

    /**
     * @param HasMany $field
     * @return string
     */
    private function scriptOf($field): string
    {
        return $field->getScript();
    }

    /**
     * @param HasMany $field
     * @return string
     */
    private function renderField($field): string
    {
        $view = $field->render();
        $html = $view instanceof View ? $view->render() : (string) $view;
        $this->assertNotSame('', $html);
        return $html;
    }

    /**
     * A child block field of the parent table, on a form of its own, with one
     * text input in it.
     *
     * @param class-string<HasMany> $class HasMany::class or HasManyTable::class
     * @return HasMany
     */
    private function makeField(string $class)
    {
        $builder = function ($form) {
            $form->text('copy_test_text', 'Text');
        };
        return $this->buildField($class, $builder);
    }

    /**
     * A child block field built like the data form does: the columns are in an
     * embedded form "value", with the sort column as a hidden input.
     *
     * @param class-string<HasMany> $class HasMany::class or HasManyTable::class
     * @return HasMany
     */
    private function makeSortField(string $class)
    {
        $builder = function ($form) {
            $form->nestedEmbeds('value', $form->getKey(), 'order-test', function ($form) {
                $form->text('index_text', 'Text');
                $form->hidden(self::SORT_COLUMN);
            })->setRelationName('order_test');
        };
        return $this->buildField($class, $builder);
    }

    /**
     * @param class-string<HasMany> $class
     * @param \Closure $builder
     * @return HasMany
     */
    private function buildField(string $class, \Closure $builder)
    {
        $parent_table = $this->parentTable();
        $child_table = $this->childTable();
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        $this->assertNotNull($relation, 'seed data has a relation from parent_table to child_table');

        $classname = getModelName($parent_table);
        $form = new Form(new $classname());

        $field = $class === HasManyTable::class
            // @phpstan-ignore-next-line
            ? $form->hasManyTable($relation->getRelationName(), 'Children', $builder)
            : $form->hasMany($relation->getRelationName(), 'Children', $builder);
        $this->assertInstanceOf($class, $field);

        return $field;
    }

    /**
     * Make the form of the parent table have a 1:n block of the child table in
     * the given style (1: table, 0: block), with the given sort column, and
     * with index_text as its only column (and the sort column too when asked).
     *
     * @param int $hasmanyType
     * @param string|null $sortColumn column_name of the sort column, null for none
     * @param bool $showSortColumn the block shows the sort column as a column
     * @return CustomFormBlock
     */
    private function prepareChildBlock(int $hasmanyType, ?string $sortColumn, bool $showSortColumn = false): CustomFormBlock
    {
        $parent_table = $this->parentTable();
        $child_table = $this->childTable();
        $form = $parent_table->getPriorityForm();

        $block = CustomFormBlock::where('custom_form_id', $form->id)
            ->where('form_block_type', FormBlockType::ONE_TO_MANY)
            ->where('form_block_target_table_id', $child_table->id)
            ->first();

        if (!isset($block)) {
            $block = CustomFormBlock::create([
                'custom_form_id' => $form->id,
                'form_block_view_name' => 'order-test-child-block',
                'form_block_type' => FormBlockType::ONE_TO_MANY,
                'form_block_target_table_id' => $child_table->id,
                'available' => 1,
            ]);
        }

        // the columns of the block: only what the test needs
        \DB::table('custom_form_columns')->where('custom_form_block_id', $block->id)->delete();
        $targets = ['index_text'];
        if ($showSortColumn) {
            $targets[] = self::SORT_COLUMN;
        }
        foreach ($targets as $i => $name) {
            $custom_column = CustomColumn::getEloquent($name, $child_table);
            $this->assertNotNull($custom_column);
            CustomFormColumn::create([
                'custom_form_block_id' => $block->id,
                'form_column_type' => FormColumnType::COLUMN,
                'form_column_target_id' => $custom_column->id,
                'row_no' => $i + 1,
                'column_no' => 1,
                'order' => $i + 1,
            ]);
        }

        $options = is_array($block->options) ? $block->options : [];
        $options['hasmany_type'] = $hasmanyType;
        $options['sort_column'] = $sortColumn;
        \DB::table('custom_form_blocks')->where('id', $block->id)
            ->update(['options' => json_encode($options), 'available' => 1]);

        System::clearRequestSession();
        System::clearCache();

        $fresh = CustomFormBlock::find($block->id);
        $this->assertInstanceOf(CustomFormBlock::class, $fresh);
        return $fresh;
    }

    /**
     * The page of a new parent record, as the admin gets it.
     *
     * @return string
     */
    private function getCreatePage(): string
    {
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $login);

        $this->clearPageScripts();
        $response = $this->actingAs($login, 'admin')->get(admin_urls('data', self::PARENT_TABLE, 'create'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertIsString($content);
        return $content;
    }

    /**
     * How many hidden inputs of the page are named like ...[value][<column>].
     *
     * @param string $html
     * @param string $column
     * @return int
     */
    private function countHiddenInputs(string $html, string $column): int
    {
        $this->assertGreaterThan(0, preg_match_all('#<input\b[^>]*>#i', $html, $m), 'the page has inputs');

        $count = 0;
        foreach ($m[0] as $tag) {
            if (preg_match('#name=["\'][^"\']*\[value\]\[' . preg_quote($column, '#') . '\]["\']#', $tag) === 1
                && preg_match('#type=["\']hidden["\']#i', $tag) === 1) {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Post a new parent record with two child rows, beta first and alpha second,
     * and check the sort column of the saved rows.
     *
     * @param int $hasmanyType
     * @return void
     */
    private function assertSavingWritesTheOrder(int $hasmanyType): void
    {
        $this->prepareChildBlock($hasmanyType, self::SORT_COLUMN, false);
        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $login);

        $parent_table = $this->parentTable();
        $child_table = $this->childTable();
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        $this->assertNotNull($relation);

        $marker = 'issue1595_parent_' . $hasmanyType;
        $response = $this->actingAs($login, 'admin')->post(admin_urls('data', self::PARENT_TABLE), [
            // the data form always sends it (DefaultForm); without it the "value" of the parent is not saved
            'laravel_admin_escape' => '',
            'value' => ['text' => $marker],
            $relation->getRelationName() => [
                'new_1' => ['value' => ['index_text' => 'beta'], '_remove_' => 0],
                'new_2' => ['value' => ['index_text' => 'alpha'], '_remove_' => 0],
            ],
        ]);
        $response->assertSessionHasNoErrors();

        System::clearRequestSession();
        $parent = $parent_table->getValueQuery()->where('value->text', $marker)->first();
        $this->assertNotNull($parent, 'the parent record was saved');

        $children = $child_table->getValueQuery()
            ->where('parent_id', $parent->id)
            ->where('parent_type', self::PARENT_TABLE)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $children, 'both child rows were saved');

        $numbers = [];
        foreach ($children as $child) {
            /** @var \Exceedone\Exment\Model\CustomValue $child */
            $numbers[strval($child->getValue('index_text'))] = intval($child->getValue(self::SORT_COLUMN));
        }
        $this->assertSame(['beta' => 1, 'alpha' => 2], $numbers, 'the rows are numbered in the order they were posted');
    }

    /**
     * Patch the form of the parent table with the given sort column in the 1:n
     * block, and tell what the block keeps.
     *
     * @param CustomFormBlock $target the 1:n block to set
     * @param string $sortColumn what the form setting posts
     * @return string|null the sort_column option of the block after saving
     */
    private function patchSortColumn(CustomFormBlock $target, string $sortColumn): ?string
    {
        $parent_table = $this->parentTable();
        $form = CustomForm::find($target->custom_form_id);
        $this->assertInstanceOf(CustomForm::class, $form);

        $blocks = [];
        foreach ($form->custom_form_blocks()->get() as $block) {
            $options = is_array($block->options) ? $block->options : [];
            if ($block->id == $target->id) {
                $options['sort_column'] = $sortColumn;
            }
            $blocks[strval($block->id)] = [
                'form_block_type' => $block->form_block_type,
                'form_block_target_table_id' => $block->form_block_target_table_id,
                'available' => 1,
                'form_block_view_name' => $block->form_block_view_name,
                'options' => $options,
            ];
        }

        $login = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $login);
        $this->actingAs($login, 'admin')->patch(admin_url("form/{$parent_table->table_name}/{$form->id}"), [
            'form_view_name' => $form->form_view_name,
            'default_flg' => $form->default_flg,
            'custom_form_blocks' => $blocks,
        ])->assertSessionHasNoErrors();

        System::clearRequestSession();
        System::clearCache();
        $saved = CustomFormBlock::find($target->id);
        $this->assertInstanceOf(CustomFormBlock::class, $saved);
        $value = $saved->getOption('sort_column');
        return is_nullorempty($value) ? null : strval($value);
    }
}
