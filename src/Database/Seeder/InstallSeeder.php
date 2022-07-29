<?php

namespace Exceedone\Exment\Database\Seeder;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Exceedone\Exment\Services\TemplateImportExport;

class InstallSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        //DB::beginTransaction();
        try {
            // DROP laravel-admin's default TABLES
            $laravel_admin_tables = [
                'admin_user_permissions',
                'admin_role_permissions',
                'admin_role_users',
                'admin_role_menu',
                //'admin_menu',
                'admin_permissions',
                'admin_roles',
                'admin_users',
            ];

            foreach ($laravel_admin_tables as $laravel_admin_table) {
                DB::statement("DROP TABLE IF EXISTS $laravel_admin_table;");
            }

            // DELETE
            DB::table(config('admin.database.menu_table'))->delete();

            $importer = new TemplateImportExport\TemplateImporter();
            $importer->importSystemTemplate();
        } catch (\Exception $exception) {
            //DB::rollback();
            throw $exception;
        }

        return;
    }
}
