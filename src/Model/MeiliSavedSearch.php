<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\SystemTableName;

/**
 * Saved Search for global search: stores keyword + filter (generic JSON params:
 * tables/date_from/date_to/users/facets/range) per user, and can be
 * shared with everyone / a role group / an organization.
 *
 * Does NOT hardcode business table/column names — filters are only generic
 * parameters of the search screen, so it works for any Exment project.
 *
 * @property mixed $name
 * @property mixed $owner_user_id
 * @property mixed $query
 * @property mixed $filters
 * @property mixed $share_type
 * @property mixed $share_targets
 */
class MeiliSavedSearch extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    public const SHARE_PERSONAL = 'personal';
    public const SHARE_ALL = 'all';
    public const SHARE_ROLE_GROUP = 'role_group';
    public const SHARE_ORGANIZATION = 'organization';

    protected $fillable = ['name', 'owner_user_id', 'query', 'filters', 'share_type', 'share_targets', 'order'];

    protected $casts = ['filters' => 'array', 'share_targets' => 'array', 'order' => 'integer'];

    /**
     * Whether the user can see this saved search.
     *
     * Duck-typed on purpose: the unit tests pass a plain stdClass so the share
     * rules can be exercised without touching the database.
     *
     * @param self|object{owner_user_id:int|string,share_type:?string,share_targets?:mixed} $record
     * @param int                  $userId        user id (CustomValue user)
     * @param array<int,int|string> $roleGroupIds role group ids the user belongs to
     * @param array<int,int|string> $orgIds       organization ids the user belongs to
     */
    public static function visibleToUser($record, int $userId, array $roleGroupIds, array $orgIds): bool
    {
        if ((int) $record->owner_user_id === $userId) {
            return true;
        }

        $targets = array_map('intval', (array) ($record->share_targets ?? []));

        switch ($record->share_type) {
            case self::SHARE_ALL:
                return true;
            case self::SHARE_ROLE_GROUP:
                return !empty(array_intersect($targets, array_map('intval', $roleGroupIds)));
            case self::SHARE_ORGANIZATION:
                return !empty(array_intersect($targets, array_map('intval', $orgIds)));
            default: // personal
                return false;
        }
    }

    /**
     * List of saved searches the current user can see (own + shared).
     *
     * @return \Illuminate\Support\Collection<int,self>
     */
    public static function listForCurrentUser()
    {
        $user = \Exment::user();
        if (!$user) {
            return collect();
        }
        $userId = (int) $user->getUserId();

        try {
            $base = $user->base_user;
            $roleGroupIds = $base ? $base->belong_role_groups_all()->pluck('id')->all() : [];
            // Same organization hierarchy rule as shared views and dashboards.
            $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
            $orgIds = $base ? (array) $base->getOrganizationIdsForQuery($enum) : [];
        } catch (\Throwable $e) {
            $roleGroupIds = [];
            $orgIds = [];
        }

        // Constrain in SQL first (own rows + anything non-personal) so this
        // never hydrates every user's personal searches; the PHP pass then
        // matches role-group/organization share targets precisely.
        $rows = static::where(function ($query) use ($userId) {
            $query->where('owner_user_id', $userId)
                    ->orWhere('share_type', '!=', self::SHARE_PERSONAL);
        })
            ->orderBy('order')->orderBy('id')->get();

        // A deleted owner's shares stop showing (kept stored: a soft delete can be restored).
        $alive = static::existingUserIds($rows->pluck('owner_user_id')->unique()->all());

        return $rows
            ->filter(fn ($r) => isset($alive[(int) $r->owner_user_id]) && static::visibleToUser($r, $userId, $roleGroupIds, $orgIds))
            ->values();
    }

    /**
     * @param array<int,mixed> $userIds
     * @return array<int,int>  existing (not deleted) user id => index
     */
    protected static function existingUserIds(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }
        return array_flip(getModelName(SystemTableName::USER)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->whereIn('id', $userIds)
            ->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * A user deleted for good takes their saved searches along.
     *
     * @param mixed $model
     */
    public static function deleteOwnedBy($model): void
    {
        if (!($model instanceof CustomValue) || !$model->isForceDeleting() || $model->custom_table->table_name !== SystemTableName::USER) {
            return;
        }
        try {
            static::where('owner_user_id', $model->id)->delete();
        } catch (\Throwable $e) {
            // Before migration the table does not exist: nothing to clean.
        }
    }

    /**
     * Whether the user can edit/delete: the owner or a system administrator.
     */
    public function canManage(): bool
    {
        $user = \Exment::user();
        if (!$user) {
            return false;
        }
        if ((int) $this->owner_user_id === (int) $user->getUserId()) {
            return true;
        }

        try {
            return $user->isAdministrator();
        } catch (\Throwable $e) {
            return false;
        }
    }
}
