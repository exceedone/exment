<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\UserApiKey;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The pages of the API keys (ISS00437): a user the feature allows makes, sees and stops
 * their own keys; a new key is shown once; the system administrators see all the keys and
 * stop them; nobody else does.
 */
class UserApiKeyPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Features::reset();
        config(['exment.features_locked' => '']);
        $this->setFeatures([Features::API_KEYS => ['enabled' => true, 'role_groups' => [$this->groupOfUser1Only()]]]);
    }

    protected function tearDown(): void
    {
        Features::reset();
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setFeatures(array $settings): void
    {
        System::features($settings);
        System::clearRequestSession();
        Features::reset();
    }

    protected function login(string $loginId): LoginUser
    {
        System::clearRequestSession();
        $user = LoginUser::findOrFail($loginId);
        $this->be($user);
        return $user;
    }

    protected function groupOfUser1Only(): int
    {
        $user1 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $only1 = array_values(array_diff(Features::roleGroupIds($user1), Features::roleGroupIds($user2)));
        $this->assertNotEmpty($only1);
        return $only1[0];
    }

    protected function baseUserId(string $loginId): int
    {
        return (int)LoginUser::findOrFail($loginId)->base_user_id;
    }

    public function testAUserMakesAKeyShownOnce(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee(exmtrans('api_key.my_keys'));

        $page = $this->post(admin_url('auth/api_keys'), ['name' => 'my tool', 'scope_type' => 'read_only', 'table_type' => 'all', 'days' => '30']);
        $page->assertOk()->assertSee(exmtrans('api_key.new_key'));
        $this->assertSame(1, preg_match('/exk_[0-9a-f]{48}/', (string)$page->getContent(), $m));
        $plain = strval($m[0] ?? '');

        /** @var UserApiKey $key */
        $key = UserApiKey::where('user_id', $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1))->firstOrFail();
        $this->assertSame(UserApiKeys::hash($plain), $key->key_hash);
        $this->assertSame(UserApiKeys::readOnlyScopes(), $key->scopes);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $key->expires_at->getTimestamp(), 60);
        // kept nowhere: not in the session, not on the page again
        $this->assertStringNotContainsString($plain, json_encode(session()->all()) ?: '');
        $again = $this->get(admin_url('auth/api_keys'));
        $again->assertOk()->assertSee('my tool')->assertSee($key->key_prefix);
        $again->assertDontSee($plain);
    }

    public function testWrongValuesAreRefusedOnThePage(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'scope_type' => 'custom', 'scopes' => ['not_a_scope']])
            ->assertOk()->assertSee(exmtrans('api_key.errors.scopes'));
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'allowed_ips' => 'not an ip'])
            ->assertOk()->assertSee(exmtrans('api_key.errors.allowed_ips'));
        // a table the user may not see, sent by hand
        $this->post(admin_url('auth/api_keys'), ['name' => 'x', 'table_type' => 'some', 'tables' => ['not_a_table']])
            ->assertOk()->assertSee(exmtrans('api_key.errors.tables'));
        $this->assertSame(0, UserApiKey::count());
    }

    public function testOnlyTheUsersTheFeatureAllows(): void
    {
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->get(admin_url('auth/api_keys'))->assertForbidden();
        $this->post(admin_url('auth/api_keys'), ['name' => 'x'])->assertForbidden();
        $this->assertSame(0, UserApiKey::count());

        $this->setFeatures([Features::API_KEYS => ['enabled' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertNotFound();
    }

    public function testAUserStopsOnlyTheirOwnKeys(): void
    {
        [$other] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2), 'other', UserApiKeys::readOnlyScopes());
        [$mine] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'mine', UserApiKeys::readOnlyScopes());

        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('auth/api_keys'))->assertOk()->assertSee('mine')->assertDontSee('other');
        // (an ajax request: the admin pages show a "not found" page with 200 otherwise)
        $this->post(admin_url("auth/api_keys/{$other->id}/revoke"), [], ['X-Requested-With' => 'XMLHttpRequest'])->assertNotFound();
        $this->post(admin_url("auth/api_keys/{$other->id}/revoke"))->assertDontSee(exmtrans('api_key.message_revoked'));
        $this->assertNull($other->refresh()->revoked_at);

        $this->post(admin_url("auth/api_keys/{$mine->id}/revoke"))->assertRedirect();
        $this->assertNotNull($mine->refresh()->revoked_at);
    }

    public function testTheAdministratorsSeeAndStopAllKeys(): void
    {
        [$a] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'key a', UserApiKeys::readOnlyScopes());
        [$b] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1), 'key b', UserApiKeys::readOnlyScopes());
        [$c] = UserApiKeys::create($this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2), 'key c', UserApiKeys::readOnlyScopes());

        // not for the other users (an ajax request, so the denial is a plain 403)
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];
        $this->login(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->get(admin_url('api_keys'), $ajax)->assertForbidden()->assertDontSee('key c');
        $this->post(admin_url("api_keys/{$c->id}/revoke"), [], $ajax)->assertForbidden();
        $this->post(admin_url('api_keys/user/' . $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER2) . '/revoke'), [], $ajax)->assertForbidden();
        $this->assertNull($c->refresh()->revoked_at);

        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee('key a')->assertSee('key c')->assertDontSee($a->key_hash);
        $this->post(admin_url("api_keys/{$c->id}/revoke"))->assertRedirect();
        $this->assertNotNull($c->refresh()->revoked_at);
        $this->post(admin_url('api_keys/user/' . $this->baseUserId(TestDefine::TESTDATA_USER_LOGINID_USER1) . '/revoke'))->assertRedirect();
        $this->assertNotNull($a->refresh()->revoked_at);
        $this->assertNotNull($b->refresh()->revoked_at);

        // the admin page stays when the feature is off (to stop keys before it is turned on again)
        $this->setFeatures([Features::API_KEYS => ['enabled' => false]]);
        $this->login(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->get(admin_url('api_keys'))->assertOk()->assertSee(exmtrans('api_key.feature_off'));
    }
}
