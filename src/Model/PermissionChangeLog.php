<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * A change of a role group (ISS02840): a permission or a member added or removed, a group made
 * or deleted. Written by Services\LogManagement\PermissionChanges; read by the IT audit report.
 *
 * @property int $id
 * @property int|null $role_group_id
 * @property string|null $role_group_name
 * @property string $change
 * @property string $kind
 * @property string|null $role_type
 * @property string|null $target_type
 * @property int|null $target_id
 * @property string|null $permission
 * @property int|null $changed_user_id
 * @property string|null $source
 * @property \Carbon\Carbon|null $created_at
 */
class PermissionChangeLog extends Model
{
    public const CHANGE_ADDED = 'added';
    public const CHANGE_REMOVED = 'removed';

    public const KIND_GROUP = 'group';
    public const KIND_PERMISSION = 'permission';
    public const KIND_MEMBER = 'member';

    protected $table = 'permission_change_logs';

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'role_group_id' => 'integer',
        'target_id' => 'integer',
        'changed_user_id' => 'integer',
        'created_at' => 'datetime',
    ];

    /** @var bool|null whether the table is there (a site not migrated yet) */
    protected static $tableExists;

    public static function tableExists(): bool
    {
        if (!isset(static::$tableExists)) {
            try {
                static::$tableExists = Schema::hasTable('permission_change_logs');
            } catch (\Throwable $e) {
                static::$tableExists = false;
            }
        }
        return static::$tableExists;
    }

    /** Forget whether the table is there (tests, the migration). */
    public static function resetTableExists(): void
    {
        static::$tableExists = null;
    }
}
