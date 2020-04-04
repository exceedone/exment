<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\File as ExmentFile;

/**
 * Refresh Data Service. truncate exm__ table data etc.
 */
class RefreshDataService
{
    /**
     * Refresh transaction data
     *
     * @return void
     */
    public static function refresh()
    {
        // trancate tables
        $tables = [
            'admin_operation_log',
            'email_code_verifies',
            'notify_navbars',
            'revisions',
            'workflow_value_authorities',
            'workflow_values',
            'custom_value_authoritables',
        ];

        // get user and org table info
        $user = CustomTable::getEloquent(SystemTableName::USER);
        $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);

        // pivot custom value's
        $tables = array_merge(CustomRelation::where('relation_type', RelationType::MANY_TO_MANY)
            ->get()
            ->filter(function ($relation) use ($user, $org) {
                // if org-user data, return false;
                if ($relation->parent_custom_table_id == $org->id && $relation->child_custom_table_id == $user->id) {
                    return false;
                }

                return true;
            })
            ->map(function ($relation) {
                return $relation->getRelationName();
            })
            ->filter(function ($relation) {
                return hasTable($relation);
            })->toArray(), $tables);

        // exm__ tables (ignore org)
        $custom_tables = CustomTable
            ::whereNotIn('id', [$user->id, $org->id, $mail_template->id])
            ->get()
            ->filter(function ($table) {
                return hasTable(getDBTableName($table));
            });

        $tables = array_merge($custom_tables->map(function ($table) {
            return getDBTableName($table);
        })->toArray(), $tables);

        // call truncate
        \DB::transaction(function () use ($tables) {
            foreach ($tables as $table) {
                \DB::table($table)->truncate();
            }
        });

        // remove attachment files
        static::removeAttachmentFiles($custom_tables);
    }

    /**
     * Remove attachment files
     *
     * @param [type] $custom_tables
     * @return void
     */
    public static function removeAttachmentFiles($custom_tables)
    {
        $disk = \Storage::disk(Define::DISKNAME_ADMIN);

        foreach ($custom_tables as $custom_table) {
            // remove file table
            ExmentFile::where('parent_type', $custom_table->table_name)
                ->delete();

            if (!$disk->exists($custom_table->table_name)) {
                continue;
            }

            // if avatar or system, continue
            if (in_array($custom_table->table_name, ['avatar', 'system'])) {
                continue;
            }
            deleteDirectory($disk, $custom_table->table_name);
        }
    }
}
