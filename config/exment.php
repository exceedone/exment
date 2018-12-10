<?php

return [

    'password_rule' => [
      'rule' => '^[ -~]+$',
      'min' => '8',
      'max' => '32',
    ],

    'manual_url' => env('EXMENT_MANUAL_URL', 'https://exment.net/docs/#/'),

    'show_default_login_provider' => env('EXMENT_SHOW_DEFAULT_LOGIN_PROVIDER', true),
    
    'login_providers' => env('EXMENT_LOGIN_PROVIDERS', []),
    
    'revision_count_default' => env('EXMENT_REVISION_COUNT', 100),
    
    'api' => env('EXMENT_API', false),
];
