<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Test cases: custom column form rendering (server side contract for common.js data-filter).
 * - "unique" switch has data-filter attribute with notValue=[image, file],
 *   so JS hides the switch when attachment column type is selected.
 * - Edit form renders hidden column_type input (the value data-filter reads on edit).
 * - columnTypeHtml for auto_number no longer contains "other" option.
 */
class CustomColumnUniqueFilterFormTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const TARGET_TABLE = 'information';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * Get expected data-filter attribute html on unique switch.
     * Same json as CustomColumnController, escaped as laravel-admin renders attributes.
     *
     * @return string
     */
    protected function getUniqueFilterAttribute(): string
    {
        $json = json_encode(['parent' => 1, 'key' => 'column_type', 'notValue' => ColumnType::COLUMN_TYPE_ATTACHMENT()]);
        return 'data-filter="' . e($json) . '"';
    }

    /**
     * Create and save custom column to TARGET_TABLE, and clear cache.
     *
     * @param string $column_type
     * @param string $column_name
     * @return CustomColumn
     */
    protected function createColumn($column_type, $column_name)
    {
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        $custom_column = new CustomColumn([
            'custom_table_id' => $custom_table->id,
            'column_name' => $column_name,
            'column_view_name' => $column_name,
            'column_type' => $column_type,
            'options' => [],
        ]);
        $custom_column->save();

        System::clearCache();

        return $custom_column;
    }


    // unique switch data-filter ----------------------------------------------------

    /**
     * Column create form: unique switch has data-filter with notValue image/file.
     *
     * @return void
     */
    public function testCreateFormContainsUniqueAttachmentFilter(): void
    {
        $response = $this->get(admin_urls('column', static::TARGET_TABLE, 'create'));

        $response->assertStatus(200);
        $this->assertStringContainsString($this->getUniqueFilterAttribute(), $response->getContent(), 'unique switch data-filter(notValue attachment) is not rendered.');
    }

    /**
     * Column edit form (existing image column): unique switch data-filter is rendered,
     * and hidden column_type input has value "image" so the JS filter can evaluate it.
     *
     * @return void
     */
    public function testEditFormOfImageColumnRendersFilterAndHiddenColumnType(): void
    {
        $custom_column = $this->createColumn(ColumnType::IMAGE, 'utest_flt_image');

        $response = $this->get(admin_urls('column', static::TARGET_TABLE, $custom_column->id, 'edit'));

        $response->assertStatus(200);
        $content = $response->getContent();

        $this->assertStringContainsString($this->getUniqueFilterAttribute(), $content, 'unique switch data-filter(notValue attachment) is not rendered on edit form.');

        // hidden column_type input with value "image"
        $matched = preg_match_all('/<input[^>]*name="column_type"[^>]*>/', $content, $matches);
        $this->assertTrue($matched > 0, 'column_type input is not rendered on edit form.');
        $hiddenFound = false;
        foreach ($matches[0] as $input) {
            if (strpos($input, 'type="hidden"') !== false && strpos($input, 'value="image"') !== false) {
                $hiddenFound = true;
                break;
            }
        }
        $this->assertTrue($hiddenFound, 'hidden column_type input with value "image" is not rendered on edit form.');
    }


    // auto_number options form ----------------------------------------------------

    /**
     * columnTypeHtml (dynamic options form) for auto_number:
     * "other" option is removed, valid options remain.
     *
     * @return void
     */
    public function testColumnTypeHtmlAutoNumberNotContainsOther(): void
    {
        $response = $this->get(admin_urls_query('column', static::TARGET_TABLE, 'columnTypeHtml', [
            'val' => ColumnType::AUTO_NUMBER,
            'form_type' => 'option',
            'form_uniqueName' => 'utest_form',
        ]));

        $response->assertStatus(200);
        $body = $response->json('body');

        $this->assertStringContainsString('value="format"', $body, 'auto_number_type option "format" is not contained.');
        $this->assertStringContainsString('value="random25"', $body, 'auto_number_type option "random25" is not contained.');
        $this->assertStringContainsString('value="random32"', $body, 'auto_number_type option "random32" is not contained.');
        $this->assertStringNotContainsString('value="other"', $body, 'auto_number_type option "other" is still contained.');
    }
}
