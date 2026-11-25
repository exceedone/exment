<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Form\Field\HasMany;
use Exceedone\Exment\Form\Field\HasManyTable;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
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
 * #1595: a row of a child table can be copied while the form is being filled in.
 *
 * The tests look at the field (the button in the rows and in the template, the
 * script that binds it), at the data form that turns it on for both styles of
 * the 1:n block, at the script and the language keys that go with it, and at the
 * rules of the javascript. Everything a test writes is rolled back.
 */
class ChildRowCopyTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const PARENT_TABLE = TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE;

    private const CHILD_TABLE = TestDefine::TESTDATA_TABLE_NAME_CHILD_TABLE;

    private const JS_PATH = 'vendor/exment/js/hasmany-copy.js';

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
     * is kept from one test to the next, so a page drawn by a later test would
     * carry the scripts of the fields of the tests before it.
     *
     * @return void
     */
    private function clearPageScripts(): void
    {
        Admin::$script = [];
        Admin::$deferredScript = [];
    }

    // ----------------------------------------------------------- the field

    /**
     * A table-style child block with rowCopy() draws the button in the template
     * row and binds the click to the script of the copy.
     */
    public function testHasManyTableWithRowCopyDrawsButtonAndBindsClick(): void
    {
        $field = $this->makeField(HasManyTable::class);
        $field->rowCopy();

        $html = $this->renderField($field);
        $template = $this->templateOf($html);
        $this->assertStringContainsString('row-copy', $template, 'the template row has the copy button');
        $this->assertStringContainsString(exmtrans('common.row_copy'), $template, 'the button has its title');

        $script = $this->scriptOf($field);
        $this->assertStringContainsString('click.admin_row_copy', $script);
        $this->assertStringContainsString('ExmentHasManyCopy.copyRow', $script);
        $this->assertStringContainsString('.row-copy', $script);
    }

    /**
     * Without rowCopy() a table-style child block has no copy button.
     */
    public function testHasManyTableWithoutRowCopyHasNoButton(): void
    {
        $field = $this->makeField(HasManyTable::class);

        $html = $this->renderField($field);
        $this->assertStringNotContainsString('row-copy', $html);
        $this->assertStringContainsString('<template', $html, 'precondition: the template row is drawn');
    }

    /**
     * A table-style child block whose rows cannot be added has no copy button in
     * its rows: a copy is a new row.
     */
    public function testHasManyTableWithoutAllowCreateHasNoButtonInRows(): void
    {
        $field = $this->makeField(HasManyTable::class);
        $field->rowCopy();
        $field->disableCreate();

        $html = $this->renderField($field);
        $withoutTemplate = (string) preg_replace('#<template\b.*?</template>#s', '', $html);
        $this->assertStringNotContainsString('row-copy', $withoutTemplate);
    }

    // ----------------------------------------------------------- the block style

    /**
     * The block style adds the button by script: nothing when it is off.
     */
    public function testHasManyRowCopyScriptIsEmptyWhenOff(): void
    {
        $field = $this->makeField(HasMany::class);

        $this->assertSame('', $this->rowCopyScript($field));
    }

    /**
     * The block style adds the button by script and binds the click to the copy.
     */
    public function testHasManyRowCopyScriptHasButtonAndClickWhenOn(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowCopy();

        $script = $this->rowCopyScript($field);
        $this->assertStringContainsString('.row-copy', $script);
        $this->assertStringContainsString('click.admin_row_copy', $script);
        $this->assertStringContainsString('ExmentHasManyCopy.copyRow', $script);
        $this->assertStringContainsString('rowcopy_', $script);
        $this->assertStringContainsString(strval(json_encode(exmtrans('common.row_copy'), JSON_UNESCAPED_UNICODE)), $script);

        // and the script of the field carries it
        $this->assertStringContainsString('ExmentHasManyCopy.copyRow', $this->scriptOf($field));
    }

    /**
     * No button when rows cannot be added, even if rowCopy() is on.
     */
    public function testHasManyRowCopyScriptIsEmptyWithoutAllowCreate(): void
    {
        $field = $this->makeField(HasMany::class);
        $field->rowCopy();
        $field->disableCreate();

        $this->assertSame('', $this->rowCopyScript($field));
    }

    // ----------------------------------------------------------- the data form

    /**
     * The data form turns the copy on for the table style of a 1:n block.
     */
    public function testDataFormTableStyleHasCopy(): void
    {
        $this->prepareChildBlock(1);

        $content = $this->getCreatePage();
        $this->assertStringContainsString('row-copy', $content);
        $this->assertStringContainsString('click.admin_row_copy', $content);
        $this->assertStringContainsString('ExmentHasManyCopy.copyRow', $content);
        $this->assertStringContainsString('has-many-table-', $content, 'precondition: the table style is drawn');
    }

    /**
     * The data form turns the copy on for the block style of a 1:n block.
     */
    public function testDataFormBlockStyleHasCopy(): void
    {
        $this->prepareChildBlock(0);

        $content = $this->getCreatePage();
        $this->assertStringContainsString('rowcopy_', $content);
        $this->assertStringContainsString('click.admin_row_copy', $content);
        $this->assertStringContainsString('ExmentHasManyCopy.copyRow', $content);
        $this->assertStringNotContainsString('has-many-table-', $content, 'precondition: the block style is drawn');
    }

    /**
     * A child table of a settings screen (no rowCopy() call) has no copy button:
     * the table style of the data form only asks for it by name.
     */
    public function testSourceTurnsCopyOnOnlyInTheDataForm(): void
    {
        $default = $this->source('src/DataItems/Form/DefaultForm.php');
        $this->assertSame(2, substr_count($default, '->rowCopy()'), 'both styles of the 1:n block');

        foreach (['CustomCopyController', 'CustomTableController', 'CustomOperationController', 'WorkflowController', 'CustomViewController'] as $name) {
            $this->assertStringNotContainsString('rowCopy(', $this->source("src/Controllers/{$name}.php"), $name);
        }
    }

    // ----------------------------------------------------------- script and lang

    /**
     * The bootstrap loads the script and the file is there.
     */
    public function testBootstrapLoadsTheScript(): void
    {
        $this->assertStringContainsString("'" . self::JS_PATH . "'", $this->source('src/Middleware/Bootstrap.php'));
        $this->assertFileExists($this->root() . '/public/' . self::JS_PATH);
    }

    /**
     * Every language has the title of the button.
     */
    public function testEveryLanguageHasTheRowCopyKey(): void
    {
        $files = glob($this->root() . '/resources/lang/*/exment.php');
        $this->assertIsArray($files);
        $this->assertGreaterThanOrEqual(7, count($files), 'de, en, es, ja, ko, vi and zh_CN');

        foreach ($files as $file) {
            $arr = include $file;
            $this->assertIsArray($arr, $file);
            $value = array_get($arr, 'common.row_copy');
            $this->assertIsString($value, $file);
            $this->assertNotSame('', trim($value), $file);
        }
    }

    /**
     * The script copies a row as a new record: it skips the id, the delete flag
     * and files.
     */
    public function testScriptKeepsWhatIsNotCopied(): void
    {
        $js = file_get_contents($this->root() . '/public/' . self::JS_PATH);
        $this->assertIsString($js);

        $this->assertStringContainsString("'[id]'", $js);
        $this->assertStringContainsString('[_remove_]', $js);
        $this->assertStringContainsString("'file'", $js);
        $this->assertStringContainsString('copyRow', $js);
        $this->assertStringContainsString('ExmentHasManyCopy', $js);
    }

    // ----------------------------------------------------------- helpers

    /**
     * @return string the root of the package
     */
    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @param string $path relative to the package root
     * @return string
     */
    private function source(string $path): string
    {
        $content = file_get_contents($this->root() . '/' . $path);
        $this->assertIsString($content, $path);
        return $content;
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
        $parent_table = CustomTable::getEloquent(self::PARENT_TABLE);
        $child_table = CustomTable::getEloquent(self::CHILD_TABLE);
        $this->assertNotNull($parent_table);
        $this->assertNotNull($child_table);
        $relation = CustomRelation::getRelationByParentChild($parent_table, $child_table);
        $this->assertNotNull($relation, 'seed data has a relation from parent_table to child_table');

        $classname = getModelName($parent_table);
        $form = new Form(new $classname());
        $builder = function ($form) {
            $form->text('copy_test_text', 'Text');
        };

        $field = $class === HasManyTable::class
            // @phpstan-ignore-next-line
            ? $form->hasManyTable($relation->getRelationName(), 'Children', $builder)
            : $form->hasMany($relation->getRelationName(), 'Children', $builder);
        $this->assertInstanceOf($class, $field);

        return $field;
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
     * The html of the template row.
     *
     * @param string $html
     * @return string
     */
    private function templateOf(string $html): string
    {
        $this->assertSame(1, preg_match('#<template\b.*?</template>#s', $html, $m), 'the template row is drawn');
        return strval($m[0] ?? '');
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
    private function rowCopyScript($field): string
    {
        $flags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE;
        $method = new \ReflectionMethod($field, 'rowCopyScript');
        $method->setAccessible(true);
        return strval($method->invoke($field, $flags));
    }

    /**
     * Make the form of the parent table have a 1:n block of the child table in
     * the given style (1: table, 0: block).
     *
     * @param int $hasmanyType
     * @return void
     */
    private function prepareChildBlock(int $hasmanyType): void
    {
        $parent_table = CustomTable::getEloquent(self::PARENT_TABLE);
        $child_table = CustomTable::getEloquent(self::CHILD_TABLE);
        $this->assertNotNull($parent_table);
        $this->assertNotNull($child_table);
        $form = $parent_table->getPriorityForm();

        $block = CustomFormBlock::where('custom_form_id', $form->id)
            ->where('form_block_type', FormBlockType::ONE_TO_MANY)
            ->where('form_block_target_table_id', $child_table->id)
            ->first();

        if (!isset($block)) {
            $block = CustomFormBlock::create([
                'custom_form_id' => $form->id,
                'form_block_view_name' => 'copy-test-child-block',
                'form_block_type' => FormBlockType::ONE_TO_MANY,
                'form_block_target_table_id' => $child_table->id,
                'available' => 1,
            ]);
            CustomFormColumn::create([
                'custom_form_block_id' => $block->id,
                'form_column_type' => FormColumnType::COLUMN,
                'form_column_target_id' => CustomColumn::getEloquent('index_text', $child_table)->id,
                'row_no' => 1,
                'column_no' => 1,
                'order' => 1,
            ]);
        }

        $options = is_array($block->options) ? $block->options : [];
        $options['hasmany_type'] = $hasmanyType;
        \DB::table('custom_form_blocks')->where('id', $block->id)
            ->update(['options' => json_encode($options), 'available' => 1]);

        System::clearRequestSession();
        System::clearCache();
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

        $response = $this->actingAs($login, 'admin')->get(admin_urls('data', self::PARENT_TABLE, 'create'));
        $response->assertOk();

        $content = $response->getContent();
        $this->assertIsString($content);
        return $content;
    }
}
