<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Form\Field\Checkboxone;

/**
 * Exment 7.3 is built on admin-core 2.x (ISS02862 B5): no iCheck is loaded, and the date
 * pickers of Exment's own scripts are flatpickr. The old paths stay for a page (a plugin) that
 * still loads the old plugins.
 *
 * The scripts are javascript the test runner does not execute; this reads the sources.
 */
class AdminCoreTwoTest extends UnitTestBase
{
    protected function read(string $path): string
    {
        return (string) file_get_contents(exment_package_path($path));
    }

    public function testExmentNeedsAdminCoreTwo(): void
    {
        $composer = json_decode($this->read('composer.json'), true);
        $this->assertSame('^2.0', $composer['require']['exceedone/exment-admin-core'] ?? null);
    }

    public function testNoICheckIsLoaded(): void
    {
        $bootstrap = $this->read('src/Middleware/Bootstrap.php');
        $this->assertStringNotContainsString('plugins/iCheck/', $bootstrap);

        $this->assertSame([], (new \ReflectionProperty(Checkboxone::class, 'css'))->getValue());
        $this->assertSame([], (new \ReflectionProperty(Checkboxone::class, 'js'))->getValue());

        foreach (['resources/views/install/layout.blade.php', 'resources/views/auth/layout.blade.php'] as $path) {
            $this->assertStringNotContainsString('plugins/iCheck/', $this->read($path), $path);
            $this->assertStringNotContainsString('.iCheck(', $this->read($path), $path);
            // admin-core 2.1 has no bootstrap5-editable (x-editable): nothing on the login pages edits in place
            $this->assertStringNotContainsString('bootstrap5-editable', $this->read($path), $path);
        }
    }

    public function testTheDateInputsOfExmentUseFlatpickr(): void
    {
        foreach (['src/Web/ts/common.ts', 'public/vendor/exment/js/common.js'] as $path) {
            $source = $this->read($path);
            $this->assertMatchesRegularExpression('/if \(typeof (\(window as any\)|window)\.flatpickr === \'function\'\) \{\s*CommonEvent\.addFlatpickrs\(SELECTOR\);/', $source, $path);
            $this->assertStringContainsString("dateFormat: 'Y-m-d'", $source, $path);
            $this->assertStringContainsString('elem._flatpickr', $source, $path . ': once an input');
        }

        $grid = $this->read('public/vendor/exment/js/grid_tools.js');
        $this->assertStringContainsString("if (typeof window.flatpickr === 'function') return attachFlatpickr(td, editor, meta, cfg);", $grid);
        $this->assertStringContainsString("var FLATPICKR_FORMATS = { date: 'Y-m-d', datetime: 'Y-m-d H:i:S' };", $grid);
        $this->assertStringContainsString('editor._flatpickr.open();', $grid);
        $this->assertStringContainsString('instance.destroy();', $grid);

        $task = $this->read('resources/views/workflow_task/index.blade.php');
        $this->assertStringContainsString("toPicker.set('minDate', text || null)", $task);
        $this->assertStringContainsString("fromPicker.set('maxDate', text || null)", $task);
        // the picker has its own function: a "var lang" of it covered the texts of the page (lang.selected)
        $this->assertSame(1, substr_count($task, 'var lang ='), 'one lang in the script of the page');
        $this->assertStringContainsString('var pickerLang = ', $task);

        // the native check boxes of the grid take the size and place of the iCheck box
        $css = $this->read('public/vendor/exment/css/grid_tools.css');
        $css = str_replace("\r\n", "\n", $css);
        $this->assertStringContainsString("input.grid-row-checkbox,\ninput.grid-select-all {\n  width: 18px;", $css);
        $this->assertStringContainsString('.table td:has(> input.grid-row-checkbox)', $css);
    }

    public function testNoSlimScrollRulesAreLeft(): void
    {
        // admin-core 2.0 has no slimScroll: the menus of the navbar are not wrapped in its box
        foreach (['src/Web/scss/common.scss', 'public/vendor/exment/css/common.css', 'public/vendor/exment/css/common.min.css', 'public/vendor/exment/css/workflow_task_navbar.css'] as $path) {
            $this->assertStringNotContainsString('slimScroll', $this->read($path), $path);
        }
    }

    public function testTheCiUsesAdminCoreTwo(): void
    {
        foreach (['unittest', 'phpstan', 'coverage', 'check-lang'] as $name) {
            $workflow = $this->read(".github/workflows/{$name}.yml");
            $this->assertMatchesRegularExpression('/repository: exceedone\/exment-admin-core\n(\s+#[^\n]*\n)*\s+ref: 2\.x\n/', $workflow, $name);
            $this->assertStringContainsString('"dev-master as 2.0.0"', $workflow, $name);
        }
    }
}
