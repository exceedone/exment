<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\InitialsAvatar;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * A user without an avatar shows the initials of the name; one with an avatar keeps it.
 */
class InitialsAvatarUserTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testWithoutAnAvatarTheInitials(): void
    {
        /** @var LoginUser $login */
        $login = LoginUser::query()->whereNull('avatar')->whereNotNull('base_user_id')->firstOrFail();
        $expected = InitialsAvatar::url(strval($login->base_user->getLabel()));

        $this->assertSame($expected, $login->display_avatar);
        // the user record (lists, comments, selects) the same
        $this->assertSame($expected, $login->base_user->display_avatar);
    }

    public function testAnUploadedAvatarStays(): void
    {
        /** @var LoginUser $login */
        $login = LoginUser::query()->whereNotNull('base_user_id')->firstOrFail();
        $login->avatar = 'avatar/test_avatar.png';

        $this->assertStringContainsString('avatar/test_avatar.png', strval($login->display_avatar));
        $this->assertStringStartsNotWith('data:', strval($login->display_avatar));
    }
}
