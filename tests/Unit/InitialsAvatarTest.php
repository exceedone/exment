<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\InitialsAvatar;

/**
 * The avatar of a user without an image: ExmentPM's initials and colors, as an inline SVG.
 */
class InitialsAvatarTest extends UnitTestBase
{
    public function testInitials(): void
    {
        $this->assertSame('SN', InitialsAvatar::initials('Seiji Noro (EX1)'));
        $this->assertSame('BH', InitialsAvatar::initials('Bui Thu Huong (TTM)'));
        $this->assertSame('SY', InitialsAvatar::initials('sysadmin'));
        $this->assertSame('野呂', InitialsAvatar::initials('野呂 誠司'));
        $this->assertSame('野呂', InitialsAvatar::initials('野呂誠司'));
        $this->assertSame('野呂', InitialsAvatar::initials('野呂　誠司'));
        $this->assertSame('?', InitialsAvatar::initials(''));
        $this->assertSame('?', InitialsAvatar::initials('  '));
    }

    public function testTheColorIsExmentPmsHashOfTheName(): void
    {
        foreach (['admin', 'Seiji Noro (EX1)', '野呂 誠司', ''] as $name) {
            $this->assertSame(InitialsAvatar::PALETTE[abs(crc32($name)) % 10], InitialsAvatar::color($name));
        }
        $this->assertSame(InitialsAvatar::color('admin'), InitialsAvatar::color('admin'));
    }

    public function testAnInlineSvgSafeInAnAttribute(): void
    {
        $url = InitialsAvatar::url('<b>"O\'Neil"</b>');
        $this->assertStringStartsWith('data:image/svg+xml;charset=utf-8,', $url);
        foreach (['"', "'", '<', '>', ' '] as $char) {
            $this->assertStringNotContainsString($char, $url);
        }
        $svg = rawurldecode(substr($url, strlen('data:image/svg+xml;charset=utf-8,')));
        $this->assertStringContainsString('<circle', $svg);
        $this->assertStringContainsString('fill="' . InitialsAvatar::color('<b>"O\'Neil"</b>') . '"', $svg);
        $this->assertStringNotContainsString('<b>', $svg);

        $this->assertStringContainsString('>野呂</text>', InitialsAvatar::svg('野呂 誠司'));
    }
}
