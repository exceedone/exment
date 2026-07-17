<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Test cases: multi column setting form (table edit ?columnmulti).
 * - unique1/2/3 selects do NOT contain attachment(image, file) columns (ignore_attachment).
 * - other selects on the same page (ex. table_labels) still contain attachment columns,
 *   proving the exclusion is only applied to multi unique settings.
 * - Backward-compat: form still renders when a legacy MULTI_UNIQUES record references
 *   an attachment column (saved before this feature), and the legacy id is NOT auto-injected
 *   into the select options.
 */
class CustomTableMultiUniqueFormTest extends FeatureTestBase
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
     * Create and save custom column to TARGET_TABLE.
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

        return $custom_column;
    }

    /**
     * Get inner htmls of select elements whose name contains [$fieldKey] from $content.
     *
     * @param string $content
     * @param string $fieldKey
     * @return array<string>
     */
    protected function getSelectInnerHtmls($content, $fieldKey)
    {
        preg_match_all('/<select[^>]*name="[^"]*\[' . preg_quote($fieldKey, '/') . '\]"[^>]*>(.*?)<\/select>/s', $content, $matches);
        return $matches[1];
    }

    /**
     * Multi unique selects (unique1/2/3) exclude attachment columns, keep other columns.
     * Table labels select on the same page still contains attachment columns.
     *
     * @return void
     */
    public function testMultiUniqueSelectsExcludeAttachmentColumns(): void
    {
        $this->createColumn(ColumnType::TEXT, 'utest_mtu_text');
        $this->createColumn(ColumnType::IMAGE, 'utest_mtu_image');
        $this->createColumn(ColumnType::FILE, 'utest_mtu_file');
        System::clearCache();

        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        $response = $this->get(admin_urls_query('table', $custom_table->id, 'edit', ['columnmulti' => 1]));

        $response->assertStatus(200);
        $content = $response->getContent();

        foreach (['unique1', 'unique2', 'unique3'] as $fieldKey) {
            $selects = $this->getSelectInnerHtmls($content, $fieldKey);
            $this->assertNotEmpty($selects, "{$fieldKey} select is not rendered.");

            $textFound = false;
            foreach ($selects as $select) {
                $this->assertStringNotContainsString('utest_mtu_image', $select, "image column is contained in {$fieldKey} select.");
                $this->assertStringNotContainsString('utest_mtu_file', $select, "file column is contained in {$fieldKey} select.");
                if (strpos($select, 'utest_mtu_text') !== false) {
                    $textFound = true;
                }
            }
            $this->assertTrue($textFound, "text column is not contained in {$fieldKey} select.");
        }

        // sanity check: table_labels select on the same page still contains attachment columns,
        // proving the columns exist and only multi unique selects exclude them.
        $labelSelects = $this->getSelectInnerHtmls($content, 'table_label_id');
        $this->assertNotEmpty($labelSelects, 'table_label_id select is not rendered.');
        $imageFound = false;
        foreach ($labelSelects as $select) {
            if (strpos($select, 'utest_mtu_image') !== false) {
                $imageFound = true;
                break;
            }
        }
        $this->assertTrue($imageFound, 'image column is not contained in table_label_id select (test columns may not be rendered at all).');
    }

    /**
     * Backward-compat: legacy MULTI_UNIQUES record referencing an attachment column
     * (saved before this feature) does not break the form.
     * - Form still loads (HTTP 200).
     * - The legacy attachment column id is NOT auto-injected into the unique1 select options,
     *   proving ignore_attachment is applied uniformly (no exception for current value).
     *
     * @return void
     */
    public function testFormRendersWhenExistingMultiUniqueReferencesAttachmentColumn(): void
    {
        $imageColumn = $this->createColumn(ColumnType::IMAGE, 'utest_legacy_image');
        $textColumn = $this->createColumn(ColumnType::TEXT, 'utest_legacy_text');

        // Simulate legacy DB state: MULTI_UNIQUES record already references an attachment column
        // (this could exist if unique1 was set to an image column before ignore_attachment was added).
        $custom_table = CustomTable::getEloquent(static::TARGET_TABLE);
        CustomColumnMulti::create([
            'custom_table_id' => $custom_table->id,
            'multisetting_type' => MultisettingType::MULTI_UNIQUES,
            'options' => [
                'unique1_id' => $imageColumn->id,
                'unique2_id' => $textColumn->id,
                'unique3_id' => null,
            ],
        ]);
        System::clearCache();

        $response = $this->get(admin_urls_query('table', $custom_table->id, 'edit', ['columnmulti' => 1]));

        $response->assertStatus(200);
        $content = $response->getContent();

        // unique1 select must not include the legacy attachment column as an option.
        $selects = $this->getSelectInnerHtmls($content, 'unique1');
        $this->assertNotEmpty($selects, 'unique1 select is not rendered.');

        $imageIdPattern = '/<option[^>]*value="' . preg_quote((string)$imageColumn->id, '/') . '"/';
        foreach ($selects as $select) {
            $this->assertDoesNotMatchRegularExpression(
                $imageIdPattern,
                $select,
                'legacy attachment column id is auto-injected into unique1 select options.'
            );
            $this->assertStringNotContainsString('utest_legacy_image', $select, 'legacy attachment column name is contained in unique1 select.');
        }
    }
}
