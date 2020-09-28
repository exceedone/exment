<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Collection;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTargetType;
use Exceedone\Exment\Enums\Permission;

/**
 * get and set notify target.
 * Contains
 *  (1)Email string
 *  (2)User model
 * CAUTION: this is not eloquent model
 */
class NotifyTarget
{
    /**
     * target value
     *
     * @var CustomValue|null
     */
    protected $targetValue;

    /**
     * target custom column notify
     *
     * @var CustomColumn|null
     */
    protected $customColumn;

    /**
     * NotifyTargetType
     *
     * @var string
     */
    protected $targetType;

    /**
     * Email Address
     *
     * @var string
     */
    protected $email;

    /**
     * User name
     *
     * @var string
     */
    protected $name;

    // /**
    //  * User code
    //  *
    //  * @var string
    //  */
    // protected $userCode;

    /**
     * notify key
     *
     * @var string
     */
    protected $notifyKey;

    /**
     * whether joins user name
     *
     * @var bool
     */
    protected $joinName;

    /**
     * slack user id
     *
     * @var string
     */
    protected $slack_id;
    
    /**
     * user id if email, this value is null.
     *
     * @var string
     */
    protected $id;

    public function notifyKey()
    {
        return $this->notifyKey;
    }
    
    public function id()
    {
        return $this->id;
    }
    
    public function email()
    {
        return $this->email;
    }
    
    public function slack_id()
    {
        return $this->slack_id;
    }
    
    public function getLabel()
    {
        if(isset($this->email)){
            return $this->email;
        }

        return $this->name;
    }
    
    public function toArray()
    {
        return [
            'email' => $this->email,
            //'user_code' => $this->userCode,
            'user_name' => $this->name,
        ];
    }


    /**
     * get models
     *
     * @return Collection
     */
    public static function getModels(Notify $notify, CustomValue $custom_value, $column)
    {
        $result = [];
        // if role users, getModelsAsRole
        if ($column == NotifyActionTarget::CREATED_USER) {
            $result[] = static::getModelAsUser(CustomTable::getEloquent(SystemTableName::USER)->getValueModel($custom_value->created_user_id));
        } elseif ($column == NotifyActionTarget::HAS_ROLES) {
            $roleUsers = static::getModelsAsRole($custom_value);
            foreach ($roleUsers as $roleUser) {
                $result[] = $roleUser;
            }
        } else {
            $custom_table = $custom_value->custom_table;
            $custom_column = CustomColumn::getEloquent($column, $custom_table);
            
            if (!isset($custom_column)) {
                return [];
            }
    
            // get target's value
            $target_value = $custom_value->getValue($custom_column);
    
            if (!isset($target_value)) {
                return [];
            }
    
            if (!is_list($target_value)) {
                $target_value = [$target_value];
            }
    
            foreach ($target_value as $v) {
                if (!isset($v)) {
                    continue;
                }

                // if email, return as only email
                if ($custom_column->column_type == ColumnType::EMAIL) {
                    $result[] = static::getModelAsEmail($v);
                }

                // if select table is organization
                elseif ($custom_column->column_type == ColumnType::ORGANIZATION) {
                    collect(static::getModelAsOrganization($v, $custom_column))->each(function($item) use(&$result){
                        $result[] = $item;
                    });
                }

                // if select table is user
                elseif ($custom_column->column_type == ColumnType::USER) {
                    $result[] = static::getModelAsUser($v, $custom_column);
                }
                
                // if select table(cotains user)
                elseif (ColumnType::isSelectTable($custom_column->column_type)) {
                    $result[] = static::getModelAsSelectTable($user, NotifyTargetType::EMAIL_COLUMN, $custom_column);
                }
            }
        }

        return array_filter($result);
    }

    /**
     * get model as email
     *
     * @param string $email
     * @return NotifyTarget
     */
    protected static function getModelAsEmail($email)
    {
        $notifyTarget = new self;

        $notifyTarget->email = $email;
       //$notifyTarget->userCode = $email;
        $notifyTarget->name = $email;
        $notifyTarget->notifyKey = $email;
        $notifyTarget->joinName = false;

        return $notifyTarget;
    }
    
    /**
     * get model as SelectTable(user, organization, select table)
     *
     * @param CustomValue $target_value
     * @param string $notify_target
     * @return NotifyTarget|null
     */
    public static function getModelAsSelectTable($target_value, $notify_target, ?CustomColumn $custom_column = null) : ?NotifyTarget
    {
        if (!isset($target_value)) {
            return null;
        }

        // get 'slack_id' custom column
        $slack_id_column = System::system_slack_user_column();
        $slack_id_column = CustomColumn::getEloquent($slack_id_column, SystemTableName::USER);
        if (isset($slack_id_column)) {
            $slack_id = $target_value->getValue($slack_id_column);
        }
        
        $label = $target_value->getLabel();

        $notifyTarget = new self;
        $notifyTarget->targetType = $notify_target;
        $notifyTarget->targetValue = $target_value;
        $notifyTarget->customColumn = $custom_column;
        $notifyTarget->id = $target_value->id;
        $notifyTarget->email = $target_value->getValue($custom_column, true);
        $notifyTarget->name = $label;
        $notifyTarget->notifyKey = $target_value->custom_table->id . '_' . $target_value->id;
        $notifyTarget->joinname = true;
        $notifyTarget->slack_id = $slack_id ?? null;

        return $notifyTarget;
    }

    public static function getModelAsUser($target_value, $custom_column = null) : ?NotifyTarget
    {
        if(is_null($custom_column)){
            $custom_column = CustomColumn::getEloquent('email', SystemTableName::USER);
        }
        return static::getModelAsSelectTable($target_value, NotifyTargetType::USER, $custom_column);
    }
    
    public static function getModelsAsOrganization($target_value, $custom_column = null) : Collection
    {
        // get organization user
        $result = collect();
        foreach ($target_value->users as $user) {
            // get email address
            $item = static::getModelAsUser($user);
            if (!is_nullorempty($item)) {
                $result->push($item);
            }
        }

        return $result;
    }
    
    /**
     * get models as role
     *
     * @param string $email
     * @return NotifyTarget
     */
    protected static function getModelsAsRole($custom_value) : Collection
    {
        $items = AuthUserOrgHelper::getRoleUserAndOrganizations($custom_value, Permission::AVAILABLE_ACCESS_CUSTOM_VALUE);
        
        $list = collect();
        foreach([SystemTableName::USER, SystemTableName::ORGANIZATION] as $key){
            $values = array_get($items, $key);
            
            foreach ($values as $value) {
                $func = NotifyTargetType::getNotifyFuncByTable($key);
                \Exment::pushCollection($list, static::{$func}($value));
            }
        }
        
        return $list->filter()->unique();
    }

    public static function getSelectedNotifyTarget($select_target, Notify $notify, CustomValue $custom_value)
    {
        // all target users
        $allUsers = collect();
        foreach($notify->action_settings as $action_setting){
            $allUsers = $allUsers->merge($notify->getNotifyTargetUsers($custom_value, $action_setting));
        }
        $user = collect($allUsers)->first(function ($user) use ($select_target) {
            return $user->notifyKey == $select_target;
        });
        return $user;
    }

    public static function getSelectedNotifyTargets($select_targets, Notify $notify, CustomValue $custom_value)
    {
        // all target users
        $allUsers = collect();
        foreach($notify->action_settings as $action_setting){
            $allUsers = $allUsers->merge($notify->getNotifyTargetUsers($custom_value, $action_setting));
        }

        $users = collect($allUsers)->filter(function ($user) use ($select_targets) {
            return in_array($user->notifyKey, $select_targets);
        })->toArray();

        return $users;
    }
}
