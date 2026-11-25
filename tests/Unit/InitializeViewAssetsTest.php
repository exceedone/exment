<?php

namespace Exceedone\Exment\Tests\Unit;

/**
 * The first-run (initialize) page used to load some Bootstrap 3 files and scripts that Admin::js()
 * already loads. They are removed; this reads the view so they do not come back.
 */
class InitializeViewAssetsTest extends UnitTestBase
{
    protected function initializeView(): string
    {
        return (string) file_get_contents(exment_package_path('resources/views/initialize/index.blade.php'));
    }

    public function testNoBootstrap3LeftoversAreLoaded(): void
    {
        $view = $this->initializeView();
        $this->assertStringContainsString('Admin::js()', $view);
        $this->assertStringNotContainsString('bootstrap3-editable', $view);
        $this->assertStringNotContainsString('slimScroll', $view);
        $this->assertStringNotContainsString('jquery.slimscroll', $view);
        $this->assertSame(0, substr_count($view, 'jquery.pjax.js'));
        $this->assertStringNotContainsString('open-admin/open-admin.js', $view);
    }
}
