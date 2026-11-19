<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use Exceedone\Exment\Middleware\LogOperation;
use Exceedone\Exment\Model\Define;
use Tests\TestCase;

/**
 * The LINE channel access token and channel secret are password-type system settings
 * posted from admin/system?advanced=1 (group "notify"). The form re-posts the decrypted
 * current values on every save, and the "admin" route group writes request input to
 * admin_operation_log through LogOperation. Both secrets must therefore be masked like
 * system_mail_password and recaptcha_secret_key are.
 */
class LineOperationLogMaskTest extends TestCase
{
    protected function adminPath(string $uri): string
    {
        return ltrim(admin_base_path($uri), '/');
    }

    public function testLineChannelCredentialsAreMaskedOnSystemUri(): void
    {
        $masked = LogOperation::maskInputArray([
            'system_line_channel_token'  => 'real-channel-access-token',
            'system_line_channel_secret' => 'real-channel-secret',
            'system_line_oa_basic_id'    => '@basicid',
            'site_name'                  => 'Exment',
        ], $this->adminPath('system'));

        $this->assertSame('***', $masked['system_line_channel_token']);
        $this->assertSame('***', $masked['system_line_channel_secret']);
        $this->assertSame('@basicid', $masked['system_line_oa_basic_id'], 'the OA basic id is public, not a credential');
        $this->assertSame('Exment', $masked['site_name']);
    }

    public function testEveryPasswordTypeSystemSettingIsMaskedEverywhere(): void
    {
        // recaptcha_site_key is stored as a password field for the UI but is the PUBLIC
        // site key, so it is the one password-type setting that may stay unmasked.
        $public = ['recaptcha_site_key'];
        $hidden = LogOperation::getHideColumns();

        $missing = [];
        foreach (Define::SYSTEM_SETTING_NAME_VALUE as $name => $setting) {
            if (($setting['type'] ?? null) !== 'password' || in_array($name, $public, true)) {
                continue;
            }
            if (!in_array($name, $hidden, true)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'password-type system settings missing from LogOperation::getHideColumns(): ' . implode(', ', $missing));
    }
}
