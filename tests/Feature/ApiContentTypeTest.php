<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The Content-Type of the API checked strictly (ISS02915 #5): off, as before; on, a POST, PUT or
 * PATCH is application/json (multipart/form-data on the document route), else 415 with code 114
 * (a JSON-RPC error for api/mcp). GET and a request without a body are not checked.
 */
class ApiContentTypeTest extends ApiTestBase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        config(['exment.api_strict_content_type' => false]);
        parent::tearDown();
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $files
     * @param array<string, mixed> $parameters
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function send(string $method, string $uri, string $token, ?string $type, string $body = '', array $parameters = [], array $files = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        \Auth::forgetGuards();
        $server = ['Authorization' => "Bearer $token", 'Accept' => 'application/json'] + $headers;
        if (isset($type)) {
            $server['Content-Type'] = $type;
        }
        return $this->call($method, $uri, $parameters, [], $files, $this->transformHeadersToServerVars($server), $body);
    }

    /**
     * @param \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    protected function json415(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertStatus(415);
        $this->assertSame(ErrorCode::UNSUPPORTED_CONTENT_TYPE, $response->json('code'));
        $this->assertNotEmpty($response->json('message'));
    }

    public function testOffAsBefore(): void
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE, ApiScope::VALUE_READ]);
        $uri = admin_urls('api', 'data', 'custom_value_edit_all');
        $response = $this->send('POST', $uri, $token, 'application/x-www-form-urlencoded', 'value%5Btext%5D=off', ['value' => ['text' => 'off']]);
        $this->assertNotSame(415, $response->getStatusCode());
    }

    public function testOnlyJsonWhenOn(): void
    {
        config(['exment.api_strict_content_type' => true]);
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE, ApiScope::VALUE_READ]);
        $uri = admin_urls('api', 'data', 'custom_value_edit_all');
        $json = (string)json_encode(['value' => ['text' => 'strict json']]);

        // JSON (with a charset too): as before
        $this->assertNotSame(415, $this->send('POST', $uri, $token, 'application/json', $json)->getStatusCode());
        $this->assertNotSame(415, $this->send('POST', $uri, $token, 'application/json; charset=UTF-8', $json)->getStatusCode());

        // a form, plain text, no type: refused
        $this->json415($this->send('POST', $uri, $token, 'application/x-www-form-urlencoded', 'value%5Btext%5D=form', ['value' => ['text' => 'form']]));
        $this->json415($this->send('POST', $uri, $token, 'text/plain', $json));
        $this->json415($this->send('POST', $uri, $token, null, $json));
        // a multipart form where no file is taken
        $this->json415($this->send('POST', $uri, $token, 'multipart/form-data; boundary=x', '', ['value' => ['text' => 'multi']]));

        // PUT and PATCH too
        $this->json415($this->send('PUT', admin_urls('api', 'data', 'custom_value_edit_all', 1), $token, 'text/plain', $json));

        // GET and a request without a body: not checked
        $this->assertNotSame(415, $this->send('GET', $uri, $token, 'text/plain')->getStatusCode());
        $this->assertNotSame(415, $this->send('POST', admin_urls('api', 'data', 'custom_value_edit_all', 1), $token, null)->getStatusCode());
    }

    public function testMultipartForTheDocument(): void
    {
        config(['exment.api_strict_content_type' => true]);
        $token = $this->getAdminAccessToken([ApiScope::VALUE_WRITE, ApiScope::VALUE_READ]);

        $response = $this->send('POST', admin_urls('api', 'document', 'custom_value_edit', 1), $token, 'multipart/form-data; boundary=x', '', [
            'name' => 'strict.txt',
            'base64' => TestDefine::FILE_BASE64,
        ]);
        $this->assertNotSame(415, $response->getStatusCode());

        // nowhere else, unless the path is added
        config(['exment.api_strict_content_type_multipart' => []]);
        $this->json415($this->send('POST', admin_urls('api', 'document', 'custom_value_edit', 1), $token, 'multipart/form-data; boundary=x', '', [
            'name' => 'strict.txt',
            'base64' => TestDefine::FILE_BASE64,
        ]));
    }

    public function testMcpGetsAJsonRpcError(): void
    {
        config(['exment.features_locked' => '', 'exment.api_strict_content_type' => true]);
        System::features([Features::MCP => ['enabled' => true, 'role_groups' => null]]);
        System::clearRequestSession();
        Features::reset();
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ, ApiScope::TABLE_READ]);
        $ping = (string)json_encode(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);

        $refused = $this->send('POST', admin_urls('api', 'mcp'), $token, 'text/plain', $ping);
        $refused->assertStatus(415);
        $this->assertSame('2.0', $refused->json('jsonrpc'));
        $this->assertSame(-32600, $refused->json('error.code'));

        $this->send('POST', admin_urls('api', 'mcp'), $token, 'application/json', $ping)->assertOk();
        Features::reset();
    }
}
