<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Form\Field\ViewOnly;
use Exceedone\Exment\Form\Field\InitOnly;

/**
 * Guard test for bug class (1): array values reaching Blade {{ }} (-> e() -> htmlspecialchars()).
 *
 * Multi-value custom columns (select / select_table / user / organization / file / image with
 * options.multiple_enabled = 1) hold PHP arrays. PR #1675 added a hidden input
 *   <input type="hidden" name="{{$name}}" value="{{$value}}" />
 * to form/field/display.blade.php (used by ViewOnly) and a similar pattern exists in
 * form/field/init_only.blade.php (used by InitOnly). On PHP 8, htmlspecialchars(array)
 * throws a TypeError, so opening the edit form of any record whose view-only / init-only
 * column holds multiple values crashes.
 *
 * These tests render those fields with array values and assert that:
 *   - rendering does NOT throw, and
 *   - the value is not silently corrupted to the literal string "Array".
 */
class DisplayFieldArrayValueTest extends TestCase
{
    use TestTrait;

    /**
     * @return void
     */
    private function bootAdmin(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();
    }

    /**
     * Render a laravel-admin field to an HTML string.
     *
     * @param mixed $field
     * @return string
     */
    private function renderToString($field): string
    {
        $rendered = $field->render();
        return is_string($rendered) ? $rendered : (string) $rendered;
    }

    /**
     * @param string $html
     * @param array<string> $expectedFragments
     * @return void
     */
    private function assertNoArrayCorruption(string $html, array $expectedFragments = []): void
    {
        $this->assertStringNotContainsString('value="Array"', $html, 'Array value was corrupted to the literal string "Array".');
        $this->assertStringNotContainsString('>Array<', $html, 'Array value was corrupted to the literal string "Array".');
        foreach ($expectedFragments as $fragment) {
            $this->assertStringContainsString($fragment, $html, "Expected rendered output to contain: {$fragment}");
        }
    }

    /**
     * ViewOnly field (resources/views/form/field/display.blade.php) - the reported bug path.
     *
     * @return void
     */
    public function testViewOnlyFieldRendersArrayValueWithoutTypeError()
    {
        $this->bootAdmin();

        $field = new ViewOnly('value.multi_column', ['Multi column']);
        // Mirror CustomItem::getCustomField() for a view-only column.
        $field->displayText('option1, option2')->escape(false);
        // fill() is the real form flow (typed array<mixed>; value()'s docblock varies per laravel-admin version)
        $field->fill(['value' => ['multi_column' => ['option1', 'option2']]]); // multi-value column -> ARRAY

        $html = $this->renderToString($field);

        $this->assertNoArrayCorruption($html, ['option1', 'option2']);
        // multi-value must round-trip as array inputs (name="...[]"), not a single "Array" string
        $this->assertStringContainsString('value[multi_column][]', $html);
    }

    /**
     * ViewOnly field with no displayText set: exercises the {{ $value }} branch directly.
     *
     * @return void
     */
    public function testViewOnlyFieldRendersArrayValueWithoutDisplayText()
    {
        $this->bootAdmin();

        $field = new ViewOnly('value.multi_column', ['Multi column']);
        $field->fill(['value' => ['multi_column' => ['option1', 'option2']]]); // ARRAY, no displayText -> hits {{ $value }}

        $html = $this->renderToString($field);

        $this->assertNoArrayCorruption($html);
    }

    /**
     * InitOnly field (resources/views/form/field/init_only.blade.php) - sibling of the bug.
     * prepareDefault() makes the blade emit the hidden input value="{{$default}}".
     *
     * @return void
     */
    public function testInitOnlyFieldRendersArrayDefaultWithoutTypeError()
    {
        $this->bootAdmin();

        $field = new InitOnly('value.multi_column', ['Multi column']);
        $field->default(['option1', 'option2'])->prepareDefault(); // ARRAY default
        $field->fill(['value' => ['multi_column' => ['option1', 'option2']]]);

        $html = $this->renderToString($field);

        $this->assertNoArrayCorruption($html, ['option1', 'option2']);
    }
}
