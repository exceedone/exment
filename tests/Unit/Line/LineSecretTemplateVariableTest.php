<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\ReplaceFormat\ReplaceFormatService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Unit\UnitTestBase;

/**
 * Anyone who can edit a mail / notify template or a form could put ${system:system_line_channel_secret}
 * in it, read the real secret from the sent text, and forge LINE webhooks that run workflow actions
 * as other users. Password-type system settings must never be expanded.
 */
class LineSecretTemplateVariableTest extends UnitTestBase
{
    use DatabaseTransactions;

    public function testPasswordTypeSystemSettingsAreNotExpanded(): void
    {
        System::system_line_channel_secret('real-channel-secret');
        System::system_line_channel_token('real-channel-access-token');
        System::system_mail_password('real-mail-password');
        System::clearCache();

        foreach (Define::SYSTEM_SETTING_NAME_VALUE as $key => $setting) {
            if (array_get($setting, 'type') !== 'password') {
                continue;
            }
            $this->assertSame('', ReplaceFormatService::replaceTextFromFormat('${system:' . $key . '}'), $key);
        }
        $this->assertSame('secret=', ReplaceFormatService::replaceTextFromFormat('secret=${system:system_line_channel_secret}'));
    }

    public function testOtherSystemSettingsAreStillExpanded(): void
    {
        $this->assertSame(System::site_name(), ReplaceFormatService::replaceTextFromFormat('${system:site_name}'));
        $this->assertSame((string) System::system_line_oa_basic_id(), ReplaceFormatService::replaceTextFromFormat('${system:system_line_oa_basic_id}'));
    }
}
