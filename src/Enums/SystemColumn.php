<?php

namespace Exceedone\Exment\Enums;

class SystemColumn extends EnumBase
{
    use EnumOptionTrait;

    const ID = 'id';
    const SUUID = 'suuid';
    const PARENT_ID = 'parent_id';
    const PARENT_TYPE = 'parent_type';
    const CREATED_AT = 'created_at';
    const UPDATED_AT = 'updated_at';
    const DELETED_AT = 'deleted_at';
    const CREATED_USER = 'created_user';
    const UPDATED_USER = 'updated_user';
    const DELETED_USER = 'deleted_user';
    const WORKFLOW_STATUS = 'workflow_status';
    const WORKFLOW_WORK_USERS = 'workflow_work_users';
    
    protected static $options = [
        'id' => ['id' => 1, 'name' => 'id', 'sqlname' => 'id', 'default' => true, 'order' => 1, 'header' => true, 'summary' => true, 'min_width' => 30, 'max_width' => 100],
        'suuid' => ['id' => 2, 'name' => 'suuid', 'sqlname' => 'suuid', 'default' => false, 'order' => 2, 'header' => true, 'min_width' => 100, 'max_width' => 300],
        'parent_id' => ['id' => 3, 'name' => 'parent_id', 'sqlname' => 'parent_id', 'default' => false, 'order' => 3, 'min_width' => 100, 'max_width' => 300],
        'parent_type' => ['id' => 4, 'name' => 'parent_type', 'sqlname' => 'parent_type', 'default' => false, 'order' => 4, 'min_width' => 100, 'max_width' => 300],
        'created_at' => ['id' => 96, 'type' => 'datetime', 'name' => 'created_at', 'sqlname' => 'created_at', 'default' => true, 'order' => 81, 'footer' => true, 'summary' => true, 'min_width' => 100, 'max_width' => 300],
        'updated_at' => ['id' => 97, 'type' => 'datetime', 'name' => 'updated_at', 'sqlname' => 'updated_at', 'default' => true, 'order' => 82, 'footer' => true, 'summary' => true, 'min_width' => 100, 'max_width' => 300],
        'deleted_at' => ['id' => 101, 'type' => 'datetime', 'name' => 'deleted_at', 'sqlname' => 'deleted_at', 'default' => false, 'order' => 83, 'min_width' => 100, 'max_width' => 300],
        'created_user' => ['id' => 98, 'type' => 'user', 'name' => 'created_user', 'sqlname' => 'created_user_id', 'tagname' => 'created_user_tag', 'avatarname' => 'created_user_avatar', 'default' => false, 'order' => 91, 'footer' => true, 'min_width' => 100, 'max_width' => 300],
        'updated_user' => ['id' => 99, 'type' => 'user', 'name' => 'updated_user', 'sqlname' => 'updated_user_id', 'tagname' => 'updated_user_tag', 'avatarname' => 'updated_user_avatar', 'default' => false, 'order' => 92, 'footer' => true, 'min_width' => 100, 'max_width' => 300],
        'deleted_user' => ['id' => 102, 'type' => 'user', 'name' => 'deleted_user', 'sqlname' => 'deleted_user_id', 'tagname' => 'deleted_user_tag', 'default' => false, 'order' => 93, 'min_width' => 100, 'max_width' => 300],
        'workflow_status' => ['id' => 201, 'type' => 'workflow', 'name' => 'workflow_status', 'tagname' => 'workflow_status_tag', 'sqlname' => 'workflow_status_to_id', 'default' => false],
        'workflow_work_users' => ['id' => 202, 'name' => 'workflow_work_users', 'tagname' => 'workflow_work_users_tag', 'sqlname' => '', 'default' => false],
    ];

    public function id()
    {
        return array_get($this->option(), 'id');
    }

    public function name()
    {
        return $this->lowerKey();
    }

    public static function isWorkflow($key)
    {
        return in_array($key, [static::WORKFLOW_STATUS, static::WORKFLOW_WORK_USERS]);
    }
    
    public static function getEnum($value, $default = null)
    {
        $enum = parent::getEnum($value, $default);
        if (isset($enum)) {
            return $enum;
        }

        foreach (self::$options as $key => $v) {
            if (array_get($v, 'id') == $value) {
                return parent::getEnum($key);
            }
        }
        return $default;
    }
}
