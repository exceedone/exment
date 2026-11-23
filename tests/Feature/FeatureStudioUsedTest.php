<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Support\Facades\DB;

/**
 * Updating to Feature management keeps Studio on where the site used it: an app made with
 * Studio counts even when the operation log was reset (a demo site restored every day).
 */
class FeatureStudioUsedTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private function studioUsed(): bool
    {
        $migration = require dirname(__DIR__, 2) . '/database/migrations/2026_11_23_000001_add_feature_management.php';
        $method = new \ReflectionMethod($migration, 'studioUsed');
        $method->setAccessible(true);
        return (bool) $method->invoke($migration);
    }

    public function testAnAppMadeWithStudioCountsWithoutTheLog(): void
    {
        $log = config('admin.database.operation_log_table', 'admin_operation_log');
        $prefix = trim(strval(config('admin.route.prefix')), '/');
        DB::table('dashboards')->where('dashboard_name', 'like', 'studio_%')->delete();
        DB::table($log)->where('path', 'like', '%studio%')->delete();
        DB::table($log)->where('path', 'like', '%app%')->delete();
        // a log without Studio in it (reset, then other pages opened)
        DB::table($log)->insert(['user_id' => 1, 'path' => ($prefix === '' ? '' : $prefix . '/') . 'data/information', 'method' => 'GET', 'ip' => '127.0.0.1', 'input' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertFalse($this->studioUsed(), 'no app, nothing in the log');

        DB::table('dashboards')->insert([
            'suuid' => short_uuid(),
            'dashboard_type' => 0,
            'dashboard_name' => 'studio_' . short_uuid(),
            'dashboard_view_name' => 'Studio app',
            'default_flg' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertTrue($this->studioUsed(), 'an app made with Studio');
    }
}
