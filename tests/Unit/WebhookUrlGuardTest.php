<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\WebhookUrlGuard;

/**
 * Slack / Teams webhooks are posted from the server, so the URL may not point at the server
 * itself or at the link-local range (cloud metadata). Private networks are allowed unless
 * exment.notify_webhook_deny_private is set.
 */
class WebhookUrlGuardTest extends UnitTestBase
{
    protected function tearDown(): void
    {
        config(['exment.notify_webhook_deny_private' => false]);
        parent::tearDown();
    }

    public function testAPublicAddressIsAllowed(): void
    {
        $this->assertTrue(WebhookUrlGuard::isAllowed('https://8.8.8.8/hook'));
        $this->assertTrue(WebhookUrlGuard::isAllowed('http://1.1.1.1:8080/hook'));
    }

    public function testTheServerItselfAndLinkLocalAreRefused(): void
    {
        foreach ([
            'http://127.0.0.1/hook',
            'http://127.1.2.3:8080/',
            'http://169.254.169.254/latest/meta-data/',
            'http://0.0.0.0/',
            'http://[::1]/',
            'http://[::ffff:127.0.0.1]/',
            'http://[fe80::1]/',
            'http://100.64.0.1/',
        ] as $url) {
            $this->assertFalse(WebhookUrlGuard::isAllowed($url), $url);
        }
    }

    public function testOtherSchemesAndCredentialsAreRefused(): void
    {
        $this->assertFalse(WebhookUrlGuard::isAllowed('ftp://8.8.8.8/'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('file:///etc/passwd'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('https://user:pass@8.8.8.8/'));
        $this->assertFalse(WebhookUrlGuard::isAllowed(''));
    }

    public function testPrivateNetworksDependOnTheSetting(): void
    {
        $this->assertTrue(WebhookUrlGuard::isAllowed('http://10.0.0.5/hook'));
        $this->assertTrue(WebhookUrlGuard::isAllowed('http://192.168.1.10/hook'));

        config(['exment.notify_webhook_deny_private' => true]);
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://10.0.0.5/hook'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://192.168.1.10/hook'));
        $this->assertFalse(WebhookUrlGuard::isAllowed('http://172.16.0.1/hook'));
        $this->assertTrue(WebhookUrlGuard::isAllowed('https://8.8.8.8/hook'));
    }
}
