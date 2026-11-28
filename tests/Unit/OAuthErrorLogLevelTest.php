<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Exceptions\Handler;
use Laravel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueOAuthServerException;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * An OAuth error the client caused (4xx) is logged as a warning without the stack trace; an error
 * of the server is logged as before.
 */
class OAuthErrorLogLevelTest extends UnitTestBase
{
    /**
     * @var OAuthErrorLogLevelTestLogger
     */
    protected $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logger = new OAuthErrorLogLevelTestLogger();
        $this->app->instance(LoggerInterface::class, $this->logger);
    }

    public function testClientErrorsAreWarnings(): void
    {
        $exceptions = [
            'invalid_scope' => LeagueOAuthServerException::invalidScope('unknown_scope'),
            'invalid_client' => LeagueOAuthServerException::invalidClient(new \Nyholm\Psr7\ServerRequest('POST', '/oauth/token')),
            'invalid_grant' => LeagueOAuthServerException::invalidGrant(),
            'invalid_request' => LeagueOAuthServerException::invalidRequest('client_id'),
        ];
        foreach ($exceptions as $errorType => $exception) {
            $this->logger->logs = [];
            (new Handler($this->app))->report($exception);

            $this->assertCount(1, $this->logger->logs, $errorType);
            [$level, $message, $context] = $this->logger->logs[0];
            $this->assertSame('warning', $level, $errorType);
            $this->assertStringStartsWith('OAuthServerException: ', $message);
            $this->assertSame($errorType, $context['error']);
            $this->assertSame($exception->getHttpStatusCode(), $context['status']);
            $this->assertArrayNotHasKey('exception', $context, $errorType);
        }
    }

    public function testClientErrorWrappedByPassportIsAWarning(): void
    {
        $league = LeagueOAuthServerException::invalidScope('unknown_scope');
        (new Handler($this->app))->report(new OAuthServerException($league, new \Illuminate\Http\Response('', 400)));

        $this->assertCount(1, $this->logger->logs);
        [$level, , $context] = $this->logger->logs[0];
        $this->assertSame('warning', $level);
        $this->assertSame('invalid_scope', $context['error']);
        $this->assertArrayNotHasKey('exception', $context);
    }

    public function testServerErrorStaysAnError(): void
    {
        $league = LeagueOAuthServerException::serverError('something went wrong');
        (new Handler($this->app))->report(new OAuthServerException($league, new \Illuminate\Http\Response('', 500)));

        $this->assertCount(1, $this->logger->logs);
        [$level, , $context] = $this->logger->logs[0];
        $this->assertSame('error', $level);
        $this->assertArrayHasKey('exception', $context);
        $this->assertNull(Handler::oauthClientError($league));
    }
}

/**
 * Keeps what is logged.
 */
class OAuthErrorLogLevelTestLogger extends AbstractLogger
{
    /** @var array<int, array{0: mixed, 1: string, 2: array<mixed>}> */
    public $logs = [];

    /**
     * @param mixed $level
     * @param array<mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->logs[] = [$level, (string) $message, $context];
    }
}
