<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Login\PasswordPolicy;

/**
 * The password policy (ISS00362): the recommended policy for a new site, and a longer maximum length.
 */
class PasswordPolicyTest extends UnitTestBase
{
    /** @var mixed */
    private $before;

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = config('exment.password_policy_new_site');
        \DB::beginTransaction();
        System::whereIn('system_name', array_keys(PasswordPolicy::RECOMMENDED))->delete();
        System::where('system_name', 'initialized')->delete();
        System::clearCache();
        System::clearRequestSession();
    }

    protected function tearDown(): void
    {
        \DB::rollBack();
        config(['exment.password_policy_new_site' => $this->before]);
        System::clearCache();
        System::clearRequestSession();
        parent::tearDown();
    }

    private function fresh(): void
    {
        System::clearCache();
        System::clearRequestSession();
    }

    public function testANewSiteGetsTheRecommendedPolicy(): void
    {
        config(['exment.password_policy_new_site' => true]);
        $this->assertTrue(PasswordPolicy::applyNewSite());
        $this->fresh();

        $this->assertTrue(boolval(System::complex_password()));
        $this->assertTrue(boolval(System::first_change_password()));
        $this->assertSame(5, intval(System::password_history_cnt()));
    }

    public function testNothingChangesWhenItIsNotAskedFor(): void
    {
        config(['exment.password_policy_new_site' => false]);
        $this->assertFalse(PasswordPolicy::applyNewSite());
        $this->fresh();

        $this->assertFalse(boolval(System::complex_password()));
        $this->assertFalse(boolval(System::first_change_password()));
        $this->assertSame(0, intval(System::password_history_cnt()));
    }

    public function testASiteThatIsThereAlreadyIsNotChanged(): void
    {
        config(['exment.password_policy_new_site' => true]);
        System::initialized(1);
        $this->fresh();

        $this->assertFalse(PasswordPolicy::applyNewSite());
        $this->fresh();
        $this->assertFalse(boolval(System::complex_password()));
    }

    public function testASavedSettingIsKept(): void
    {
        config(['exment.password_policy_new_site' => true]);
        System::password_history_cnt(2);
        $this->fresh();

        PasswordPolicy::applyNewSite();
        $this->fresh();
        $this->assertSame(2, intval(System::password_history_cnt()));
        $this->assertTrue(boolval(System::complex_password()));
    }

    public function testTheMaximumLengthIs64(): void
    {
        $this->assertContains('max:64', get_password_rule(true, null));
    }
}
