<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Auth\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\CoreExtensions;
use Exceedone\Exment\Services\ScreenExtensions;
use Exceedone\Exment\Services\TablePolicy;

/**
 * The places where an application built on Exment adds its own settings, menus, pages and
 * rules without changing the files of Exment.
 */
class CoreExtensionsTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        CoreExtensions::reset();
        TablePolicy::reset();
        ScreenExtensions::reset();
        Permission::resetEndpoints();
        \Exceedone\Exment\Exment::resolveVersionUsing(null);
        \Exceedone\Exment\Exment::versionUpdateUrlUsing(null);
        parent::tearDown();
    }

    public function testNothingAddedIsDefineAsItIs(): void
    {
        $this->assertSame(Define::SYSTEM_SETTING_NAME_VALUE, CoreExtensions::systemSettings());
        $this->assertSame(Define::MENU_SYSTEM_DEFINITION, CoreExtensions::systemMenus());
        $this->assertSame(Define::GRID_CHANGE_PAGE_MENULIST, CoreExtensions::tablePages());
        $this->assertSame(Define::CUSTOM_TABLE_ENDPOINTS, CoreExtensions::customTableEndpoints());
        $this->assertSame(Define::HELP_URLS, CoreExtensions::helpUrls());
    }

    public function testASystemSettingIsAdded(): void
    {
        CoreExtensions::addSystemSettings(['sample_extension_flg' => ['type' => 'boolean', 'default' => true, 'group' => 'advanced']]);
        $this->assertTrue(System::hasFunction('sample_extension_flg'));
        $this->assertContains('sample_extension_flg', System::get_system_keys('advanced'));
    }

    public function testAMenuAndATablePageAreAdded(): void
    {
        CoreExtensions::addSystemMenus(['sample_page' => ['uri' => 'sample_page', 'icon' => 'fa-star']]);
        $this->assertSame('fa-star', array_get(CoreExtensions::systemMenus(), 'sample_page.icon'));

        CoreExtensions::addTablePage(['name' => 'sample-setting', 'href' => 'sample-setting/:table_name', 'icon' => 'fa-star', 'roles' => []], 'column');
        $names = collect(CoreExtensions::tablePages())->pluck('name')->toArray();
        $this->assertSame(intval(array_search('column', $names, true)) + 1, array_search('sample-setting', $names, true), 'after the columns');
        $this->assertContains('sample-setting', CoreExtensions::customTableEndpoints());
    }

    public function testAnEndpointUsesTheRuleOfAnother(): void
    {
        Permission::extendEndpoint('sample-setting', 'column');
        Permission::extendEndpoint('sample-closed', function () {
            return false;
        });
        $permission = (new \ReflectionClass(Permission::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(Permission::class, 'hasPermissionByEndpoint');
        $method->setAccessible(true);
        $this->assertFalse($method->invoke($permission, 'sample-closed', false));
    }

    public function testATablePolicyDecides(): void
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        TablePolicy::register(SystemTableName::USER, new class () extends TablePolicy {
            public function hasPermissionEditData(CustomTable $table, $id): ?bool
            {
                return false;
            }
        });
        $this->assertFalse($table->hasPermissionEditData(1));
        $this->assertNull(TablePolicy::of('no_such_table'));
    }

    public function testTheVersionAndItsPageCanComeFromTheApplication(): void
    {
        \Exceedone\Exment\Exment::resolveVersionUsing(function () {
            return ['9.9.9', '9.9.8'];
        });
        \Exceedone\Exment\Exment::versionUpdateUrlUsing(function () {
            return admin_url('sample-update');
        });
        $this->assertSame(['9.9.9', '9.9.8'], \Exment::getExmentVersion());
        $this->assertSame(admin_url('sample-update'), \Exment::versionUpdateUrl());
    }

    public function testAScreenPlaceCallsWhatWasAdded(): void
    {
        $this->assertSame([], ScreenExtensions::call('data_grid.tools', null));
        $this->assertTrue(ScreenExtensions::allows('data_form.column', null, null));
        $this->assertSame(['a'], ScreenExtensions::filter('import.items', ['a'], null));

        ScreenExtensions::add('data_form.column', function () {
            return false;
        });
        ScreenExtensions::add('import.items', function (array $items) {
            $items[] = 'b';
            return $items;
        });
        $this->assertFalse(ScreenExtensions::allows('data_form.column', null, null));
        $this->assertSame(['a', 'b'], ScreenExtensions::filter('import.items', ['a'], null));
    }

    public function testTheFieldsKeepTheOptionsOfTheColumn(): void
    {
        foreach ([\Exceedone\Exment\Form\Field\Text::class, \Exceedone\Exment\Form\Field\Number::class, \Exceedone\Exment\Form\Field\Tinymce::class] as $class) {
            $field = new $class('sample');
            $this->assertSame(['ocr' => 'x'], $field->setCustomOptions(['ocr' => 'x'])->getCustomOptions(), $class);
        }
    }
}
