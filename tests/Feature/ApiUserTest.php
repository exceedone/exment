<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiScope;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Tests\TestDefine;

class ApiUserTest extends ApiTestBase
{
    // Me ----------------------------------------------------

    public function testWrongScopeMe()
    {
        $token = $this->getAdminAccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetMe()
    {
        $token = $this->getAdminAccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'value' => [
                    "email"=> "admin@admin.foobar.test",
                    "user_code"=> "admin",
                    "user_name"=> "admin"
                ]
            ])
            ->assertJsonStructure([
                'id',
                'suuid',
                'created_at',
                'updated_at',
                'created_user_id',
                'updated_user_id',
            ]);
    }

    public function testWrongScopeMeApiKey()
    {
        $token = $this->getAdminAccessTokenAsApiKey([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetMeApiKey()
    {
        $token = $this->getAdminAccessTokenAsApiKey([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'me'))
            ->assertStatus(200)
            ->assertJsonFragment([
                'value' => [
                    "email"=> "admin@admin.foobar.test",
                    "user_code"=> "admin",
                    "user_name"=> "admin"
                ]
            ])
            ->assertJsonStructure([
                'id',
                'suuid',
                'created_at',
                'updated_at',
                'created_user_id',
                'updated_user_id',
            ]);
    }



    // Avatar ----------------------------------------------------

    public function testWrongScopeAvatar()
    {
        $token = $this->getUser1AccessToken([ApiScope::VALUE_READ]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'avatar'))
            ->assertStatus(403)
            ->assertJsonFragment([
                'code' => ErrorCode::WRONG_SCOPE
            ]);
    }

    public function testGetAvatarDefaultBase64()
    {
        // User1 not has icon.
        $token = $this->getUser1AccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'avatar', ['default' => '1', 'base64' => '1']))
            ->assertStatus(200)
            ->assertJsonFragment([
                'base64' => TestDefine::FILE_USERDEFALUT_BASE64,
            ]);
    }

    public function testGetAvatarBase64Null()
    {
        // User1 not has icon.
        $token = $this->getUser1AccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'avatar', ['base64' => '1']))
            ->assertStatus(200)
            ->assertJsonFragment([
                'base64' => null,
            ]);
    }

    public function testGetAvatarBase64()
    {
        // User2 has icon.
        $token = $this->getUser2AccessToken([ApiScope::ME]);

        $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'avatar', ['base64' => '1']))
            ->assertStatus(200)
            ->assertJsonFragment([
                'base64' => TestDefine::FILE_USER_BASE64,
            ]);
    }

    public function testGetAvatar()
    {
        // User2 has icon.
        $token = $this->getUser2AccessToken([ApiScope::ME]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'avatar'));

        $content = $response->streamedContent();
        $this->assertMatch(base64_encode($content), TestDefine::FILE_USER_BASE64);
    }

    public function testGetAvatar2()
    {
        // User2 has icon.
        $token = $this->getUser2AccessToken([ApiScope::ME]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls_query('api', 'avatar', ['default' => '1']));

        $content = $response->streamedContent();
        $this->assertMatch(base64_encode($content), TestDefine::FILE_USER_BASE64);
    }

    public function testGetAvatarDefault()
    {
        // User1 has not icon.
        $token = $this->getUser1AccessToken([ApiScope::ME]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
            ])->get(admin_urls_query('api', 'avatar', ['default' => '1']));

        $content = $response->streamedContent();
        $this->assertMatch(base64_encode($content), TestDefine::FILE_USERDEFALUT_BASE64);
    }

    public function testGetAvatarNotHas()
    {
        // User1 has not icon.
        $token = $this->getUser1AccessToken([ApiScope::ME]);

        $response = $this->withHeaders([
            'Authorization' => "Bearer $token",
        ])->get(admin_urls('api', 'avatar'));

        // Response is not file.
        $this->assertTrue($response->baseResponse instanceof \Illuminate\Http\Response);
        /** @phpstan-ignore-next-line next line always false */
        $this->assertFalse($response->baseResponse instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse);
    }
}
