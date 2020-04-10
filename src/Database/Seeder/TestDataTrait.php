<?php

namespace Exceedone\Exment\Database\Seeder;

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
                    ],
                    'password' => 'user1user1',
                ],
                'user2' => [
                    'id' => 3,
                    'value' => [
                        'user_name' => 'user2',
                        'user_code' => 'user2',
                        'email' => 'user2@user.foobar.test',
                    ],
                    'password' => 'user2user2',
                ],
                'user3' => [
                    'id' => 4,
                    'value' => [
                        'user_name' => 'user3',
                        'user_code' => 'user3',
                        'email' => 'user2@user.foobar.test',
                    ],
                    'password' => 'user3user3',
                ],
                'company1-userA' => [
                    'id' => 5,
                    'value' => [
                        'user_name' => 'company1-userA',
                        'user_code' => 'company1-userA',
                        'email' => 'company1-userA@user.foobar.test',
                    ],
                    'password' => 'company1-userA',
                ],
                'dev-userB' => [
                    'id' => 6,
                    'value' => [
                        'user_name' => 'dev-userB',
                        'user_code' => 'dev-userB',
                        'email' => 'dev-userB@user.foobar.test',
                    ],
                    'password' => 'dev-userB',
                ],
                'dev1-userC' => [
                    'id' => 7,
                    'value' => [
                        'user_name' => 'dev1-userC',
                        'user_code' => 'dev1-userC',
                        'email' => 'dev1-userC@user.foobar.test',
                    ],
                    'password' => 'dev1-userC',
                ],
                'dev1-userD' => [
                    'id' => 8,
                    'value' => [
                        'user_name' => 'dev1-userD',
                        'user_code' => 'dev1-userD',
                        'email' => 'dev1-userD@user.foobar.test',
                    ],
                    'password' => 'dev1-userD',
                ],
                'dev2-userE' => [
                    'id' => 9,
                    'value' => [
                        'user_name' => 'dev2-userE',
                        'user_code' => 'dev2-userE',
                        'email' => 'dev2-userE@user.foobar.test',
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
}
