<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\Login\OAuth\OAuthUser;

/**
 * An OAuth login finds the Exment user by the e-mail address from the provider. When the provider
 * says the address is not confirmed, it does not log in as the user who has that address.
 */
class OAuthEmailVerifiedTest extends UnitTestBase
{
    /**
     * @param array<string, mixed> $raw
     */
    protected function unverified(array $raw): bool
    {
        $provider_user = new \stdClass();
        $provider_user->user = $raw;
        $method = new \ReflectionMethod(OAuthUser::class, 'isEmailUnverified');
        $method->setAccessible(true);
        return $method->invoke(null, $provider_user);
    }

    public function testAnAddressTheProviderDidNotConfirmIsRefused(): void
    {
        $this->assertTrue($this->unverified(['email' => 'a@example.com', 'email_verified' => false]));
        $this->assertTrue($this->unverified(['email' => 'a@example.com', 'verified_email' => false]));
        $this->assertTrue($this->unverified(['email' => 'a@example.com', 'email_verified' => 'false']));
    }

    public function testAConfirmedAddressOrAProviderThatDoesNotTellIsTaken(): void
    {
        $this->assertFalse($this->unverified(['email' => 'a@example.com', 'email_verified' => true]));
        $this->assertFalse($this->unverified(['email' => 'a@example.com', 'verified_email' => 'true']));
        $this->assertFalse($this->unverified(['email' => 'a@example.com']));
        $this->assertFalse($this->unverified([]));
    }
}
