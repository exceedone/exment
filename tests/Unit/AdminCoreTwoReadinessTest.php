<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * Exment gets ready for an admin-core that no longer ships iCheck, the eonasdan datetimepicker,
 * moment and x-editable (ISS02862). Nothing changes while they are still there.
 *
 * The scripts are javascript the test runner does not execute; this reads the sources.
 */
class AdminCoreTwoReadinessTest extends UnitTestBase
{
    protected function read(string $path): string
    {
        return (string) file_get_contents(exment_package_path($path));
    }

    /**
     * @return string[] the files exment writes its own browser script in
     */
    protected function scriptFiles(): array
    {
        $files = [];
        foreach (['public/vendor/exment/js', 'src/Web/ts', 'resources/views', 'src'] as $dir) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(exment_package_path($dir), \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                $name = $file->getFilename();
                if (preg_match('/\.min\.js$/', $name)) {
                    continue;
                }
                if (preg_match('/\.(js|ts|php)$/', $name)) {
                    $files[] = $file->getPathname();
                }
            }
        }
        return $files;
    }

    /**
     * Every `.iCheck(` call is behind a check that the plugin is loaded ($.fn.iCheck, hasICheck(),
     * or `$el.iCheck &&`), so the page does not stop with "iCheck is not a function" without it.
     */
    public function testNoICheckCallWithoutAGuard(): void
    {
        $unguarded = [];
        foreach ($this->scriptFiles() as $path) {
            $source = (string) file_get_contents($path);
            $offset = 0;
            while (($pos = strpos($source, '.iCheck(', $offset)) !== false) {
                $offset = $pos + 1;
                $lineStart = strrpos(substr($source, 0, $pos), "\n");
                $line = substr($source, $lineStart === false ? 0 : $lineStart + 1, $pos - ($lineStart === false ? 0 : $lineStart + 1));
                // a comment, not a call
                if (preg_match('/^\s*(\/\/|\*)/', $line)) {
                    continue;
                }
                $before = substr($source, max(0, $pos - 600), min($pos, 600));
                if (!preg_match('/fn\.iCheck|hasICheck\(\)|\.iCheck &&/', $before)) {
                    $unguarded[] = $path . ':' . (substr_count(substr($source, 0, $pos), "\n") + 1);
                }
            }
        }
        $this->assertSame([], $unguarded, 'an .iCheck( call without a check that iCheck is loaded');
    }

    public function testCheckboxHandlersListenToTheNativeChangeWithoutICheck(): void
    {
        foreach (['src/Web/ts/customform.ts', 'public/vendor/exment/js/customform.js'] as $path) {
            $source = $this->read($path);
            $this->assertStringContainsString("(CustomFromEvent.hasICheck() ? 'ifChanged' : 'change') + '.exment_custom_form'", $source, $path);
            $this->assertStringNotContainsString("on('ifChanged.exment_custom_form'", $source, $path);
        }
        $this->assertStringContainsString("var checkEvent = hasICheck ? 'ifChanged' : 'change';", $this->read('resources/views/backup/index.blade.php'));
        $this->assertStringContainsString("var checkEvent = (\$.fn && \$.fn.iCheck) ? 'ifChanged' : 'change';", $this->read('src/Form/Tools/CustomTableMenuAjaxButton.php'));
    }

    /**
     * The date picker gets Font Awesome icons, as admin-core's Date field passes: its default icons
     * are glyphicons, and no glyphicon font is loaded.
     */
    public function testTheDatePickerGetsFontAwesomeIcons(): void
    {
        foreach (['src/Web/ts/common.ts', 'public/vendor/exment/js/common.js'] as $path) {
            $source = $this->read($path);
            $this->assertStringContainsString('"widgetParent": $parent, "icons": CommonEvent.DATE_PICKER_ICONS })', $source, $path);
            $this->assertStringContainsString("previous: 'fa fa-chevron-left',", $source, $path);
            $this->assertStringContainsString("next: 'fa fa-chevron-right',", $source, $path);
        }
    }

    public function testTheCalendarsDoNotCallMoment(): void
    {
        foreach (['resources/views/dashboard/calendar/calendar.blade.php', 'resources/views/widgets/calendar.blade.php'] as $path) {
            $source = $this->read($path);
            // the comment names what the code replaces; no call is left
            $code = (string) preg_replace('/^\s*\/\/.*$/m', '', $source);
            $this->assertStringNotContainsString('moment(', $code, $path);
            $this->assertStringContainsString('event.end = nextDay(event.end);', $source, $path);
            $this->assertStringContainsString('function nextDay(value)', $source, $path);
        }
    }

    public function testTheRoleGroupOrderTakesTheInlineEditOfAdminCoreTwo(): void
    {
        $source = $this->read('src/Controllers/RoleGroupController.php');
        $this->assertStringContainsString("request()->get('_editable') == 1 || request()->has('_edit_inline')", $source);
    }
}
