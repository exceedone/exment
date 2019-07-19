<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Collection;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\NotifyActionTarget;

/**
 * get and set notify target
 * CAUTION: this is not eloquent model
 */
class NotifyTarget
{
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
    protected $userName;

    /**
     * notify key
     *
     * @var string
     */
    protected $notifyKey;

    /**
     * whether joins user name
     *
     * @var string
     */
    protected $joinUserName;
    
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
    
    public function getLabel()
    {
        return $this->joinUserName ? "{$this->userName} <{$this->email}>" : $this->email;
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
        if ($column == NotifyActionTarget::HAS_ROLES) {
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
    
            if (!is_array($target_value)) {
                $target_value = [$target_value];
            }
    
            foreach ($target_value as $v) {
                if (!isset($v)) {
                    continue;
                }

                // if email, return as only email
                if ($custom_column->column_type == ColumnType::EMAIL) {
                    $result[] =  static::getModelAsEmail($v);
                }
                // if select table is organization
                elseif ($custom_column->column_type == ColumnType::ORGANIZATION) {
                    // get organization user
                    foreach ($v->users as $user) {
                        // get email address
                        $item = static::getModelAsSelectTable($user);
                        if (isset($item)) {
                            $result[] = $item;
                        }
                    }
                }
                // if select table(cotains user)
                elseif (ColumnType::COLUMN_TYPE_SELECT_TABLE($custom_column->column_type)) {
                    // get email address
                    $item = static::getModelAsSelectTable($v, null, $custom_column);
                    if (isset($item)) {
                        $result[] = $item;
                    }
                }
            }
        }

        return $result;
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
        $notifyTarget->userName = $email;
        $notifyTarget->notifyKey = $email;
        $notifyTarget->joinUserName = false;

        return $notifyTarget;
    }
    
    /**
     * get model as SelectTable(user, select table)
     *
     * @param [type] $custom_value
     * @return NotifyTarget
     */
    protected static function getModelAsSelectTable($target_value, $email_column = null, $custom_column = null)
    {
        if (!isset($target_value)) {
            return null;
        }

        if (!isset($email_column)) {
            if (isset($custom_column)) {
                $select_target_table = $custom_column->select_target_table;
            } else {
                $select_target_table = $target_value->custom_table;
            }
            // get email address
            $email_column = $select_target_table->custom_columns()->where('column_type', ColumnType::EMAIL)->first();
        }
        
        $email = $target_value->getValue($email_column);
        if (empty($email)) {
            return null;
        }
        
        $label = $target_value->getLabel();

        $notifyTarget = new self;
        $notifyTarget->email = $email;
        $notifyTarget->id = $target_value->id;
        $notifyTarget->userName = $label;
        $notifyTarget->notifyKey = $target_value->custom_table->id . '_' . $target_value->id;
        $notifyTarget->joinUserName = true;

        return $notifyTarget;
    }
    
    /**
     * get models as role
     *
     * @param string $email
     * @return NotifyTarget
     */
    protected static function getModelsAsRole($custom_value)
    {
        $users = AuthUserOrgHelper::getRoleUserQueryValue($custom_value)->get();

        // get 'email' custom column
        $email_column = CustomColumn::getEloquent('email', SystemTableName::USER);

        $list = [];
        foreach ($users as $user) {
            $item = static::getModelAsSelectTable($user, $email_column, null);
            if (isset($item)) {
                $list[] = $item;
            }
        }

        return collect($list);
    }

    public static function getSelectedNotifyTarget($select_target, Notify $notify, CustomValue $custom_value)
    {
        // all target users
        $allUsers = $notify->getNotifyTargetUsers($custom_value);

        $user = collect($allUsers)->first(function ($user) use ($select_target) {
            return $user->notifyKey == $select_target;
        });
        return $user;
    }
}
