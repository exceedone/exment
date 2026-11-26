<?php

namespace Exceedone\Exment\Services\MailOAuth;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\Transport\Smtp\Auth\AuthenticatorInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * SMTP AUTH XOAUTH2 with an access token asked for when the connection is opened
 * (MailOAuth::accessToken), not a password set once: a mailer kept by a queue worker outlives
 * its token.
 */
class XOAuth2TokenAuthenticator implements AuthenticatorInterface
{
    public function getAuthKeyword(): string
    {
        return 'XOAUTH2';
    }

    /**
     * @see https://developers.google.com/gmail/imap/xoauth2-protocol
     * @see https://learn.microsoft.com/exchange/client-developer/legacy-protocols/how-to-authenticate-an-imap-pop-smtp-application-by-using-oauth
     */
    public function authenticate(EsmtpTransport $client): void
    {
        try {
            $client->executeCommand('AUTH XOAUTH2 ' . static::initialResponse($client->getUsername(), MailOAuth::accessToken()) . "\r\n", [235]);
        } catch (TransportExceptionInterface $ex) {
            // refused: the next connection asks for a new token
            MailOAuth::forgetToken();
            throw $ex;
        }
    }

    public static function initialResponse(string $username, string $token): string
    {
        return base64_encode('user=' . $username . "\1auth=Bearer " . $token . "\1\1");
    }
}
