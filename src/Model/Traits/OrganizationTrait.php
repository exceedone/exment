<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use ExmentAdminCore\Admin\Traits\ModelTree;
use ExmentAdminCore\Admin\Traits\AdminBuilder;

/**
 * @uses \Exceedone\Exment\Services\ClassBuilder
 */
trait OrganizationTrait
{
    use AdminBuilder, ModelTree {
        ModelTree::boot as treeBoot;
    }
    // organizations, their hierarchy and membership feed the cached permissions
    use ClearCacheTrait;

    /**
     * Clear cache after saving/deleting an organization (called from ModelBase events).
     * The membership pivot and parent_organization decide the users in the accessible user/organization
     * caches, and the form syncs the pivot after the model is saved, so clear after the commit.
     *
     * @return void
     */
    public static function clearCacheTrait()
    {
        System::clearCacheAfterCommit();
    }

    /**
     * get parent organization.
     * (*)Only one deeply parent. not all deeply parents.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<static, $this>
     */
    public function parent_organization()
    {
        return $this->belongsTo(static::class, static::getParentOrgIndexName());
    }

    /**
     * get children organizations.
     * (*)Only one deeply organizations. not all deeply organizations.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<static, $this>
     */
    public function children_organizations()
    {
        return $this->hasMany(static::class, static::getParentOrgIndexName());
    }

    public function hasChildren()
    {
        return count($this->children_organizations) > 0;
    }

    public function hasParent()
    {
        return isset($this->parent_organization);
    }

    public function users()
    {
        $db_table_name_pivot = CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER);
        return $this->belongsToMany(getModelName(SystemTableName::USER), $db_table_name_pivot, "parent_id", "child_id");
    }

    public static function getParentOrgIndexName()
    {
        return CustomColumn::getEloquent('parent_organization', new static())->getIndexColumnName();
    }

    /**
     * Get avatar
     *
     * @return void
     */
    public function getDisplayAvatarAttribute()
    {
        // get default avatar
        return asset(Define::ORGANIZATION_IMAGE_LINK);
    }

    /**
     * get all deeply parent organizations.
     * (*) not contains this.
     *
     * @return array all_parent_organizations (as array)
     */
    public function all_parent_organizations()
    {
        $organizations = [];
        $deeps = intval(config('exment.organization_deeps', 4));
        $target = $this;
        for ($i = 0 ; $i < $deeps; $i++) {
            if (!isset($target->parent_organization)) {
                break;
            }
            $organizations[] = $target->parent_organization;
            $target = $target->parent_organization;
        }

        return $organizations;
    }

    /**
     * get all deeply children organizations.
     * (*) not contains this.
     *
     * @return array all_children_organizations (as array)
     */
    public function all_children_organizations()
    {
        $organizations = [];
        $deeps = intval(config('exment.organization_deeps', 4));
        static::setChildrenOrganizations(1, $this, $organizations);

        return $organizations;
    }

    /**
     * Get Parent and Children Ids
     *
     * IMPORTANT: Please look this topic.
     * https://exment.net/docs/#/ja/developing_memo
     *
     * @param string $joinedOrgFilterType
     * @return array
     */
    public function getOrganizationIdsForQuery($filterType = JoinedOrgFilterType::ALL)
    {
        return System::requestSession(sprintf(Define::SYSTEM_KEY_SESSION_ORGANIZATION_IDS_ORG, $this->id, $filterType), function () use ($filterType) {
            // only the ids are needed: they are read from the parents of all organizations, kept
            // for the request. (The relations read the children of each organization with a query
            // of its own: 253 queries on a form of a site with 253 organizations, GitHub #1346.)
            $parents = static::parentIdsOfOrganizations();
            $deeps = intval(config('exment.organization_deeps', 4));
            $ids = [];
            if (JoinedOrgFilterType::isGetUpper($filterType)) {
                $children = [];
                foreach ($parents as $id => $parentId) {
                    if (isset($parentId)) {
                        $children[$parentId][] = $id;
                    }
                }
                // the same order and the same depth as all_children_organizations()
                $walk = function ($id, $deep) use (&$walk, &$ids, $children, $deeps) {
                    if ($deep > $deeps) {
                        return;
                    }
                    foreach ($children[$id] ?? [] as $childId) {
                        $ids[] = $childId;
                        $walk($childId, $deep + 1);
                    }
                };
                $walk($this->id, 1);
            }
            if (JoinedOrgFilterType::isGetDowner($filterType)) {
                // the same as all_parent_organizations()
                $targetId = $this->id;
                for ($i = 0; $i < $deeps; $i++) {
                    $parentId = $parents[$targetId] ?? null;
                    if (!isset($parentId) || !array_key_exists($parentId, $parents)) {
                        break;
                    }
                    $ids[] = $parentId;
                    $targetId = $parentId;
                }
            }

            $ids[] = $this->id;

            return collect($ids)->sort()->unique()->toArray();
        });
    }

    /**
     * The parent of every organization the query of the model gives (id => id of the parent,
     * or null), read once for the request. Saving or deleting an organization clears it
     * (System::clearCache, see clearCacheTrait).
     *
     * @return array<int, int|null>
     */
    protected static function parentIdsOfOrganizations(): array
    {
        return System::requestSession(Define::SYSTEM_KEY_SESSION_ORGANIZATION_PARENT_IDS, function () {
            $indexName = static::getParentOrgIndexName();
            $parents = [];
            foreach (static::query()->orderBy('id')->toBase()->get(['id', $indexName]) as $row) {
                $parentId = $row->{$indexName};
                $parents[intval($row->id)] = is_numeric($parentId) ? intval($parentId) : null;
            }
            return $parents;
        });
    }

    protected static function setChildrenOrganizations($deep, $target, &$organizations)
    {
        $deeps = intval(config('exment.organization_deeps', 4));

        if (!isset($target->children_organizations) || count($target->children_organizations) == 0) {
            return;
        }
        if ($deep > $deeps) {
            return;
        }
        $deep++;
        foreach ($target->children_organizations as $children_organization) {
            $organizations[] = $children_organization;
            static::setChildrenOrganizations($deep, $children_organization, $organizations);
        }
    }

    /**
     * get role_group user or org joined.
     *
     * @return void
     */
    public function belong_role_groups()
    {
        return RoleGroup::whereHas('role_group_organizations', function ($query) {
            $query->where('role_group_target_id', $this->id);
        })->get();
    }
}
