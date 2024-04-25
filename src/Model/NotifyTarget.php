<?php

namespace Exceedone\Exment\Model;

use Illuminate\Support\Collection;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\NotifyTargetType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Services\Notify\NotifyTargetBase;

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
     * Notify Target Type
     *
     * @var string
     */
    protected $targetType;

    /**
     * Email Address
     *
     * @var string|null
     */
    protected $email;

    /**
     * User name
     *
     * @var string
     */
    protected $name;

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
        if (isset($this->email)) {
            return "{$this->name} <{$this->email}>";
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
     * return user id
     *
     * @return string|null
     */
    public function getUserId()
    {
        return $this->id;
    }

    /**
     * Get notify target models
     *
     * @param Notify $notify
     * @param CustomValue|null $custom_value
     * @param string|CustomColumn $notify_action_target NotifyActionTarget or custom column id or CustomColumn.
     * @param array $action_setting
     * @param CustomTable|null $custom_table
     * @return Collection|\Tightenco\Collect\Support\Collection
     */
    public static function getModels(Notify $notify, ?CustomValue $custom_value, $notify_action_target, array $action_setting, ?CustomTable $custom_table = null)
    {
        $notifyTarget = NotifyTargetBase::make($notify_action_target, $notify, $action_setting);
        if (!$notifyTarget) {
            return collect();
        }

        return $notifyTarget->getModels($custom_value, $custom_table);
    }

    /**
     * get model as email
     *
     * @param string $email
     * @return NotifyTarget
     */
    public static function getModelAsEmail($email)
    {
        $notifyTarget = new self();

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
     * @param CustomValue|null $target_value
     * @param string $notify_target
     * @return NotifyTarget|null
     */
    public static function getModelAsSelectTable(?CustomValue $target_value, string $notify_target, ?CustomColumn $custom_column = null): ?NotifyTarget
    {
        if (is_nullorempty($target_value)) {
            return null;
        }

        // get 'slack_id' custom column
        $slack_id_column = System::system_slack_user_column();
        $slack_id_column = CustomColumn::getEloquent($slack_id_column, SystemTableName::USER);
        if (!is_nullorempty($slack_id_column)) {
            $slack_id = $target_value->getValue($slack_id_column);
        }

        $label = $target_value->getLabel();

        $notifyTarget = new self();
        $notifyTarget->targetType = $notify_target;
        $notifyTarget->targetValue = $target_value;
        $notifyTarget->customColumn = $custom_column;
        $notifyTarget->id = $target_value->id;
        $notifyTarget->email = $target_value->getValue($custom_column, true);
        $notifyTarget->name = $label;
        $notifyTarget->notifyKey = $target_value->custom_table->id . '_' . $target_value->id;
        $notifyTarget->joinName = true;
        $notifyTarget->slack_id = $slack_id ?? null;

        return $notifyTarget;
    }

    public static function getModelAsUser(?CustomValue $target_value, ?CustomColumn $custom_column = null): ?NotifyTarget
    {
        if (is_null($custom_column)) {
            $custom_column = CustomColumn::getEloquent('email', SystemTableName::USER);
        }
        return NotifyTarget::getModelAsSelectTable($target_value, NotifyTargetType::USER, $custom_column);
    }

    public static function getModelsAsOrganization(?CustomValue $target_value, ?CustomColumn $custom_column = null): Collection
    {
        // get organization user
        $result = collect();
        foreach ($target_value->users as $user) {
            // get email address
            $item = NotifyTarget::getModelAsUser($user);
            if (!is_nullorempty($item)) {
                $result->push($item);
            }
        }

        return $result;
    }

    /**
     * get models as role
     *
     * @param CustomValue $custom_value
     * @return Collection
     */
    public static function getModelsAsRole(?CustomValue $custom_value, ?CustomTable $custom_table = null): Collection
    {
        $items = AuthUserOrgHelper::getRoleUserAndOrganizations($custom_value, Permission::AVAILABLE_ALL_CUSTOM_VALUE, $custom_table);

        $list = collect();
        foreach ([SystemTableName::USER, SystemTableName::ORGANIZATION] as $key) {
            $values = array_get($items, $key);

            foreach ($values as $value) {
                $func = NotifyTargetType::getNotifyFuncByTable($key);
                \Exment::pushCollection($list, static::{$func}($value));
            }
        }

        return $list->filter()->unique();
    }

    public static function getSelectedNotifyTarget($select_target, Notify $notify, ?CustomValue $custom_value)
    {
        // all target users
        $allUsers = collect();
        foreach ($notify->action_settings as $action_setting) {
            $allUsers = $allUsers->merge($notify->getNotifyTargetUsers($custom_value, $action_setting));
        }
        $user = collect($allUsers)->first(function ($user) use ($select_target) {
            return $user->notifyKey == $select_target;
        });
        return $user;
    }

    public static function getSelectedNotifyTargets($select_targets, Notify $notify, ?CustomValue $custom_value)
    {
        // all target users
        $allUsers = collect();
        foreach ($notify->action_settings as $action_setting) {
            $allUsers = $allUsers->merge($notify->getNotifyTargetUsers($custom_value, $action_setting));
        }

        $users = collect($allUsers)->filter(function ($user) use ($select_targets) {
            return in_array($user->notifyKey, $select_targets);
        })->toArray();

        return $users;
    }
}
