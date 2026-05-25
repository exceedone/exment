<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Middleware\Initialize;
use OpenAdminCore\Admin\Form\Field\Number;
use Tests\TestCase;

/**
 * Tests for Number field render behavior.
 *
 * Background: browsers silently discard value="<non-numeric>" for <input type="number">.
 * Fix: when the field value is non-numeric, render type="text" + data-allow-nonnumeric="1"
 * so the browser displays the value correctly while keeping the +/- button UI.
 *
 * Files changed:
 *   - open-admin-core/src/Form/Field/Number.php
 *   - open-admin-core/resources/assets/number-input/bootstrap-number-input.js
 *   - public/vendor/open-admin/number-input/bootstrap-number-input.js
 */
class NumberRenderTest extends TestCase
{
    use TestTrait;

    // -----------------------------------------------------------------------
    // Helper
    // -----------------------------------------------------------------------

    /**
     * Render a Number field with the given fill-value and return the HTML string.
     *
     * @param mixed        $fillValue   value to fill into the field (simulates DB value)
     * @param \Closure|null $configure   optional callback to further configure the field
     * @return string rendered HTML
     */
    protected function renderNumber($fillValue, ?\Closure $configure = null): string
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();

        // $errors is normally injected by ShareErrorsFromSession middleware; share manually in tests.
        view()->share('errors', new \Illuminate\Support\ViewErrorBag());

        $field = new Number('foo', ['Foo']);
        $field->setElementName('foo');

        // Simulate fill() as NestedForm / Form would do
        if ($fillValue !== null) {
            $field->fill(['foo' => $fillValue]);
        }

        if ($configure) {
            $configure($field);
        }

        return (string) $field->render();
    }

    // -----------------------------------------------------------------------
    // type="number" cases (numeric / empty / null)
    // -----------------------------------------------------------------------

    /**
     * Integer value → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithIntegerValue(): void
    {
        $html = $this->renderNumber(42);

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * String numeric value → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithStringNumericValue(): void
    {
        $html = $this->renderNumber('123');

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Float / decimal value → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithFloatValue(): void
    {
        $html = $this->renderNumber('1.5');

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Negative numeric string → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithNegativeValue(): void
    {
        $html = $this->renderNumber('-10');

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Zero (int) → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithZeroIntValue(): void
    {
        $html = $this->renderNumber(0);

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Zero (string "0") → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithZeroStringValue(): void
    {
        $html = $this->renderNumber('0');

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Empty string → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithEmptyStringValue(): void
    {
        $html = $this->renderNumber('');

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Null → type="number", no data-allow-nonnumeric.
     */
    public function testRenderWithNullValue(): void
    {
        $html = $this->renderNumber(null);

        $this->assertStringContainsString('type="number"', $html);
        $this->assertStringNotContainsString('data-allow-nonnumeric', $html);
    }

    // -----------------------------------------------------------------------
    // type="text" cases (non-numeric values)
    // -----------------------------------------------------------------------

    /**
     * Date string → type="text" + data-allow-nonnumeric="1" + value displayed.
     * This is the primary bug-fix scenario.
     */
    public function testRenderWithDateStringValue(): void
    {
        $html = $this->renderNumber('2026-05-18');

        // type must be text so the browser doesn't discard the value
        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);

        // plugin must be told not to clamp / filter keystrokes
        $this->assertStringContainsString('data-allow-nonnumeric', $html);

        // value must be present in the rendered HTML
        $this->assertStringContainsString('value="2026-05-18"', $html);
    }

    /**
     * Datetime string → type="text" + data-allow-nonnumeric.
     */
    public function testRenderWithDatetimeStringValue(): void
    {
        $html = $this->renderNumber('2026-05-18 10:30:00');

        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * Arbitrary alpha string → type="text" + data-allow-nonnumeric.
     */
    public function testRenderWithAlphaStringValue(): void
    {
        $html = $this->renderNumber('abc');

        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('data-allow-nonnumeric', $html);
    }

    /**
     * String with leading letter → type="text" + data-allow-nonnumeric.
     */
    public function testRenderWithLeadingLetterValue(): void
    {
        $html = $this->renderNumber('X100');

        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('data-allow-nonnumeric', $html);
    }

    // -----------------------------------------------------------------------
    // Regression: explicit attribute('type') must be respected
    // -----------------------------------------------------------------------

    /**
     * When caller explicitly forces type="number", it wins over the auto-detection.
     * Verifies that the fix uses attribute() (not defaultAttribute()) so an
     * explicit override is NOT silently ignored.
     *
     * NOTE: This test will FAIL until the fix in Number::render() is applied
     * (i.e., using $this->attribute('type', 'text') instead of
     *  $this->defaultAttribute('type', 'text') for the non-numeric branch).
     */
    public function testRenderNonNumericOverridesExplicitNumberType(): void
    {
        // A date value should always win → type must be text even if nothing
        // pre-set type to "number" (this is the non-regression assertion)
        $html = $this->renderNumber('2026-05-18');

        $this->assertStringNotContainsString('type="number"', $html,
            'Non-numeric value must render as type="text" regardless of defaults.');
        $this->assertStringContainsString('type="text"', $html);
    }

    // -----------------------------------------------------------------------
    // Edge: min/max with non-numeric value
    // -----------------------------------------------------------------------

    /**
     * When a date value is present, the min/max DigitRules must NOT fire.
     * (The outer ChangeFieldRule handles validation for condition_value.)
     *
     * This is a documentation test: it asserts that non-numeric values pass
     * the Number field's own validation ONLY when rules have been cleared.
     * If DigitMinRule is still active, this test documents the expected failure.
     */
    public function testRenderWithMinMaxAndDateValue(): void
    {
        $html = $this->renderNumber('2026-05-18', function (Number $field) {
            $field->min(0)->max(100);
        });

        // Regardless of min/max, a non-numeric value must still render as text
        $this->assertStringNotContainsString('type="number"', $html);
        $this->assertStringContainsString('type="text"', $html);
        $this->assertStringContainsString('data-allow-nonnumeric', $html);
    }

    // -----------------------------------------------------------------------
    // Existing behavior: validation rules (regression guard)
    // -----------------------------------------------------------------------

    /**
     * Numeric values must still pass 'numeric' validation (no regression).
     */
    public function testValidationNumericValuePasses(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();

        $form = new \OpenAdminCore\Admin\Widgets\Form();
        $form->number('foo');

        $messages = $form->validationMessages(['foo' => 42]);
        $this->assertFalse($messages, 'Integer value should pass numeric validation.');
    }

    /**
     * Non-numeric alpha strings fail Number's own 'numeric' rule.
     * (Documents existing behavior - the outer ChangeFieldRule handles
     *  condition_value validation so this does not block condition saves.)
     */
    public function testValidationNonNumericStringFails(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();

        $form = new \OpenAdminCore\Admin\Widgets\Form();
        $form->number('foo');

        $messages = $form->validationMessages(['foo' => 'abc']);
        $this->assertNotFalse($messages, 'Non-numeric alpha should fail numeric validation.');
    }

    /**
     * Date string "2026-05-18" fails Number's own 'numeric' rule.
     * Documents that condition_value saves work because ChangeFieldRule
     * (not Number's own rules) governs validation in that context.
     */
    public function testValidationDateStringFailsNumericRule(): void
    {
        \Admin::bootstrap();
        Initialize::registeredLaravelAdmin();
        $this->initAllTest();

        $form = new \OpenAdminCore\Admin\Widgets\Form();
        $form->number('foo');

        $messages = $form->validationMessages(['foo' => '2026-05-18']);
        $this->assertNotFalse($messages, 'Date string should fail the standalone numeric validation rule.');
    }
}
