<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Ai;

use Tests\TestCase;
use ReflectionMethod;
use Exceedone\Exment\Services\AiChatService;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ConnectException;

/**
 * Unit tests for the pure logic of AiChatService (no network / no DB):
 *  - mapApiError()        : upstream error -> [code, message, http status]
 *
 * (The chat-only helpers normaliseChartArgs / parseChartSuggestion were removed together with
 *  the chat dialog on 2026-08-10, the column-name PII word list on 2026-08-19; their tests went
 *  with them.)
 */
class AiChatServiceTest extends TestCase
{
    private AiChatService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiChatService();
    }

    /** Invoke a private method via reflection. */
    private function invokePrivate(string $method, array $args)
    {
        $m = new ReflectionMethod($this->service, $method);
        $m->setAccessible(true);
        return $m->invoke($this->service, ...$args);
    }

    private function httpError(int $status): RequestException
    {
        return new RequestException('err', new Request('POST', 'x'), new Response($status));
    }

    // ---- mapApiError -------------------------------------------------------

    public function testMapApiErrorRateLimit(): void
    {
        [$code, $msg, $status] = $this->invokePrivate('mapApiError', [$this->httpError(429)]);
        $this->assertSame('rate_limit', $code);
        $this->assertSame(429, $status);
        $this->assertNotEmpty($msg);
    }

    public function testMapApiErrorAuth(): void
    {
        foreach ([401, 403] as $up) {
            [$code, , $status] = $this->invokePrivate('mapApiError', [$this->httpError($up)]);
            $this->assertSame('auth', $code, "upstream $up");
            $this->assertSame(502, $status);
        }
    }

    public function testMapApiErrorBadRequest(): void
    {
        [$code, , $status] = $this->invokePrivate('mapApiError', [$this->httpError(400)]);
        $this->assertSame('bad_request', $code);
        $this->assertSame(502, $status);
    }

    public function testMapApiErrorUpstream5xx(): void
    {
        [$code, , $status] = $this->invokePrivate('mapApiError', [$this->httpError(503)]);
        $this->assertSame('upstream', $code);
        $this->assertSame(502, $status);
    }

    public function testMapApiErrorTimeout(): void
    {
        $ex = new ConnectException('timeout', new Request('POST', 'x'));
        [$code, , $status] = $this->invokePrivate('mapApiError', [$ex]);
        $this->assertSame('timeout', $code);
        $this->assertSame(504, $status);
    }
}
