<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Form\Field\ViewOnly;

/**
 * #1698: the <style> block of hasmanytable.blade.php had the rule "input { min-width: 90px
 * !important; }". It is printed into the page, so it reached every input of a screen that has a
 * child table block: the after-save checkboxes in the form footer became 90px wide and were
 * toggled by a click beside them.
 *
 * The rule is limited to the inputs of the child table (.has-many-table), and never to a
 * checkbox or a radio button.
 *
 * Pure file scan: no DB, no rendering.
 */
class HasManyTableStyleScopeTest extends TestCase
{
    /**
     * The css rules of the <style> block as selector => declarations.
     *
     * @return array<string, string>
     */
    private function styleRules(): array
    {
        $ref = new \ReflectionClass(ViewOnly::class); // src/Form/Field/ViewOnly.php
        $fileName = $ref->getFileName();
        if ($fileName === false) {
            $this->fail('Cannot resolve the file of class ' . ViewOnly::class);
        }
        $file = dirname($fileName, 4) . '/resources/views/form/field/hasmanytable.blade.php';
        $this->assertFileExists($file);

        $content = file_get_contents($file);
        if ($content === false) {
            $this->fail("Cannot read blade file: {$file}");
        }
        if (preg_match('#<style[^>]*>(.*?)</style>#s', $content, $m) !== 1) {
            $this->fail('hasmanytable.blade.php has no <style> block');
        }

        // drop the comments, then split into "selector { declarations }"
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $m[1]);
        preg_match_all('#([^{}]+)\{([^{}]*)\}#', $css, $rules, PREG_SET_ORDER);
        $this->assertNotEmpty($rules, 'no css rule found in the <style> block');

        $result = [];
        foreach ($rules as $rule) {
            $result[trim($rule[1])] = trim($rule[2]);
        }

        return $result;
    }

    public function testNoRuleTargetsEveryInputOfThePage(): void
    {
        foreach (array_keys($this->styleRules()) as $selectors) {
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                // a selector made of the element only: "input", "input[type=text]", "input:focus"...
                $this->assertDoesNotMatchRegularExpression(
                    '/^input\b/',
                    $selector,
                    "The selector \"{$selector}\" of hasmanytable.blade.php styles inputs outside the child table."
                );
            }
        }
    }

    public function testInputMinWidthIsLimitedToTheChildTable(): void
    {
        $found = false;
        foreach ($this->styleRules() as $selectors => $declarations) {
            if (strpos($declarations, 'min-width') === false || strpos($selectors, 'input') === false) {
                continue;
            }
            $found = true;
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                $this->assertStringStartsWith('.has-many-table ', $selector);
                // a wide checkbox or radio button is toggled by a click beside it
                $this->assertStringContainsString(':not([type="checkbox"])', $selector);
                $this->assertStringContainsString(':not([type="radio"])', $selector);
            }
        }

        $this->assertTrue($found, 'the text inputs of the child table keep their min-width');
    }
}
