<?php

return [

    'password_rule' => [
      'rule' => '^[ -~]+$',
      'min' => '8',
      'max' => '32',
    ],

    'manual_url' => env('EXMENT_MANUAL_URL', 'https://exment.net/docs/#/'),

    'show_default_login_provider' => env('SHOW_DEFAULT_LOGIN_PROVIDER', true),
    'login_providers' => env('LOGIN_PROVIDERS', []),
    'revision_count_default' => env('REVISION_COUNT', 100),
];
