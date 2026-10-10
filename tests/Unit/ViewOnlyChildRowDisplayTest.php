<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Form\Field\ViewOnly;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;

/**
 * View-only (表示専用) column in a saved row of a child table (has-many table block).
 *
 * The child block builds its fields once, before any row value is known, so the item holds no
 * value. For a saved row (the item has an id) CustomItem::getCustomField() then gave the field
 * the display html of "no value", and the edit form of the parent showed the raw stored value:
 * yes/no "NO" (or 0/1) and the key "2" of a select_valtext column instead of its label.
 *
 * Expected: the row shows the same text as the main form - "YES", "Loại B".
 */
class ViewOnlyChildRowDisplayTest extends TestCase
{
    use TestTrait;

    private function bootAdmin(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();
    }

    /**
     * A column that is not saved, attached to a system table that always exists.
     *
     * @param string $column_type
     * @param array<string, mixed> $options
     */
    private function makeColumn(string $column_type, array $options = []): CustomColumn
    {
        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent('information');
        $custom_column = new CustomColumn([
            'column_name' => 'vo_' . $column_type,
            'column_view_name' => 'vo_' . $column_type,
            'column_type' => $column_type,
        ]);
        $custom_column->custom_table_id = $custom_table->id;
        $custom_column->options = $options;
        $custom_column->setRelation('custom_table', $custom_table);

        return $custom_column;
    }

    /**
     * Build the view-only field of a column while the item holds no value, fill it like the form
     * does and render.
     *
     * @param CustomColumn $custom_column
     * @param mixed $value value the field is filled with (null: nothing, like a create form)
     * @param mixed $id id of the record the item is built for (null: create form)
     */
    private function renderViewOnly(CustomColumn $custom_column, $value, $id): string
    {
        $form_column = new CustomFormColumn();
        $form_column->options = ['field_showing_type' => 'view_only'];

        $field = CustomItem::getItem($custom_column)->id($id)->getAdminField($form_column);
        $this->assertInstanceOf(ViewOnly::class, $field);

        $field->fill([$custom_column->column_name => $value]);

        $rendered = $field->render();
        $this->assertInstanceOf(\Illuminate\Contracts\View\View::class, $rendered);

        return $rendered->render();
    }

    /**
     * Build the field like a saved child row (item with id, no value), give it the row value and render.
     *
     * @param CustomColumn $custom_column
     * @param mixed $value
     */
    private function renderChildRow(CustomColumn $custom_column, $value): string
    {
        return $this->renderViewOnly($custom_column, $value, 1);
    }

    /**
     * Text of the display span (the hidden input that carries the raw value is left out).
     */
    private function displayText(string $html): string
    {
        $this->assertSame(1, preg_match('#<span class="[^"]*"[^>]*>(.*?)</span>#s', $html, $m), $html);

        return trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES));
    }

    public function test_yesno_view_only_child_row_shows_yes(): void
    {
        $this->bootAdmin();
        $html = $this->renderChildRow($this->makeColumn(ColumnType::YESNO), '1');

        $this->assertSame('YES', $this->displayText($html));
        // the raw value still goes back with the form
        $this->assertStringContainsString('value="1"', $html);
    }

    public function test_select_valtext_view_only_child_row_shows_label(): void
    {
        $this->bootAdmin();
        $column = $this->makeColumn(ColumnType::SELECT_VALTEXT, ['select_item_valtext' => "1,Loại A\n2,Loại B"]);
        $html = $this->renderChildRow($column, '2');

        $this->assertSame('Loại B', $this->displayText($html));
        $this->assertStringContainsString('value="2"', $html);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function decimalProvider(): array
    {
        return [
            'trailing zero' => [[], '1.50'],
            'decimal digit' => [['decimal_digit' => 2], '1.239'],
            'percent' => [['decimal_digit' => 1, 'percent_format' => 1], '0.256'],
        ];
    }

    /**
     * @dataProvider decimalProvider
     * @param array<string, mixed> $options
     */
    public function test_decimal_view_only_child_row_matches_main_form(array $options, string $value): void
    {
        $this->bootAdmin();
        $column = $this->makeColumn(ColumnType::DECIMAL, $options);

        // main form: the item is filled from the saved record (setCustomValue runs prepare())
        $main = CustomItem::getItem($column)->setCustomValue(['id' => 1, 'value' => [$column->column_name => $value]])->html();

        $this->assertSame((string)$main, $this->displayText($this->renderChildRow($column, $value)));
    }

    public function test_view_only_child_row_escapes_value(): void
    {
        $this->bootAdmin();
        $html = $this->renderChildRow($this->makeColumn(ColumnType::TEXT), '<img src=x onerror=alert(1)>');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    public function test_view_only_child_row_without_value_matches_main_form(): void
    {
        $this->bootAdmin();
        $column = $this->makeColumn(ColumnType::YESNO);

        $main = CustomItem::getItem($column)->setCustomValue(['id' => 1, 'value' => []])->html();

        $this->assertSame((string)$main, $this->displayText($this->renderChildRow($column, null)));
    }

    /**
     * Every value of a multiple select_valtext column in a child row is shown with its label,
     * and one hidden input per value goes back with the form.
     */
    public function test_multiple_select_valtext_view_only_child_row_shows_every_label(): void
    {
        $this->bootAdmin();
        $column = $this->makeColumn(ColumnType::SELECT_VALTEXT, [
            'select_item_valtext' => "1,Loại A
2,Loại B
3,Loại C",
            'multiple_enabled' => 1,
        ]);
        $html = $this->renderChildRow($column, ['1', '3']);

        $text = $this->displayText($html);
        $this->assertStringContainsString('Loại A', $text);
        $this->assertStringContainsString('Loại C', $text);
        $this->assertStringNotContainsString('Loại B', $text);
        $this->assertStringNotContainsString('Array', $html);
        $this->assertStringContainsString('value="1"', $html);
        $this->assertStringContainsString('value="3"', $html);
        $this->assertStringNotContainsString('value="2"', $html);
    }

    /**
     * Create form (item without id, nothing filled): the column default is shown and the hidden
     * input carries it, as before the child-row fix.
     */
    public function test_view_only_create_form_shows_default_value(): void
    {
        $this->bootAdmin();
        $column = $this->makeColumn(ColumnType::YESNO, ['default' => '1']);
        $html = $this->renderViewOnly($column, null, null);

        $this->assertSame('YES', $this->displayText($html));
        $this->assertStringContainsString('value="1"', $html);
    }
}
