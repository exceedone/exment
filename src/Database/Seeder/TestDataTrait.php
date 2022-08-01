<?php

namespace Exceedone\Exment\Database\Seeder;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Tests\TestDefine;

trait TestDataTrait
{
    public function getUsersAndOrgs()
    {
        // set users
        $values = [
            'user' => [
                'admin' => [
                    'id' => 1,
                    'value' => [
                        'user_name' => 'admin',
                        'user_code' => 'admin',
                        'email' => 'admin@admin.foobar.test',
                    ],
                    'password' => 'adminadmin',
                ],
                'user1' => [
                    'id' => 2,
                    'value' => [
                        'user_name' => 'user1',
                        'user_code' => 'user1',
                        'email' => 'user1@user.foobar.test',
                        'boss' => 1,
                    ],
                    'password' => 'user1user1',
                ],
                'user2' => [
                    'id' => 3,
                    'value' => [
                        'user_name' => 'user2',
                        'user_code' => 'user2',
                        'email' => 'user2@user.foobar.test',
                        'boss' => 1,
                    ],
                    'password' => 'user2user2',
                    'avatar' => TestDefine::FILE_USER_BASE64,
                ],
                'user3' => [
                    'id' => 4,
                    'value' => [
                        'user_name' => 'user3',
                        'user_code' => 'user3',
                        'email' => 'user3@user.foobar.test',
                        'boss' => 2, //user1
                    ],
                    'password' => 'user3user3',
                ],
                'company1-userA' => [
                    'id' => 5,
                    'value' => [
                        'user_name' => 'company1-userA',
                        'user_code' => 'company1-userA',
                        'email' => 'company1-userA@user.foobar.test',
                        'boss' => 1,
                    ],
                    'password' => 'company1-userA',
                ],
                'dev0-userB' => [
                    'id' => 6,
                    'value' => [
                        'user_name' => 'dev0-userB',
                        'user_code' => 'dev0-userB',
                        'email' => 'dev0-userB@user.foobar.test',
                        'boss' => 3, // userB
                    ],
                    'password' => 'dev0-userB',
                ],
                'dev1-userC' => [
                    'id' => 7,
                    'value' => [
                        'user_name' => 'dev1-userC',
                        'user_code' => 'dev1-userC',
                        'email' => 'dev1-userC@user.foobar.test',
                        'boss' => 6, // dev0-userB
                    ],
                    'password' => 'dev1-userC',
                ],
                'dev1-userD' => [
                    'id' => 8,
                    'value' => [
                        'user_name' => 'dev1-userD',
                        'user_code' => 'dev1-userD',
                        'email' => 'dev1-userD@user.foobar.test',
                        'boss' => 6, // dev0-userB
                    ],
                    'password' => 'dev1-userD',
                ],
                'dev2-userE' => [
                    'id' => 9,
                    'value' => [
                        'user_name' => 'dev2-userE',
                        'user_code' => 'dev2-userE',
                        'email' => 'dev2-userE@user.foobar.test',
                        'boss' => 6, // dev0-userB
                    ],
                    'password' => 'dev2-userE',
                ],
                'company2-userF' => [
                    'id' => 10,
                    'value' => [
                        'user_name' => 'company2-userF',
                        'user_code' => 'company2-userF',
                        'email' => 'company2-userF@user.foobar.test',
                    ],
                    'password' => 'company2-userF',
                ],
            ],

            'organization' => [
                'company1' => [
                    'id' => 1,
                    'value' => [
                        'organization_name' => 'company1',
                        'organization_code' => 'company1',
                        'parent_organization' => null,
                    ],
                    'users' => [
                        5
                    ],
                ],
                'dev' => [
                    'id' => 2,
                    'value' => [
                        'organization_name' => 'dev',
                        'organization_code' => 'dev',
                        'parent_organization' => 1,
                    ],
                    'users' => [
                        6
                    ],
                ],
                'manage' => [
                    'id' => 3,
                    'value' => [
                        'organization_name' => 'manage',
                        'organization_code' => 'manage',
                        'parent_organization' => 1,
                    ],
                ],
                'dev1' => [
                    'id' => 4,
                    'value' => [
                        'organization_name' => 'dev1',
                        'organization_code' => 'dev1',
                        'parent_organization' => 2,
                    ],
                    'users' => [
                        7, 8
                    ],
                ],
                'dev2' => [
                    'id' => 5,
                    'value' => [
                        'organization_name' => 'dev2',
                        'organization_code' => 'dev2',
                        'parent_organization' => 2,
                    ],
                    'users' => [
                        9
                    ],
                ],
                'company2' => [
                    'id' => 6,
                    'value' => [
                        'organization_name' => 'company2',
                        'organization_code' => 'company2',
                        'parent_organization' => null,
                    ],
                    'users' => [
                        10
                    ],
                ],
                'company2-a' => [
                    'id' => 7,
                    'value' => [
                        'organization_name' => 'company2-a',
                        'organization_code' => 'company2-a',
                        'parent_organization' => 6,
                    ],
                ],
            ]
        ];


        return $values;
    }

    /**
         * Get mail template from key
         *
         * @param CustomValue|string|null $mail_template
         * @return CustomValue|null
         */
    protected function getMailTemplateFromKey($mail_template): ?CustomValue
    {
        if (is_null($mail_template)) {
            return null;
        } elseif ($mail_template instanceof CustomValue) {
            return $mail_template;
        }

        $result = null;
        if (is_numeric($mail_template)) {
            $result = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_template);
        } else {
            $result = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $mail_template)->first();
        }
        // if not found, return exception
        if (is_null($result)) {
            throw new NoMailTemplateException($mail_template);
        }

        return $result;
    }
}
