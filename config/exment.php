<?php

return [

    'password_rule' => [
      'rule' => '^[ -~]+$',
      'min' => '8',
      'max' => '32',
    ],

    'organization_deeps' => env('EXMENT_ORGANIZATION_DEEPS', 4),

    'dashboard_rows' => env('EXMENT_DASHBOARD_ROWS', 4),

    'manual_url' => env('EXMENT_MANUAL_URL', 'https://exment.net/docs/#/'),

    'show_default_login_provider' => env('EXMENT_SHOW_DEFAULT_LOGIN_PROVIDER', true),
    
    'login_providers' => env('EXMENT_LOGIN_PROVIDERS', []),
    
    'revision_count_default' => env('EXMENT_REVISION_COUNT', 100),
    
    'api' => env('EXMENT_API', false),

    'backup_info' => [
      'mysql_dir' => env('EXMENT_MYSQL_BIN_DIR'),
      'def_file' => 'table_definition.sql',
      'copy_dir' => [
      ],
    ],

];
