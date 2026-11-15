<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * #1748: the mail settings of the system setting page only fill the "smtp" mailer
 * (mail.mailers.smtp.*). Since Laravel 11 the default mailer is "log" (config/mail.php and
 * MAIL_MAILER of .env.example), so a new installation wrote every mail to the log although the
 * SMTP host was set on the page.
 *
 * Initialize::initializeConfig() now switches the default mailer from "log" to "smtp" when the
 * SMTP host is set on the page. Nothing else changes: no host on the page, another mailer in
 * .env, or EXMENT_MAIL_SETTING_ENV_FORCE=true.
 */
class MailDefaultMailerTest extends UnitTestBase
{
    use DatabaseTransactions;

    private const HOST = 'smtp.issue1748.example.com';

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    public function testLogMailerBecomesSmtpWhenHostIsSetOnThePage(): void
    {
        $this->setMailHost(self::HOST);
        Config::set('exment.mail_setting_env_force', false);
        Config::set('mail.default', 'log');

        Initialize::initializeConfig();

        $this->assertSame('smtp', Config::get('mail.default'));
        $this->assertSame(self::HOST, Config::get('mail.mailers.smtp.host'));
    }

    public function testLogMailerIsKeptWhenNoHostIsSetOnThePage(): void
    {
        $this->setMailHost(null);
        Config::set('exment.mail_setting_env_force', false);
        Config::set('mail.default', 'log');

        Initialize::initializeConfig();

        $this->assertSame('log', Config::get('mail.default'));
    }

    public function testLogMailerIsKeptWhenEnvIsForced(): void
    {
        $this->setMailHost(self::HOST);
        Config::set('exment.mail_setting_env_force', true);
        Config::set('mail.default', 'log');
        Config::set('mail.mailers.smtp.host', 'smtp.env.example.com');

        Initialize::initializeConfig();

        $this->assertSame('log', Config::get('mail.default'));
        // .env wins as before
        $this->assertSame('smtp.env.example.com', Config::get('mail.mailers.smtp.host'));
    }

    #[DataProvider('otherMailerProvider')]
    public function testAnotherMailerIsKept(string $mailer): void
    {
        $this->setMailHost(self::HOST);
        Config::set('exment.mail_setting_env_force', false);
        Config::set('mail.default', $mailer);

        Initialize::initializeConfig();

        $this->assertSame($mailer, Config::get('mail.default'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function otherMailerProvider(): array
    {
        return [
            'smtp' => ['smtp'],
            'sendmail' => ['sendmail'],
            'ses' => ['ses'],
            'array' => ['array'],
        ];
    }

    /**
     * Set the SMTP host of the system setting page (rolled back with the transaction).
     */
    private function setMailHost(?string $host): void
    {
        // the value read without a record falls back to the old "mail.host" config
        Config::set('mail.host', null);
        Config::set('mail.driver', null);
        System::system_mail_host($host);
        System::clearCache();
    }
}
