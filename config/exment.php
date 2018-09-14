<?php

return [

    'password_rule' => [
      'rule' => '^[ -~]+$',
      'min' => '8',
      'max' => '32',
    ],

    'manual_url' => env('EXMENT_MANUAL_URL', '')
];
