<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;

/**
 * What every page asks several times is worked out once in the request, and worked out again
 * when what it comes from changes (ISS00413).
 */
class RequestMemoTest extends UnitTestBase
{
    /**
     * @return void
     */
    protected function init()
    {
        $this->initAllTest();
    }

    /**
     * A setting is found by the index of system_name, as by the scan before.
     *
     * @return void
     */
    public function testASettingIsFoundByItsName()
    {
        System::clearRequestSession();
        $name = System::site_name();
        $row = System::where('system_name', 'site_name')->first();
        if (isset($row)) {
            $this->assertSame($row->system_value, $name);
        }

        $before = System::site_name_short();
        try {
            System::site_name_short('memo test');
            System::clearRequestSession();
            $this->assertSame('memo test', System::site_name_short());
        } finally {
            System::site_name_short($before ?? '');
            System::clearRequestSession();
        }
    }

    /**
     * The decoded settings of Feature management are kept while the stored value is the same,
     * and decoded again when it is set.
     *
     * @return void
     */
    public function testFeatureSettingsAreDecodedAgainWhenSet()
    {
        $before = System::features();
        try {
            System::features(['studio' => ['enabled' => true, 'role_groups' => null, 'menu' => true]]);
            $this->assertTrue(array_get(Features::settings(), 'studio.enabled'));
            $this->assertSame(Features::settings(), Features::settings());

            System::features(['studio' => ['enabled' => false, 'role_groups' => null, 'menu' => true]]);
            $this->assertFalse(array_get(Features::settings(), 'studio.enabled'), 'set again: decoded again');
        } finally {
            System::features($before);
            Features::reset();
        }
    }

    /**
     * The permission objects are made once for the roles of the request, and again when the
     * roles are read again.
     *
     * @return void
     */
    public function testPermissionObjectsFollowTheRoles()
    {
        System::clearRequestSession();
        $user = LoginUser::find(1);
        $this->assertInstanceOf(LoginUser::class, $user);

        $first = $user->allPermissions();
        $second = $user->allPermissions();
        $this->assertGreaterThan(0, $first->count());
        $this->assertSame($first->all(), $second->all(), 'the same objects in the request');

        System::setRequestSession(Define::SYSTEM_KEY_SESSION_AUTHORITY, [RoleType::SYSTEM => [], RoleType::TABLE => [], RoleType::PLUGIN => []]);
        $this->assertCount(1, $user->allPermissions(), 'other roles: made again');
        System::clearRequestSession();
    }

    /**
     * The roles of a user, read once for the three kinds, are the same as before.
     *
     * @return void
     */
    public function testTheRolesAreTheSame()
    {
        System::clearRequestSession();
        $user = LoginUser::find(2) ?? LoginUser::find(1);
        $method = new \ReflectionMethod($user, 'getPermissions');
        $method->setAccessible(true);
        $all = $method->invoke($user);

        $each = [];
        foreach ([RoleType::SYSTEM => 'getSystemPermissions', RoleType::TABLE => 'getCustomTablePermissions', RoleType::PLUGIN => 'getPluginPermissions'] as $key => $name) {
            $m = new \ReflectionMethod($user, $name);
            $m->setAccessible(true);
            $each[$key] = $m->invoke($user);
        }
        $this->assertEquals($each, $all);
        System::clearRequestSession();
    }
}
