<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\NotifyService;

/**
 * The subject and the body of the "send mail" dialog are typed by the user and filled with
 * values when they are sent. Only the ${...} words of the mail template are filled; a word the
 * user adds (${system:...}, ${notify:...}) is sent as typed, so it does not read settings out.
 */
class SendMailReplaceWordsTest extends UnitTestBase
{
    /**
     * @param array<string> $allowed
     */
    protected function keep(string $text, array $allowed): string
    {
        $method = new \ReflectionMethod(NotifyService::class, 'keepOnlyReplaceWords');
        $method->setAccessible(true);
        return $method->invoke(null, $text, $allowed);
    }

    /**
     * @return array<string>
     */
    protected function words(string $text): array
    {
        $method = new \ReflectionMethod(NotifyService::class, 'getReplaceWords');
        $method->setAccessible(true);
        return $method->invoke(null, $text);
    }

    public function testTheWordsOfTheTemplateAreKept(): void
    {
        $allowed = $this->words("Hello \${value:user_name}\n\${system:site_name}");
        $this->assertSame(['${value:user_name}', '${system:site_name}'], $allowed);

        $this->assertSame('Dear ${value:user_name} of ${system:site_name}', $this->keep('Dear ${value:user_name} of ${system:site_name}', $allowed));
    }

    public function testAWordTheUserAddsIsNotFilled(): void
    {
        $allowed = $this->words('Hello ${value:user_name}');
        $sent = $this->keep('Hello ${value:user_name} ${system:smtp_password} ${notify:action_settings.0.webhook_url}', $allowed);

        $this->assertStringContainsString('${value:user_name}', $sent);
        $this->assertStringNotContainsString('${system:smtp_password}', $sent);
        $this->assertStringNotContainsString('${notify:', $sent);
        $this->assertStringContainsString('$ {system:smtp_password}', $sent);
    }

    public function testAWordInsideAnotherIsNotFilled(): void
    {
        $allowed = $this->words('Hello ${value:user_name}');
        $sent = $this->keep('${${system:site_name}} ${$${notify:x}}', $allowed);

        $this->assertStringNotContainsString('${system:', $sent);
        $this->assertStringNotContainsString('${notify:', $sent);
        $this->assertSame(0, preg_match('/\$\{/', $sent));
    }

    public function testAnInvalidByteDoesNotLetAWordThrough(): void
    {
        $allowed = $this->words('Hello ${value:user_name}');
        $sent = $this->keep("\xff \${system:smtp_password} \${value:user_name}", $allowed);

        $this->assertStringNotContainsString('${system:smtp_password}', $sent);
        $this->assertStringContainsString('${value:user_name}', $sent);
    }
}
