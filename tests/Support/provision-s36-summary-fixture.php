<?php

/**
 * Fixture of the multi-value select_table pivot summary acceptance (A36), on top of the Hybrid P1 test data
 * (parent_table_select / pivot_table_select, column parent_multi).
 *
 * Run on an ISOLATED TEST database only, from the application root:
 *   php artisan tinker --execute="require 'packages/exceedone/exment/tests/Support/provision-s36-summary-fixture.php';"
 *
 * Refuses unless APP_ENV is "testing" and the database name contains "test". Idempotent: does nothing when
 * the marker rows already exist. Adds:
 *   - children 101 ["3","3"] (repeated element), 102 ["1","10"] (1 vs 10), 103 ["2","zzz"] (invalid element)
 *   - parent 11 without any child
 *   - role group "hyb_s36_rg" and view shares for the login user "user2": parents 1, 3, 10 and children 101, 102
 * Expected totals per parent: tests/Support/s36-expected-summary.php.
 */

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValueAuthoritable;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupPermission;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;

$c = config('database.connections.' . config('database.default'));
if (config('app.env') !== 'testing' || stripos((string)($c['database'] ?? ''), 'test') === false) {
    fwrite(STDERR, "refused: not an isolated test database\n");
    return;
}

$parent = CustomTable::getEloquent('parent_table_select');
$child = CustomTable::getEloquent('pivot_table_select');
$pt = getDBTableName($parent);
$ct = getDBTableName($child);
if (DB::table($ct)->where('id', '>', 100)->exists()) {
    echo "already set up\n";
    return;
}

$now = date('Y-m-d H:i:s');
DB::table($pt)->insert(['id' => 11, 'suuid' => 's36orphanparent0001', 'value' => json_encode(['text' => 'test_11_orphan', 'user' => '1']), 'created_at' => $now, 'updated_at' => $now, 'created_user_id' => 1, 'updated_user_id' => 1]);
foreach ([101 => ['3', '3'], 102 => ['1', '10'], 103 => ['2', 'zzz']] as $id => $multi) {
    DB::table($ct)->insert(['id' => $id, 'suuid' => 's36child' . $id . '0000000', 'value' => json_encode(['parent_multi' => $multi]), 'created_at' => $now, 'updated_at' => $now, 'created_user_id' => 1, 'updated_user_id' => 1]);
}

$rg = new RoleGroup();
$rg->role_group_name = 'hyb_s36_rg';
$rg->role_group_view_name = 'hyb s36';
$rg->save();
foreach ([$parent, $child] as $t) {
    RoleGroupPermission::create(['role_group_id' => $rg->id, 'role_group_permission_type' => RoleType::TABLE, 'role_group_target_id' => $t->id, 'permissions' => [Permission::CUSTOM_VALUE_VIEW]]);
}

// the user table row (base user) whose code is user2; NOT the login_users.id (user1 is 2, user2 is 3)
$userTable = CustomTable::getEloquent(SystemTableName::USER);
$u2 = $userTable->getValueModel()->where('value->user_code', 'user2')->first();
if (!isset($u2)) {
    fwrite(STDERR, "refused: user2 not found\n");
    return;
}
RoleGroupUserOrganization::create(['role_group_id' => $rg->id, 'role_group_user_org_type' => SystemTableName::USER, 'role_group_target_id' => $u2->id]);
foreach ([[$parent, [1, 3, 10]], [$child, [101, 102]]] as [$table, $ids]) {
    foreach ($ids as $id) {
        CustomValueAuthoritable::create(['parent_id' => $id, 'parent_type' => $table->table_name, 'authoritable_type' => Permission::CUSTOM_VALUE_VIEW, 'authoritable_user_org_type' => SystemTableName::USER, 'authoritable_target_id' => $u2->id]);
    }
}
System::clearCache();
echo 'setup done: role_group=', $rg->id, ' user2 base id=', $u2->id, "\n";
