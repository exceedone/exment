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
        $userTable = CustomTable::getEloquent(SystemTableName::USER);
        $orgTable = CustomTable::getEloquent(SystemTableName::ORGANIZATION);
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE);

        // pivot custom value's
        $tables = array_merge(CustomRelation::where('relation_type', RelationType::MANY_TO_MANY)
            ->get()
            ->filter(function ($relation) use ($userTable, $orgTable) {
                // if org-user data, return false;
                if ($relation->parent_custom_table_id == $orgTable->id && $relation->child_custom_table_id == $userTable->id) {
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
        $custom_tables = CustomTable::whereNotIn('id', [$userTable->id, $orgTable->id, $mail_template->id])
            ->get()
            ->filter(function ($table) {
                return hasTable(getDBTableName($table));
            });

        $tables = array_merge($custom_tables->map(function ($table) {
            return getDBTableName($table);
        })->toArray(), $tables);

        // call truncate
        \ExmentDB::transaction(function () use ($tables) {
            foreach ($tables as $table) {
                \DB::table($table)->truncate();
            }
        });

        // remove attachment files
        static::removeAttachmentFiles($custom_tables);
    }


    /**
     * Refresh transaction data selecting table
     *
     * @param array $tables
     * @return void
     */
    public static function refreshTable(array $tables)
    {
        // delete tables
        $deleteTables = [
            'notify_navbars' => ['type' => 'parent_type'],
            'revisions' => ['type' => 'revisionable_type'],
            'workflow_value_authorities' => ['type' => 'related_type'],
            'workflow_values' => ['type' => 'morph_type'],
            'custom_value_authoritables' => ['type' => 'parent_type'],
        ];

        // truancate tables;
        $truacateTables = [];
        $custom_tables = [];

        foreach ($tables as $table) {
            $custom_table = CustomTable::getEloquent($table);
            if (empty($custom_table)) {
                continue;
            }

            // truncate 1:n table if $custom_table is parent
            $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
            foreach ($relations as $relation) {
                $truacateTables[] = getDBTableName($relation->child_custom_table);
                $custom_tables[] = $relation->child_custom_table;
            }

            // pivot custom value's
            $pivots = CustomRelation::getRelationsByParent($custom_table, RelationType::MANY_TO_MANY);
            $pivots = $pivots->merge(CustomRelation::getRelationsByChild($custom_table, RelationType::MANY_TO_MANY));
            $pivots = $pivots->map(function ($relation) {
                return $relation->getRelationName();
            })
            ->each(function ($pivot) use (&$truacateTables) {
                $truacateTables[] = $pivot;
            });

            // truncate table self
            $truacateTables[] = getDBTableName($custom_table);
            $custom_tables[] = $custom_table;
        }


        // call truncate
        \ExmentDB::transaction(function () use ($truacateTables, $custom_tables, $deleteTables) {
            foreach ($custom_tables as $custom_table) {
                // delete
                foreach ($deleteTables as $deleteTableName => $deleteTable) {
                    \DB::table($deleteTableName)
                        ->where($deleteTable['type'], $custom_table->table_name)->delete();
                }

                // update select table's value
                collect($custom_table->getSelectedTableColumns())->each(function ($custom_column) {
                    $custom_table = $custom_column->custom_table_cache;
                    if (empty($custom_table)) {
                        return true;
                    }

                    $custom_table->getValueQuery()->withTrashed()
                        ->whereNotNull('value->' . $custom_column->column_name)
                        ->updateRemovingJsonKey('value->' . $custom_column->column_name);
                    //->update(['value->' . $custom_column->column_name => '']);
                });
            }

            foreach ($truacateTables as $table) {
                if (!hasTable($table)) {
                    continue;
                }

                \DB::table($table)->truncate();
            }
        });

        // remove attachment files
        static::removeAttachmentFiles($custom_tables);

        static::removeDocumentComments($custom_tables);
    }


    /**
     * Remove attachment files
     *
     * @param array|\Illuminate\Support\Collection $custom_tables
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

    /**
     * Remove document and comment
     *
     * @param \Illuminate\Support\Collection $custom_tables
     * @return void
     */
    public static function removeDocumentComments($custom_tables)
    {
        // delete tables
        $deleteTables = [
            SystemTableName::COMMENT,
            SystemTableName::DOCUMENT,
        ];
        foreach ($deleteTables as $deleteTable) {
            $deleteTableName = getDBTableName(CustomTable::getEloquent($deleteTable));
            if (!hasTable($deleteTableName)) {
                continue;
            }

            foreach ($custom_tables as $custom_table) {
                \DB::table($deleteTableName)->where('parent_type', $custom_table->table_name)
                    ->delete();
            }
        }
    }
}
