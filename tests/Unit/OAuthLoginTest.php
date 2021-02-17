<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Services\Login\OAuth\OAuthService;
use Exceedone\Exment\Services\Login\OAuth\OAuthUser;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Illuminate\Support\Arr;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class OAuthLoginTest extends UnitTestBase
{
    use TestTrait, DatabaseTransactions;

    protected function _commonProcess($options = [])
    {
        $this->initAllTest();

        $options = array_merge([
            'mapping_user_column' => 'email',
            'sso_jit' => '0',
            'update_user_info' => '0',
            'user_code' => 'unittest_user',
            'email' => 'unittest@mail.com'
        ], $options);

        extract($options);

        $login_setting = LoginSetting::create([
            'login_type' => 'oauth',
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => 'google',
                'mapping_user_column' => $mapping_user_column,
                'sso_jit' => $sso_jit,
                'update_user_info' => $update_user_info
            ]
        ]);

        $test_user = (new SocialiteUser)->map([
            'id' => $user_code,
            'name' => 'テストユーザー',
            'email' => $email,
        ]);

        $custom_login_user = OAuthUser::with('google', $test_user, true);

        $validator = LoginService::validateCustomLoginSync($custom_login_user);

        return [$custom_login_user, $validator];
    }

    /**
     * new user
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: yes
     */
    public function testNewUserNoCreate1()
    {
        list($custom_login_user, $validator) = $this->_commonProcess([
            'update_user_info' => '1'
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
            } catch (\Exception $ex) {
                $this->assertTrue($ex instanceof SsoLoginErrorException);
            }
        }
    }

    /**
     * new user
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: no
     */
    public function testNewUserNoCreate2()
    {
        list($custom_login_user, $validator) = $this->_commonProcess();

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
            } catch (\Exception $ex) {
                $this->assertTrue($ex instanceof SsoLoginErrorException);
            }
        }
    }

    /**
     * new user
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testNewUserCreate1()
    {
        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'update_user_info' => '1'
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * new user
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testNewUserCreate2()
    {
        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
        ]);


        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * email format error
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testValidateErrorEmail()
    {
        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'email' => 'error_mail_address',
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * user_code character type error
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testValidateErrorUserCode()
    {
        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'user_code' => 'あいうえお',
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (email, user_code match)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: yes
     */
    public function testExistsUser1()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'update_user_info' => '1',
            'user_code' => $user->getValue('user_code'),
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (email, user_code match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testExistsUser2()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'user_code' => $user->getValue('user_code'),
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (email, user_code match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testExistsUser3()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => $user->getValue('user_code'),
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (email, user_code match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testExistsUser4()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'user_code' => $user->getValue('user_code'),
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only email match)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: yes
     */
    public function testExistsUserEmail1()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'update_user_info' => '1',
            'email' => $user->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (only email match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testExistsUserEmail2()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only email match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testExistsUserEmail3()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'update_user_info' => '1',
            'email' => $user->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (only email match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testExistsUserEmail4()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only email match)
     * user_code character type error
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testValidateErrorUpdate()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => 'あいうえお',
            'email' => $user->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (only email match)
     * user_code character type error
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testValidateErrorNoUpdate()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'user_code' => 'あいうえお',
            'email' => $user->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only email match, user_code match with other user)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: yes
     */
    public function testOtherUserCodeMatch1()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'update_user_info' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (only email match, user_code match with other user)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: no
     */
    public function testOtherUserCodeMatch2()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only email match, user_code match with other user)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testOtherUserCodeMatch3()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
    }

    /**
     * same user exists (only email match, user_code match with other user)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testOtherUserCodeMatch4()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'sso_jit' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: yes
     */
    public function testExistsUserUserCode1()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'update_user_info' => '1',
            'user_code' => $user->getValue('user_code'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: email
     * new user create: no
     * update user info: no
     */
    public function testExistsUserUserCode2()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'user_code' => $user->getValue('user_code'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: yes
     */
    public function testExistsUserUserCode3()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => $user->getValue('user_code'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: email
     * new user create: yes
     * update user info: no
     */
    public function testExistsUserUserCode4()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'user_code' => $user->getValue('user_code'),
        ]);

        if ($validator->passes()) {
            try {
                $result = LoginService::executeLogin(request(), $custom_login_user);
                $this->assertTrue($result);
            } catch (\Exception $ex) {
            }
        }
    }
}
