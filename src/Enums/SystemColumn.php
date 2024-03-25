<?php

namespace Exceedone\Exment\Enums;

/**
 * System column info.
 *
 * @method static SystemColumn ID()
 * @method static SystemColumn SUUID()
 * @method static SystemColumn PARENT_ID()
 * @method static SystemColumn PARENT_TYPE()
 * @method static SystemColumn CREATED_AT()
 * @method static SystemColumn UPDATED_AT()
 * @method static SystemColumn DELETED_AT()
 * @method static SystemColumn CREATED_USER()
 * @method static SystemColumn UPDATED_USER()
 * @method static SystemColumn DELETED_USER()
 * @method static SystemColumn WORKFLOW_STATUS()
 * @method static SystemColumn WORKFLOW_WORK_USERS()
 */
class SystemColumn extends EnumBase
{
    use EnumOptionTrait;

    public const ID = 'id';
    public const SUUID = 'suuid';
    public const PARENT_ID = 'parent_id';
    public const PARENT_TYPE = 'parent_type';
    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = 'updated_at';
    public const DELETED_AT = 'deleted_at';
    public const CREATED_USER = 'created_user';
    public const UPDATED_USER = 'updated_user';
    public const DELETED_USER = 'deleted_user';
    public const WORKFLOW_STATUS = 'workflow_status';
    public const WORKFLOW_WORK_USERS = 'workflow_work_users';

    /**
     * We should use `const OPTIONS` instead of `protected static $options`.
     *
     * @var array[]
     */
    protected static $options = [
        'id' => ['id' => 1, 'name' => 'id', 'sqlname' => 'id', 'default' => true, 'order' => 1, 'header' => true, 'summary' => true, 'min_width' => 30, 'max_width' => 100, 'grid_filter' => true, 'grid_filter_system' => true],
        'suuid' => ['id' => 2, 'name' => 'suuid', 'sqlname' => 'suuid', 'default' => false, 'order' => 2, 'header' => true, 'min_width' => 100, 'max_width' => 300],
        'parent_id' => ['id' => 3, 'name' => 'parent_id', 'sqlname' => 'parent_id', 'default' => false, 'order' => 3, 'min_width' => 100, 'max_width' => 300],
        'parent_type' => ['id' => 4, 'name' => 'parent_type', 'sqlname' => 'parent_type', 'default' => false, 'order' => 4, 'min_width' => 100, 'max_width' => 300],
        'created_at' => ['id' => 96, 'type' => 'datetime', 'name' => 'created_at', 'sqlname' => 'created_at', 'default' => true, 'order' => 81, 'footer' => true, 'summary' => true, 'min_width' => 100, 'max_width' => 300, 'grid_filter' => true, 'grid_filter_system' => true],
        'updated_at' => ['id' => 97, 'type' => 'datetime', 'name' => 'updated_at', 'sqlname' => 'updated_at', 'default' => true, 'order' => 82, 'footer' => true, 'summary' => true, 'min_width' => 100, 'max_width' => 300, 'grid_filter' => true, 'grid_filter_system' => true],
        'deleted_at' => ['id' => 101, 'type' => 'datetime', 'name' => 'deleted_at', 'sqlname' => 'deleted_at', 'default' => false, 'order' => 83, 'min_width' => 100, 'max_width' => 300],
        'created_user' => ['id' => 98, 'type' => 'user', 'name' => 'created_user', 'sqlname' => 'created_user_id', 'tagname' => 'created_user_tag', 'avatarname' => 'created_user_avatar', 'default' => false, 'order' => 91, 'footer' => true, 'min_width' => 100, 'max_width' => 300],
        'updated_user' => ['id' => 99, 'type' => 'user', 'name' => 'updated_user', 'sqlname' => 'updated_user_id', 'tagname' => 'updated_user_tag', 'avatarname' => 'updated_user_avatar', 'default' => false, 'order' => 92, 'footer' => true, 'min_width' => 100, 'max_width' => 300],
        'deleted_user' => ['id' => 102, 'type' => 'user', 'name' => 'deleted_user', 'sqlname' => 'deleted_user_id', 'tagname' => 'deleted_user_tag', 'avatarname' => 'deleted_user_avatar', 'default' => false, 'order' => 93, 'min_width' => 100, 'max_width' => 300],
        'workflow_status' => ['id' => 201, 'type' => 'workflow', 'name' => 'workflow_status', 'tagname' => 'workflow_status_tag', 'sqlname' => 'workflow_status_to_id', 'default' => false, 'grid_filter' => true, 'grid_filter_system' => false],
        'workflow_work_users' => ['id' => 202, 'name' => 'workflow_work_users', 'tagname' => 'workflow_work_users_tag', 'default' => false, 'grid_filter' => true, 'grid_filter_system' => false],
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

    public static function getEnum($value, $default = null, $include_id = true)
    {
        $enum = parent::getEnum($value, $default);
        if (isset($enum)) {
            return $enum;
        }

        if ($include_id) {
            foreach (self::$options as $key => $v) {
                if (array_get($v, 'id') == $value) {
                    return parent::getEnum($key);
                }
            }
        }
        return $default;
    }

    public static function isSqlValid($value)
    {
        foreach (self::$options as $key => $v) {
            if (array_get($v, 'sqlname') == $value) {
                return true;
            }
        }
        return false;
    }
}
