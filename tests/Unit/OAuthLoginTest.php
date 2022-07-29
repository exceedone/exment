<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Services\Login\OAuth\OAuthUser;
use Exceedone\Exment\Exceptions\SsoLoginErrorException;
use Laravel\Socialite\Two\User as SocialiteUser;
use Exceedone\Exment\Tests\DatabaseTransactions;

class OAuthLoginTest extends UnitTestBase
{
    use TestTrait;
    use DatabaseTransactions;

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

        $login_setting = LoginSetting::create([
            'login_view_name' => 'test',
            'login_type' => 'oauth',
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => 'google',
                'mapping_user_column' => $options['mapping_user_column'],
                'sso_jit' => $options['sso_jit'],
                'update_user_info' => $options['update_user_info'],
            ],
        ]);

        $test_user = (new SocialiteUser())->map([
            'id' => $options['user_code'],
            'name' => 'テストユーザー',
            'email' => $options['email'],
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        try {
            $result = LoginService::executeLogin(request(), $custom_login_user);
        } catch (\Exception $ex) {
            $this->assertTrue($ex instanceof SsoLoginErrorException);
            $this->assertMatchRegex('/ユーザーが存在しません/', $ex->getMessage());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        try {
            $result = LoginService::executeLogin(request(), $custom_login_user);
        } catch (\Exception $ex) {
            $this->assertTrue($ex instanceof SsoLoginErrorException);
            $this->assertMatchRegex('/ユーザーが存在しません/', $ex->getMessage());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, 'unittest_user');
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'unittest@mail.com');
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


        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, 'unittest_user');
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'unittest@mail.com');
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
        $this->assertMatchRegex('/正しい形式のメールアドレスを指定してください/', $validator->errors()->first());
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
        $this->assertMatchRegex('/正しい形式のuser codeを指定してください/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'user1@user.foobar.test');
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'user1@user.foobar.test');
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
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
        $this->assertMatchRegex('/列user codeの値は、.+から変更できません。/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
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
        $this->assertMatchRegex('/列user codeの値は、.+から変更できません。/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
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
        $this->assertMatchRegex('/列user codeの値は、.+から変更できません。/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
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
        $this->assertMatchRegex('/列user codeの値は、.+から変更できません。/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user1->getValue('user_code'));
        $this->assertEquals($result->user_name, $user1->getValue('user_name'));
        $this->assertEquals($result->email, $user1->getValue('email'));
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
        $this->assertMatchRegex('/列user codeの値は、.+から変更できません。/', $validator->errors()->first());
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user1->getValue('user_code'));
        $this->assertEquals($result->user_name, $user1->getValue('user_name'));
        $this->assertEquals($result->email, $user1->getValue('email'));
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: user_code
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'unittest@mail.com');
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: user_code
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: user_code
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, 'テストユーザー');
        $this->assertEquals($result->email, 'unittest@mail.com');
    }

    /**
     * same user exists (only user_code match)
     * <login setting>
     * mapping column: user_code
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

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
    }

    /**
     * same user exists (only user_code match, email format error)
     * <login setting>
     * mapping column: user_code
     * new user create: yes
     * update user info: yes
     */
    public function testValidateErrorEmailUpdate()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => $user->getValue('user_code'),
            'email' => 'wrongaddress'
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
        $this->assertMatchRegex('/正しい形式のメールアドレスを指定してください/', $validator->errors()->first());
    }

    /**
     * same user exists (only user_code match, email format error)
     * <login setting>
     * mapping column: user_code
     * new user create: yes
     * update user info: no
     */
    public function testValidateErrorEmailNoUpdate()
    {
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'user_code' => $user->getValue('user_code'),
            'email' => 'wrongaddress'
        ]);

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user->getValue('user_code'));
        $this->assertEquals($result->user_name, $user->getValue('user_name'));
        $this->assertEquals($result->email, $user->getValue('email'));
    }

    /**
     * same user exists (only user_code match, email match with other user)
     * <login setting>
     * mapping column: user_code
     * new user create: no
     * update user info: yes
     */
    public function testOtherEmailMatch1()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'update_user_info' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
        $this->assertMatchRegex('/そのemailはすでに使われています/', $validator->errors()->first());
    }

    /**
     * same user exists (only user_code match, email match with other user)
     * <login setting>
     * mapping column: user_code
     * new user create: no
     * update user info: no
     */
    public function testOtherEmailMatch2()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user2->getValue('user_code'));
        $this->assertEquals($result->user_name, $user2->getValue('user_name'));
        $this->assertEquals($result->email, $user2->getValue('email'));
    }

    /**
     * same user exists (only user_code match, email match with other user)
     * <login setting>
     * mapping column: user_code
     * new user create: yes
     * update user info: yes
     */
    public function testOtherEmailMatch3()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'update_user_info' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $result = $validator->passes();
        $this->assertFalse($result);
        $this->assertMatchRegex('/そのemailはすでに使われています/', $validator->errors()->first());
    }

    /**
     * same user exists (only user_code match, email match with other user)
     * <login setting>
     * mapping column: user_code
     * new user create: yes
     * update user info: no
     */
    public function testOtherEmailMatch4()
    {
        $user1 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = CustomTable::getEloquent(SystemTableName::USER)->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        list($custom_login_user, $validator) = $this->_commonProcess([
            'mapping_user_column' => 'user_code',
            'sso_jit' => '1',
            'user_code' => $user2->getValue('user_code'),
            'email' => $user1->getValue('email'),
        ]);

        $this->assertTrue($validator->passes(), 'Validation pass error.');
        $result = LoginService::executeLogin(request(), $custom_login_user);
        $this->assertTrue($result instanceof LoginUser);
        $this->assertEquals($result->user_code, $user2->getValue('user_code'));
        $this->assertEquals($result->user_name, $user2->getValue('user_name'));
        $this->assertEquals($result->email, $user2->getValue('email'));
    }
}
