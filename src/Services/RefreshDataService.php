<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Notifications;

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
    public static function refresh(){
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
            ->filter(function($relation) use($user, $org){
                // if org-user data, return false;
                if($relation->parent_custom_table_id == $org->id && $relation->child_custom_table_id == $user->id){
                    return false;
                }

                return true;
            })
            ->map(function($relation){
                return $relation->getRelationName();
            })
            ->filter(function($relation){
                return hasTable($relation);
            })->toArray()
        , $tables);

        // exm__ tables (ignore org)
        $tables = array_merge(CustomTable
            ::whereNotIn('id', [$user->id, $org->id, $mail_template->id])
            ->get()
            ->map(function($table){
                return getDBTableName($table);
            })
            ->filter(function($table){
                return hasTable($table);
            })->toArray()
        , $tables);

        // call truncate
        \DB::transaction(function() use($tables){
            foreach($tables as $table){
                \DB::table($table)->truncate();
            }
        });
    }
}
