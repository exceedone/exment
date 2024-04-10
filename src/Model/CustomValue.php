<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\AbstractPaginator;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Collection;
use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ValueType;
use Exceedone\Exment\Enums\FormActionType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\ShareTrigger;
use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\PluginEventType;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Services\AuthUserOrgHelper;

/**
 * @property-read string $display_avatar
 * @phpstan-consistent-constructor
 * @property mixed $users
 * @property mixed $workflow_values
 * @property mixed $workflow_value
 * @property mixed $value
 * @property mixed $belong_role_groups
 * @property mixed $belong_organizations
 * @property mixed $titleColumn
 * @property mixed $revisionFormattedFields
 * @property mixed $revisionFormattedFieldNames
 * @property mixed $parent_type
 * @property mixed $parent_id
 * @property mixed $parentColumn
 * @property mixed $orderColumn
 * @property mixed $dontKeepRevisionOf
 * @property mixed $custom_table_name
 * @property mixed $created_user_id
 * @property mixed $login_user
 * @property mixed $login_users
 * @property mixed $deleted_user_id
 * @property mixed $created_at
 * @property mixed $deleted_at
 * @property mixed $revisionEnabled
 * @method mixed getUserId()
 * @method static ExtendedBuilder withoutGlobalScopes(array $scopes = null)
 * @method static ExtendedBuilder where($column, $operator = null, $value = null, $boolean = 'and')
 */
abstract class CustomValue extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;
    use \Exceedone\Exment\Revisionable\RevisionableTrait;

    protected $casts = ['value' => 'json'];
    // protected $appends = ['label'];
    protected $hidden = ['laravel_admin_escape'];
    protected $keepRevisionOf = ['value'];
    protected $keepRevisionOfTrigger = ['deleted_at' => 'value'];

    /**
     * remove_file_columns.
     * default flow, if file column is empty, set original value.
     */
    protected $remove_file_columns = [];

    /**
     * disabled saving event.
     * if true, disable.
     */
    protected $disable_saving_event = false;

    /**
     * disabled saved event.
     * if true, disable.
     */
    protected $disable_saved_event = false;

    /**
     * set value directly without processing.
     * if true, skip saving event without revision.
     */
    protected $restore_revision = false;

    /**
     * saved notify.
     * if false, don't notify
     */
    protected $saved_notify = true;

    /**
     * already_updated.
     * if true, not call saved event again.
     */
    protected $already_updated = false;

    /**
     * label work.
     * get label only first time.
     */
    protected $_label;

    /**
     * file uuids.
     * *NOW only use edtitor images
     */
    protected $file_uuids = [];

    /**
     * result validate destroy.
     * if true, pass validate destroy
     */
    protected $validation_destroy = false;

    /**
     * Create a new Eloquent model instance.
     *
     * @param  array  $attributes
     * @return void
     */
    public function __construct(array $attributes = [])
    {
        // set parent_id for org
        if ($this->custom_table->table_name == SystemTableName::ORGANIZATION) {
            // treeview
            $this->titleColumn = 'label';
            $this->orderColumn = 'id';
            $this->parentColumn = CustomColumn::getEloquent('parent_organization', $this->custom_table)->getIndexColumnName();
        }

        parent::__construct($attributes);
    }

    public function workflow_value()
    {
        return $this->hasOne(WorkflowValue::class, 'morph_id')
            ->where('morph_type', $this->custom_table->table_name)
            ->where('latest_flg', true)
            ->orderBy('updated_at', 'desc')
        ;
    }

    /**
     * Get all workflow values
     */
    public function workflow_values()
    {
        return $this->hasMany(WorkflowValue::class, 'morph_id')
            ->where('morph_type', $this->custom_table->table_name);
    }

    public function getLabelAttribute()
    {
        if (is_null($this->_label)) {
            $this->_label = $this->getLabel();
        }
        return $this->_label;
    }

    public function getCustomTableAttribute()
    {
        // return resuly using cache
        return CustomTable::getEloquent($this->custom_table_name);
    }

    public function getDeletedUserAttribute()
    {
        return $this->getUser('deleted_user_id');
    }
    public function getDeletedUserValueAttribute()
    {
        return $this->getUserValue('deleted_user_id');
    }
    public function getDeletedUserTagAttribute()
    {
        return $this->getUser('deleted_user_id', true);
    }
    public function getDeletedUserAvatarAttribute()
    {
        return $this->getUser('deleted_user_id', true, true);
    }
    public function getValidationDestroy()
    {
        return $this->validation_destroy;
    }
    public function setValidationDestroy($value)
    {
        $this->validation_destroy = $value;
    }

    /**
     * Whether this model disable delete
     *
     * @return boolean
     */
    public function getDisabledDeleteAttribute()
    {
        return $this->enableDelete(true) !== true;
    }

    public function getWorkflowStatusAttribute()
    {
        if (is_null(Workflow::getWorkflowByTable($this->custom_table))) {
            return null;
        }

        return isset($this->workflow_value) ? $this->workflow_value->workflow_status_cache : null;
    }

    public function getWorkflowStatusNameAttribute()
    {
        if (isset($this->workflow_status)) {
            return $this->workflow_status->status_name;
        }

        // get workflow
        $workflow = isset($this->workflow_value) ? $this->workflow_value->workflow_cache : null;
        if (!isset($workflow)) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        }
        if (isset($workflow)) {
            return $workflow->start_status_name;
        }

        return null;
    }

    /**
     * Get workflow status tag. Please escape workflow_status_name
     */
    public function getWorkflowStatusTagAttribute()
    {
        $icon = ' <i class="fa fa-lock" aria-hidden="true" data-toggle="tooltip" title="' . esc_html(exmtrans('workflow.message.locked')) . '"></i>';
        return esc_html($this->workflow_status_name) .
            ($this->lockedWorkflow() ? $icon : '');
    }

    public function getWorkflowWorkUsersAttribute()
    {
        $workflow_actions = $this->getWorkflowActions(false, true);

        $result = collect();
        foreach ($workflow_actions as $workflow_action) {
            $result = \Exment::uniqueCustomValues(
                $result,
                $workflow_action->getAuthorityTargets($this, WorkflowGetAuthorityType::CURRENT_WORK_USER)
            );
        }

        return $result;
    }

    public function getWorkflowWorkUsersTagAttribute()
    {
        $users = $this->workflow_work_users;

        return collect($users)->map(function ($user) {
            if (is_string($user)) {
                return $user;
            }
            return getUserName($user, true, true);
        })->implode('');
    }

    // value_authoritable. it's all role data.
    public function custom_value_authoritables()
    {
        return $this->hasMany(CustomValueAuthoritable::class, 'parent_id')
            ->where('parent_type', $this->custom_table_name);
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_users()
    {
        return $this->morphToMany(getModelName(SystemTableName::USER), 'parent', 'custom_value_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::USER)
        ;
    }

    // user value_authoritable. it's all role data. only filter morph_type
    public function value_authoritable_organizations()
    {
        return $this->morphToMany(getModelName(SystemTableName::ORGANIZATION), 'parent', 'custom_value_authoritables', 'parent_id', 'authoritable_target_id')
            ->withPivot('authoritable_target_id', 'authoritable_user_org_type', 'authoritable_type')
            ->wherePivot('authoritable_user_org_type', SystemTableName::ORGANIZATION)
        ;
    }


    /**
     * Get dynamic relation value for custom value.
     *
     * @param int $custom_relation_id
     * @param boolean $isCallAsParent
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany|\Illuminate\Database\Eloquent\Relations\MorphMany|\Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function getDynamicRelationValue(int $custom_relation_id, bool $isCallAsParent)
    {
        $relation = CustomRelation::getEloquent($custom_relation_id);
        return $relation->getDynamicRelationValue($this, $isCallAsParent);
    }


    // get whether workflow is completed
    public function isWorkflowCompleted()
    {
        $workflow_value = $this->workflow_value;

        // get current status etc
        $workflow_status = isset($workflow_value) ? $workflow_value->workflow_status_cache : null;

        if (isset($workflow_status)) {
            return $workflow_status->completed_flg == 1;
        }

        return false;
    }


    // get workflow actions which has authority
    public function getWorkflowActions($onlyHasAuthority = false, $ignoreNextWork = false)
    {
        $workflow_value = $this->workflow_value;

        // get workflow.
        $workflow = isset($workflow_value) ? $workflow_value->workflow_cache : null;
        if (!isset($workflow)) {
            $workflow = Workflow::getWorkflowByTable($this->custom_table);
        }

        if (!isset($workflow)) {
            return collect();
        }

        // get current status etc
        $workflow_status = isset($workflow_value) ? $workflow_value->workflow_status_cache : null;

        // get matched actions
        $workflow_actions = $workflow
            ->workflow_actions_cache
            ->filter(function ($workflow_action) use ($workflow_status) {
                if (!isset($workflow_status)) {
                    return $workflow_action->status_from == Define::WORKFLOW_START_KEYNAME;
                }
                return $workflow_action->status_from == $workflow_status->id;
            });

        // check authority
        if ($onlyHasAuthority) {
            $workflow_actions = $workflow_actions->filter(function ($workflow_action) {
                // has authority, and has MatchedCondtionHeader.
                return $workflow_action->hasAuthority($this) && !is_null($workflow_action->getMatchedCondtionHeader($this));
            });
        }

        if ($ignoreNextWork) {
            $workflow_actions = $workflow_actions->filter(function ($workflow_action) {
                return !boolval($workflow_action->ignore_work);
            });
        }

        return $workflow_actions;
    }

    /**
     * get workflow histories
     *
     * @return Collection
     */
    public function getWorkflowHistories($appendsStatus = false)
    {
        $workflow_values = WorkflowValue::where('morph_type', $this->custom_table->table_name)
            ->where('morph_id', $this->id)
            ->orderby('workflow_values.created_at', 'desc')
            ->get();


        if (!$appendsStatus) {
            return $workflow_values;
        }

        $results = [];
        foreach ($workflow_values as $v) {
            $v->append('created_user');
            $v->workflow_action->append('status_from_name');
            $v->workflow_action->status_from_to_name = exmtrans('workflow.status_from_to_format', $v->workflow_action_cache->status_from_name, $v->workflow_status_name);

            $results[] = $v->toArray();
        }

        /** @var Collection $collection */
        $collection = collect($results);
        return $collection;
    }

    /**
     * get or set remove_file_columns
     */
    public function remove_file_columns($key = null)
    {
        // get
        if (!isset($key)) {
            return $this->remove_file_columns;
        }

        // set
        $this->remove_file_columns[] = $key;
        return $this;
    }

    /**
     * get or set file_uuids
     */
    public function file_uuids($key = null)
    {
        // get
        if (!isset($key)) {
            return $this->file_uuids;
        }

        // set
        $this->file_uuids[] = $key;
        return $this;
    }

    public function saved_notify($disable_saved_notify)
    {
        $this->saved_notify = $disable_saved_notify;
        return $this;
    }

    public function disable_saving_event($disable_saving_event)
    {
        $this->disable_saving_event = $disable_saving_event;
        return $this;
    }
    public function disable_saved_event($disable_saved_event)
    {
        $this->disable_saved_event = $disable_saved_event;
        return $this;
    }
    public function restore_revision($restore_revision = true)
    {
        $this->restore_revision = $restore_revision;
        return $this;
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            if ($model->disable_saving_event) {
                return;
            }

            if (!$model->restore_revision) {
                $events = $model->exists ? CustomOperationType::UPDATE : CustomOperationType::CREATE;
                // call create or update trigger operations
                CustomOperation::operationExecuteEvent($events, $model);

                // call saving trigger plugins
                Plugin::pluginExecuteEvent(PluginEventTrigger::SAVING, $model->custom_table, [
                    'custom_table' => $model->custom_table,
                    'custom_value' => $model,
                ]);

                // re-get field data --------------------------------------------------
                $model->prepareValue();
            }

            // prepare revision
            $model->preSave();
        });
        static::created(function ($model) {
            $model->savedEvent(true);
        });
        static::updated(function ($model) {
            $model->savedEvent(false);
        });

        static::deleting(function ($model) {
            $deleteForce = boolval(config('exment.delete_force_custom_value', false));

            if ($deleteForce) {
                $model->forceDeleting = true;
            }

            // delete hard
            if ($model->isForceDeleting()) {
                $model->deleteFile();
                $model->deleteRelationValues();
            }
            // Execute only not force deleting
            else {
                // Delete only children.
                $model->deleteChildrenValues();

                $model->deleted_user_id = \Exment::getUserId();

                // saved_notify(as update) disable
                $saved_notify = $model->saved_notify;
                $model->saved_notify = false;
                $model->save();
                $model->saved_notify = $saved_notify;
            }
        });

        static::deleted(function ($model) {
            // call deleted event plugins
            Plugin::pluginExecuteEvent(PluginEventType::DELETED, $model->custom_table, [
                'custom_table' => $model->custom_table,
                'custom_value' => $model,
                'force_delete' => $model->isForceDeleting(),
            ]);

            // Delete file hard delete
            if ($model->isForceDeleting()) {
                // Execute notify if delete_force_custom_value is true
                if ($model->saved_notify && boolval(config('exment.delete_force_custom_value', false))) {
                    $model->notify(NotifySavedType::DELETE);
                }
                $model->postForceDelete();
                return;
            }

            $model->preSave();
            $model->postDelete();

            if ($model->saved_notify) {
                $model->notify(NotifySavedType::DELETE);
            }
        });

        static::restored(function ($model) {
            $model->restoreChildrenValues();
            $model->deleted_user_id = null;

            // saved_notify(as update) disable
            $saved_notify = $model->saved_notify;
            $model->saved_notify = false;
            $model->save();
            $model->saved_notify = $saved_notify;

            $model->postRestore();
        });

        static::addGlobalScope(new CustomValueModelScope());
    }

    /**
     * Call saved event
     *
     * @param bool $isCreate
     * @return void
     */
    protected function savedEvent($isCreate)
    {
        if ($this->disable_saved_event) {
            return;
        }

        // save file value
        $this->setFileValue();

        // call plugins
        Plugin::pluginExecuteEvent(PluginEventTrigger::SAVED, $this->custom_table, [
            'custom_table' => $this->custom_table,
            'custom_value' => $this,
        ]);

        $this->savedValue();

        if ($isCreate) {
            // save Authoritable
            CustomValueAuthoritable::setValueAuthoritable($this);

            // save external Authoritable
            CustomValueAuthoritable::setValueAuthoritableEx($this, ShareTrigger::CREATE);

            // send notify
            $this->notify(NotifySavedType::CREATE);

            // set revision
            $this->postCreate();
        } else {
            // Only call already_updated is false
            if (!$this->already_updated) {
                // save external Authoritable
                CustomValueAuthoritable::setValueAuthoritableEx($this, ShareTrigger::UPDATE);

                // send notify
                $this->notify(NotifySavedType::UPDATE);
            }

            // set revision
            $this->postSave();
        }
    }

    /**
     * Validator before saving.
     * Almost multiple columns validation
     *
     * @param array $input laravel-admin input
     * @return mixed
     */
    public function validateSaving($input, array $options = [])
    {
        $options = array_merge([
            'asApi' => false,
            'appendErrorAllColumn' => true,
            'column_name_prefix' => null,
            'uniqueCheckSiblings' => [], // unique validation Siblings
            'calledType' => null, // Whether this validation is called.
        ], $options);

        // validate multiple column set is unique
        $errors = $this->custom_table->validatorUniques($input, $this, $options);

        $errors = array_merge($this->custom_table->validatorCompareColumns($input, $this, $options), $errors);

        $errors = array_merge($this->custom_table->validatorLock($input, $this, $options['asApi']), $errors);

        // call plugin validator
        $errors = array_merge_recursive($errors, $this->custom_table->validatorPlugin($input, $this, ['called_type' => $options['calledType']]));

        return count($errors) > 0 ? $errors : true;
    }

    // re-set field data --------------------------------------------------
    // if user update form and save, but other field remove if not conatins form field, so re-set field before update
    protected function prepareValue()
    {
        ///// saving event for image, file event
        // https://github.com/z-song/laravel-admin/issues/1024
        // because on value edit display, if before upload file and not upload again, don't post value.
        $value = $this->value;
        $original = json_decode_ex($this->getRawOriginal('value'), true);
        // get  columns
        $custom_columns = $this->custom_table->custom_columns_cache;

        // loop columns
        $update_flg = false;
        foreach ($custom_columns as $custom_column) {
            $column_name = $custom_column->column_name;
            // get saving value
            $v = $custom_column->column_item->setCustomValue($this)->saving();
            // if has value, update
            if (isset($v)) {
                array_set($value, $column_name, $v);
                $update_flg = true;
            }

            if ($this->setAgainOriginalValue($value, $original, $custom_column)) {
                $update_flg = true;
            }
        }

        // array_forget if $v is null
        // if not execute this, mysql column "virtual" returns string "null".
        foreach ($value as $k => $v) {
            if (is_null($v)) {
                $update_flg = true;
                array_forget($value, $k);
            }
        }

        // if update
        if ($update_flg) {
            $this->setAttribute('value', $value);
        }
    }

    /**
     * set original data.
     */
    protected function setAgainOriginalValue(&$value, $original, $custom_column)
    {
        if (is_null($value)) {
            $value = [];
        }
        $column_name = $custom_column->column_name;
        // if not key, set from original
        if (array_key_exists($column_name, $value)) {
            return false;
        }
        // if column has $remove_file_columns, continue.
        // property "$remove_file_columns" uses user wants to delete file
        if (in_array($column_name, $this->remove_file_columns())) {
            return false;
        }

        if (!array_key_value_exists($column_name, $original)) {
            return false;
        }

        $value[$column_name] = array_get($original, $column_name);
        return true;
    }

    /**
     * saved file id.
     */
    protected function setFileValue()
    {
        // if requestsession "file upload uuid"(for set data this value's id and type into files)
        $uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID);
        if (isset($uuids)) {
            foreach ($uuids as &$uuid) {
                if (boolval(array_get($uuid, 'setted'))) {
                    continue;
                }
                // get id matching path
                $file = File::getData(array_get($uuid, 'uuid'));
                if (!$file) {
                    continue;
                }
                $value = $file->getCustomValueFromForm($this, $uuid);
                if (is_null($value)) {
                    continue;
                }

                $file->saveCustomValueAndColumn(array_get($value, 'id')?? $this->id, array_get($uuid, 'column_name'), array_get($uuid, 'custom_table'), array_get($uuid, 'replace'));
                $uuid['setted'] = true;
            }
            System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $uuids);
        }
    }

    /**
     * saved value event.
     */
    protected function savedValue()
    {
        $this->syncOriginal();

        // if already updated, not save again
        if ($this->already_updated) {
            return;
        }

        $columns = $this->custom_table
            ->custom_columns_cache;

        $update_flg = false;
        // loop columns
        foreach ($columns as $custom_column) {
            $column_name = array_get($custom_column, 'column_name');
            // get saved value
            $v = $custom_column->column_item->setCustomValue($this)->saved();

            // if has value, update
            if (isset($v)) {
                $this->setValue($column_name, $v);
                $update_flg = true;
            }
        }
        // if update
        if ($update_flg) {
            $this->already_updated = true;
            $this->save();
        }
    }


    /**
     * delete file and document.
     */
    public function deleteFile()
    {
        ///// delete file column
        $this->custom_table
            ->custom_columns_cache
            ->filter(function ($custom_column) {
                return ColumnType::isAttachment($custom_column);
            })->each(function ($custom_column) {
                $values = array_get($this->value, $custom_column->column_name);
                if (!$values) {
                    return;
                }

                foreach (toArray($values) as $value) {
                    $file = File::getData($value);
                    if (!$file) {
                        continue;
                    }
                    File::deleteFileInfo($file);
                }
            });


        // Delete Attachment ----------------------------------------------------
        $this->getDocuments()
            ->each(function ($document) {
                $value = array_get($document->value, 'file_uuid');
                if (!$value) {
                    return;
                }

                $file = File::getData($value);
                if (!$file) {
                    return;
                }
                File::deleteDocumentModel($file);
            });
    }


    // notify user --------------------------------------------------
    public function notify($notifySavedType)
    {
        // if $saved_notify is false, return
        if ($this->saved_notify === false) {
            return;
        }

        $notifies = $this->custom_table->notifies;

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifyCreateUpdateUser($this, $notifySavedType);
        }
    }

    /**
     * delete relation if record delete
     */
    protected function deleteRelationValues()
    {
        if (!$this->isForceDeleting()) {
            return;
        }

        $custom_table = $this->custom_table;
        // delete custom relation is 1:n value
        $this->deleteChildrenValues();

        // delete custom relation is n:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();

            // find keys and delete
            \DB::table($pivot_name)
                ->where('parent_id', $this->id)
                ->delete();
        }

        // delete custom relation is n:n value (for children)
        $relations = CustomRelation::getRelationsByChild($custom_table, RelationType::MANY_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            // ge pivot table
            $pivot_name = $relation->getRelationName();

            // find keys and delete
            \DB::table($pivot_name)
                ->where('child_id', $this->id)
                ->delete();
        }

        // delete value_authoritables
        CustomValueAuthoritable::deleteValueAuthoritable($this);
        // delete role group
        RoleGroupUserOrganization::deleteRoleGroupUserOrganization($this);

        // remove history if hard deleting
        $this->revisionHistory()->delete();

        // Delete all workflow values
        $this->workflow_values->each(function ($workflow_value) {
            $workflow_value->delete();
        });
    }

    /**
     * Delete the model from the database.
     *
     * @return bool|null
     *
     * @throws \LogicException
     */
    public function delete()
    {
        if(!$this->getValidationDestroy()) {
            $res = Plugin::pluginValidateDestroy($this);
            if (!empty($res)) {
                throw new \Exception(array_get($res, 'message'));
            }
            $this->setValidationDestroy(true);
        }
        parent::delete();
    }

    /**
     * delete relation if record delete
     */
    protected function deleteChildrenValues()
    {
        $custom_table = $this->custom_table;
        $deleteForce = $this->isForceDeleting();

        // delete custom relation is 1:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            $this->getChildrenValues($relation, true)
                ->withTrashed()
                ->get()
                ->each(function ($child) use ($deleteForce) {
                    // disable notify
                    $child->saved_notify(false);
                    if ($deleteForce) {
                        $child->forceDelete();
                    } else {
                        if(!$child->getValidationDestroy()) {
                            $res = Plugin::pluginValidateDestroy($child);
                            if (!empty($res)) {
                                throw new \Exception(array_get($res, 'message'));
                            }
                            $child->setValidationDestroy(true);
                        }
                        $child->delete();
                    }
                });
        }
    }

    /**
     * restore relation if record delete
     */
    protected function restoreChildrenValues()
    {
        $custom_table = $this->custom_table;
        // delete custom relation is 1:n value
        $relations = CustomRelation::getRelationsByParent($custom_table, RelationType::ONE_TO_MANY);
        // loop relations
        foreach ($relations as $relation) {
            $child_table = $relation->child_custom_table;
            // find keys
            getModelName($child_table)::where('parent_id', $this->id)
                ->where('parent_type', $custom_table->table_name)
                ->restore();
        }
    }

    /**
     * get Authoritable values.
     * this function selects value_authoritable, and get all values.
     */
    public function getAuthoritable($related_type)
    {
        // check request session for grid.
        $key = sprintf(Define::SYSTEM_KEY_SESSION_GRID_AUTHORITABLE, $this->custom_table->id);
        $reqSessions = System::requestSession($key);

        // If already getting, filter value.
        if (!is_null($reqSessions)) {
            return $reqSessions->filter(function ($value) use ($related_type) {
                $value = (array)$value;
                if ($value['authoritable_user_org_type'] != $related_type) {
                    return false;
                }
                if ($value['parent_id'] != $this->id) {
                    return false;
                }

                // check has user or org id
                if ($related_type == SystemTableName::USER) {
                    return $value['authoritable_target_id'] == \Exment::getUserId();
                } elseif ($related_type == SystemTableName::ORGANIZATION) {
                    $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
                    return in_array($value['authoritable_target_id'], \Exment::user()->getOrganizationIdsForQuery($enum));
                }
            });
        }

        // if not get before, now get.
        if ($related_type == SystemTableName::USER) {
            $query = $this
            ->value_authoritable_users()
            ->where('authoritable_target_id', \Exment::getUserId());
        } elseif ($related_type == SystemTableName::ORGANIZATION) {
            $enum = JoinedOrgFilterType::getEnum(System::org_joined_type_custom_value(), JoinedOrgFilterType::ONLY_JOIN);
            $query = $this
                ->value_authoritable_organizations()
                ->whereIn('authoritable_target_id', \Exment::user()->getOrganizationIdsForQuery($enum));
        } else {
            throw new \Exception();
        }

        return $query->get();
    }

    /**
     * Set value for custom column.
     *
     * @param string|array|Collection $key
     * @param mixed $val if $key is string, set value
     * @param boolean $forgetIfNull if true, and val is null, remove DB's column from "value".
     * @return $this
     */
    public function setValue($key, $val = null, $forgetIfNull = false)
    {
        $custom_columns = $this->custom_table->custom_columns_cache;

        if (is_list($key)) {
            $key = collect($key)->filter(function ($item, $itemkey) use ($custom_columns) {
                return $custom_columns->contains(function ($rec) use ($itemkey) {
                    return $rec->column_name == $itemkey;
                });
            })->toArray();
        } else {
            $is_exists = $custom_columns->contains(function ($rec) use ($key) {
                return $rec->column_name == $key;
            });
            if (!$is_exists) {
                return $this;
            }
        }

        return $this->setJson('value', $key, $val, $forgetIfNull);
    }

    /**
     * Set value for custom column, strictly.
     * (1) Execute validation before set value.
     * If validate is failed, throw exception.
     * (2) Set value.
     *
     * @param array|Collection $list custom value's list(array or collection)
     * @param boolean $forgetIfNull if true, and val is null, remove DB's column from "value".
     * @throws ValidationException validation error
     * @return $this
     */
    public function setValueStrictly($list, $forgetIfNull = false)
    {
        // validation value
        $validator = $this->custom_table->validateValue(toArray($list), $this, [
            'appendKeyName' => false,
            'checkCustomValueExists' => true,
            'checkUnnecessaryColumn' => true,
            'addValue' => false,
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $this->setValue($list, null, $forgetIfNull);
    }

    /**
     * Set value for custom column, not check custom column contains.
     *
     * @param string|array|Collection $key
     * @param mixed $val if $key is string, set value
     * @param boolean $forgetIfNull if true, and val is null, remove DB's column from "value".
     * @return $this
     */
    public function setValueDirectly($key, $val = null, $forgetIfNull = false)
    {
        return $this->setJson('value', $key, $val, $forgetIfNull);
    }

    /**
     * Get all column's getValue.
     * "$this->value" : return data on database
     * "$this->getValues()" : return data converting getValue
     */
    public function getValues($label = false, $options = [])
    {
        $custom_table = $this->custom_table;
        $values = [];

        foreach ($custom_table->custom_columns_cache as $custom_column) {
            $values[$custom_column->column_name] = $this->getValue($custom_column, $label, $options);
        }
        return $values;
    }

    public function getValue($column, $label = false, $options = [])
    {
        $time_start = microtime(true);
        if (is_null($column)) {
            return null;
        }

        $options = array_merge(
            [
                'format' => null,
                'disable_currency_symbol' => false,
            ],
            $options
        );
        $custom_table = $this->custom_table;

        // if $column is string and  and contains comma
        if (is_string($column) && str_contains($column, ',')) {
            ///// getting value Recursively
            // split comma
            $columns = explode(",", $column);
            // if $columns count >= 2, loop columns
            if (count($columns) >= 2) {
                $loop_value = $this;
                foreach ($columns as $k => $c) {
                    $lastIndex = ($k == count($columns) - 1);
                    // if $k is not last index, $loop_label is false(because using CustomValue Object)
                    if (!$lastIndex) {
                        $loop_label = false;
                    }
                    // if last index, $loop_label is called $label
                    else {
                        $loop_label = $label;
                    }
                    // get value using $c
                    $loop_value = $loop_value->getValue($c, $loop_label);
                    // if null, return
                    if (is_null($loop_value)) {
                        return null;
                    }

                    // if last index, return value
                    if ($lastIndex) {
                        return $loop_value;
                    }

                    // get custom table. if CustomValue
                    if (!($loop_value instanceof CustomValue)) {
                        return null;
                    }
                }
                return $loop_value;
            } else {
                $column = $columns[0];
            }
        }

        ///// get custom column
        // if string
        $column = CustomColumn::getEloquent($column, $custom_table);
        if (is_null($column)) {
            return null;
        }

        $item = CustomItem::getItem($column, $this);
        if (!isset($item)) {
            return null;
        }

        $item->options($options);


        // get value
        // using ValueType
        $valueType = ValueType::getEnum($label);
        if (!is_bool($label) && isset($valueType)) {
            return $valueType->getCustomValue($item, $this);
        }

        if ($label === true) {
            return $item->text();
        }
        return $item->value();
    }

    /**
     * Get vustom_value's label
     * @return string
     */
    public function getLabel()
    {
        if (!is_null($this->_label)) {
            return $this->_label;
        }

        $label_columns = $this->custom_table->getLabelColumns();

        if (isset($label_columns) && is_string($label_columns)) {
            $this->_label = $this->getExpansionLabel($label_columns);
        } else {
            $this->_label = $this->getBasicLabel($label_columns);
        }
        return  $this->_label;
    }

    /**
     * get label string (general setting case)
     */
    protected function getBasicLabel($label_columns)
    {
        $custom_table = $this->custom_table;

        if (!isset($label_columns) || count($label_columns) == 0) {
            $columns = [$custom_table->custom_columns_cache->first()];
        } else {
            $columns = $label_columns->map(function ($label_column) {
                return CustomColumn::getEloquent($label_column->table_label_id);
            });
        }

        // loop for columns and get value
        $labels = [];

        // if table's use_label_id_flg is true, add id
        if (boolval($custom_table->getOption('use_label_id_flg', false))) {
            $labels[] = '#'.strval($this->id);
        }

        foreach ($columns as $column) {
            if (!isset($column)) {
                continue;
            }
            $label = $this->getValue($column, true);
            if (empty($label)) {
                continue;
            }
            $labels[] = $label;
        }
        if (count($labels) == 0) {
            return strval($this->id);
        }

        return implode(' ', $labels);
    }

    /**
     * get custom format label
     */
    protected function getExpansionLabel($label_format)
    {
        $options['afterCallback'] = function ($text, $custom_value, $options) {
            return $this->replaceText($text, $options);
        };
        return replaceTextFromFormat($label_format, $this, $options);
    }

    /**
     * replace text. ex.comma, &yen, etc...
     */
    protected function replaceText($text, $documentItem = [])
    {
        // add comma if number_format
        if (array_key_exists('number_format', $documentItem) && !str_contains($text, ',') && is_numeric($text)) {
            $text = number_format($text);
        }

        // replace <br/> or \r\n, \n, \r to new line
        $text = preg_replace("/\\\\r\\\\n|\\\\r|\\\\n/", "\n", $text);
        // &yen; to
        $text = str_replace("&yen;", "¥", $text);

        return $text;
    }

    /**
     * get target custom_value's self link url
     */
    public function getUrl($options = [])
    {
        // options is boolean, tag is true
        if (is_bool($options)) {
            $options = ['tag' => true];
        }
        $options = array_merge(
            [
                'tag' => false,
                'uri' => null,
                'list' => false,
                'icon' => null,
                'modal' => true,
                'add_id' => false,
                'add_avatar' => false,
                'only_avatar' => false,
                'asApi' => false,
                'blank' => false,
            ],
            $options
        );
        $tag = boolval($options['tag']);

        // if this table is document, create target blank link
        if ($this->custom_table->table_name == SystemTableName::DOCUMENT) {
            $url = admin_urls(($options['asApi'] ? 'api' : null), 'files', $this->getValue('file_uuid', true));
            $document_name = $this->getValue('document_name');

            if (!$tag) {
                return $url;
            }

            return \Exment::getUrlTag($url, $document_name, UrlTagType::BLANK, [], [
                'tooltipTitle' => exmtrans('common.download')
            ]);
        }
        $url = admin_urls('data', $this->custom_table->table_name);
        if (!boolval($options['list'])) {
            $url = url_join($url, $this->id);
        }

        if (isset($options['uri'])) {
            $url = url_join($url, $options['uri']);
        }
        if (!$tag) {
            return $url;
        }

        $attributes = [];
        $escape = true;

        if (isset($options['icon'])) {
            $label = '<i class="fa ' . esc_html($options['icon']) . '" aria-hidden="true"></i>';
            $escape = false;
        } else {
            $label = $this->getLabel();
        }

        if (boolval($options['add_id'])) {
            $attributes['data-id'] = $this->id;
        }

        if (!is_nullorempty($label) && (boolval($options['add_avatar']) || boolval($options['only_avatar'])) && method_exists($this, 'getDisplayAvatarAttribute')) {
            $img = "<img src='{$this->display_avatar}' class='user-avatar' />";
            $label = '<span class="d-inline-block user-avatar-block">' . $img . esc_html($label) . '</span>';
            $escape = false;

            if (boolval($options['only_avatar'])) {
                return $label;
            }
        }

        $urlType = boolval($options['modal']) ? UrlTagType::MODAL : UrlTagType::TOP;
        return \Exment::getUrlTag($url, $label, $urlType, $attributes, [
            'notEscape' => !$escape,
        ]);
    }

    /**
     * Get document list
     *
     * @return \Illuminate\Database\Eloquent\Collection|AbstractPaginator
     */
    public function getDocuments($options = [])
    {
        $options = array_merge(
            [
                'count' => 20,
                'paginate' => false,
            ],
            $options
        );
        $query = getModelName(SystemTableName::DOCUMENT)::where('parent_id', $this->id)
            ->where('parent_type', $this->custom_table_name)
        ;

        if ($options['paginate']) {
            return $query->paginate($options['count']);
        }
        return $query->get();
    }

    /**
     * get target custom_value's relation search url
     */
    public function getRelationSearchUrl($options = [])
    {
        if (is_bool($options)) {
            $options = ['force' => true];
        }
        $options = array_merge(
            [
                'force' => false
            ],
            $options
        );
        return admin_url("search?table_name={$this->custom_table->table_name}&value_id={$this->id}" . ($options['force'] ? '&relation=1' : ''));
    }

    /**
     * merge value from custom_value
     */
    public function mergeValue($value)
    {
        foreach ($this->custom_table->custom_columns_cache as $custom_column) {
            $column_name = $custom_column->column_name;
            // if not key in value, set default value
            if (!array_has($value, $column_name)) {
                $value[$column_name] = $this->getValue($column_name, ValueType::PURE_VALUE);
            }
        }

        return $value;
    }

    /**
     * get parent value
     */
    public function getParentValue(?CustomRelation $custom_relation = null)
    {
        // if not has arg or custom relation is one to many
        if (!$custom_relation || $custom_relation->relation_type == RelationType::ONE_TO_MANY) {
            if (is_nullorempty($this->parent_type) || is_nullorempty($this->parent_id)) {
                return null;
            }

            $parent = CustomTable::getEloquent($this->parent_type);
            if (isset($parent)) {
                $model = $parent->getValueModel($this->parent_id);
            }

            return $model ?? null;
        }

        ///// get as n:n relation custom table
        $parent_table = $custom_relation->parent_custom_table_cache;
        $child_table = $custom_relation->child_custom_table_cache;
        $query = $parent_table->getValueQuery();

        // Add join query to child
        RelationTable::setChildJoinManyMany($query, $parent_table, $child_table);

        $query->where(getDBTableName($child_table) . '.id', $this->id)
            ->select(getDBTableName($parent_table) . '.*')
            ->distinct();
        return $query->get();
    }

    /**
     * Get Custom children value summary
     */
    public function getSum($custom_column)
    {
        $name = $custom_column->getQueryKey();

        if (!isset($name)) {
            return 0;
        }
        return $this->getChildrenValues($custom_column, true)
            ->sum($name);
    }

    /**
     * Get Custom children Value.
     * v1.1.0 changes ... get children values using relation or select_table
     */
    public function getChildrenValues($relation, $returnBuilder = false)
    {
        // first, get children values as relation
        if ($relation instanceof CustomColumn) {
            // get custom column as array
            // target column is select table and has index, get index name
            /** @phpstan-ignore-next-line Right side of && is always true. */
            if (ColumnType::isSelectTable($relation->column_type) && $relation->indexEnabled()) {
                $index_name = $relation->getIndexColumnName();
                // get children values where this id
                $query = getModelName(CustomTable::getEloquent($relation))::where($index_name, $this->id);
                return $returnBuilder ? $query : $query->get();
            }
        }

        // get custom column as array
        if ($relation instanceof CustomRelation) {
            $pivot_table_name = $relation->getRelationName();
        } else {
            $child_table = CustomTable::getEloquent($relation);
            $pivot_table_name = CustomRelation::getRelationNameByTables($this->custom_table, $child_table);
        }

        if (!is_nullorempty($pivot_table_name)) {
            return $returnBuilder ? $this->{$pivot_table_name}() : $this->{$pivot_table_name};
        }

        return collect();
    }

    /**
     * set revision data
     */
    public function setRevision($revision_suuid)
    {
        $revision_value = $this->revisionHistory()->where('suuid', $revision_suuid)->first()->new_value;
        if (is_json($revision_value)) {
            $revision_value = \json_decode_ex($revision_value, true);
        }
        $this->value = $revision_value;
        return $this;
    }

    /**
     * Get Query for text search.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getSearchQuery($q, $options = [])
    {
        $options = $this->getQueryOptions($q, $options);
        $searchColumns = $options['searchColumns'];

        // if search and not has searchColumns, return null;
        if ($options['executeSearch'] && is_nullorempty($searchColumns)) {
            // return no value if searchColumns is not has
            return static::query()->whereNotMatch();
        }

        $getQueryFunc = function ($searchColumn, $options) {
            $takeCount = $options['takeCount'];

            $queries = [];
            // if not search, set only pure query
            if (!$options['executeSearch']) {
                $query = static::query();
                //$query->take($takeCount);
                $queries[] = $query;
            } elseif ($searchColumn instanceof CustomColumn) {
                $column_item = $searchColumn->column_item;
                if (!isset($column_item)) {
                    return;
                }

                foreach ($column_item->getSearchQueries($options['mark'], $options['value'], $takeCount, $options['q'], $options) as $query) {
                    $query->take($takeCount);
                    $queries[] = $query;
                }
            } else {
                $query = static::query();
                if (isset($searchColumn)) {
                    $query->whereOrIn($searchColumn, $options['mark'], $options['value'])->select('id');
                }
                $query->take($takeCount);

                $queries[] = $query;
            }

            foreach ($queries as &$query) {
                // if has relationColumn, set query filtering
                if (isset($options['relationColumn'])) {
                    $options['relationColumn']->setQueryFilter($query, array_get($options, 'relationColumnValue'));
                }

                ///// if has display table, filter display table
                if (isset($options['display_table'])) {
                    $this->custom_table->filterDisplayTable($query, $options['display_table'], $options);
                }

                // set custom view's filter
                if (isset($options['target_view'])) {
                    $options['target_view']->filterModel($query); // sort is false.
                }
            }

            return $queries;
        };

        // crate union query
        $queries = [];
        $searchColumns = collect($searchColumns);
        for ($i = 0; $i < count($searchColumns) - 1; $i++) {
            $searchColumn = collect($searchColumns)->values()->get($i);

            foreach ($getQueryFunc($searchColumn, $options) as $query) {
                $queries[] = $query;
            }
        }

        $searchColumn = $searchColumns->last();
        $subquery = $getQueryFunc($searchColumn, $options)[0];

        foreach ($queries as $inq) {
            $subquery->union($inq);
        }
        //$subquery->take($takeCount);

        if ($options['searchDocument'] && boolval(config('exment.search_document', false))) {
            $subquery->union(\Exment::getSearchDocumentQuery($this->custom_table, $q)->select('id'));
        }

        // create main query
        // $mainQuery = \DB::query()->fromSub($subquery, 'sub');

        // return $mainQuery;
        return $subquery;
    }

    /**
     * Set Query for text search. use orwhere
     *
     * @return void
     */
    public function setSearchQueryOrWhere(&$query, $q, $options = [])
    {
        $options = $this->getQueryOptions($q, $options);

        $query->where(function ($query) use ($options, $q) {
            $searchColumns = collect($options['searchColumns']);
            if (is_nullorempty($searchColumns)) {
                $query->whereNotMatch();
            }

            for ($i = 0; $i < count($searchColumns); $i++) {
                $searchColumn = $searchColumns->values()->get($i);

                if ($searchColumn instanceof CustomColumn) {
                    $column_item = $searchColumn->column_item;
                    if (!isset($column_item)) {
                        continue;
                    }

                    $column_item->setSearchOrWhere($query, $options['mark'], $options['value'], $options['q']);
                } else {
                    $query->orWhere($searchColumn, $options['mark'], $options['value']);
                }
            }

            if ($options['searchDocument'] && boolval(config('exment.search_document', false))) {
                $query->orWhere(function ($query) use ($q) {
                    \Exment::getSearchDocumentQuery($this->custom_table, $q, $query);
                });
            }
        });
    }

    /**
     * Get Query Options for search
     *
     * @param string $q search text
     * @param array $options
     * @return array query option for search.
     */
    protected function getQueryOptions($q, $options = [])
    {
        $options = array_merge(
            [
                'isLike' => true,
                'maxCount' => 5,
                'paginate' => false,
                'makeHidden' => false,
                'searchColumns' => null,
                'relation' => false,
                'executeSearch' => true, // if true, search $q . If false,  not filter.
                'searchDocument' => false, // is search document.

                // append default
                'takeCount' => null,
                'mark' => null,
                'value' => null,
                'q' => $q,
                'isApi' => false,
            ],
            $options
        );

        // if selected target column,
        if (!isset($options['searchColumns']) && !$options['isApi']) {
            $options['searchColumns'] = $this->custom_table->getFreewordSearchColumns();
        }

        if (!isset($options['searchColumns']) || count($options['searchColumns']) == 0) {
            return $options;
        }

        list($mark, $value) = $this->getQueryMarkAndValue($options['isLike'], $q, $options['relation']);

        if (boolval($options['relation'])) {
            $takeCount = intval(config('exment.keyword_search_relation_count', 5000));
        } else {
            $takeCount = intval(config('exment.keyword_search_count', 1000));
        }

        // if not paginate, only take maxCount
        if (!boolval($options['paginate'])) {
            $takeCount = !isset($options['maxCount']) ? $takeCount : min($takeCount, $options['maxCount']);
        }

        //$options['searchColumns'] = $searchColumns;
        $options['takeCount'] = $takeCount;
        $options['mark'] = $mark;
        $options['value'] = $value;
        $options['q'] = $q;

        return $options;
    }

    /**
     * Get mark and value for search
     *
     * @param bool $isLike
     * @param string $q search string
     * @return array
     */
    protected function getQueryMarkAndValue($isLike, $q, bool $relation)
    {
        // if relation search, return always "=" and $q
        if ($relation) {
            return ["=", $q];
        }

        return \Exment::getQueryMarkAndValue($isLike, $q);
    }

    /**
     * Set CustomValue's model for request session.
     *
     */
    public function setValueModel()
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_CUSTOM_VALUE_VALUE, $this->custom_table_name, $this->id);
        System::setRequestSession($key, $this);

        return $this;
    }

    /**
     * Is locked by workflow
     *
     * @return bool is lock this data.
     */
    public function lockedWorkflow()
    {
        // check workflow
        if (is_null($workflow = Workflow::getWorkflowByTable($this->custom_table))) {
            return false;
        }

        // check workflow value
        if ($this->workflow_value === null || $this->workflow_status === null) {
            return false;
        }

        return boolval($this->workflow_status->datalock_flg);
    }

    /**
     * User can access this custom value
     *
     * @return bool|ErrorCode
     */
    public function enableAccess()
    {
        if (($code = $this->custom_table->enableAccess()) !== true) {
            return $code;
        }

        if (!$this->custom_table->hasPermissionData($this)) {
            return ErrorCode::PERMISSION_DENY();
        }

        return true;
    }

    /**
     * User can edit this custom value
     *
     * @param bool $checkFormAction if true, check as display
     * @return bool|ErrorCode
     */
    public function enableEdit($checkFormAction = false)
    {
        // Deleted to address the case where users with view authority are sharing data
        // if (($code = $this->custom_table->enableEdit($checkFormAction)) !== true) {
        //     return $code;
        // }

        if ($checkFormAction && $this->custom_table->formActionDisable(FormActionType::EDIT)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }

        if (!$this->custom_table->hasPermissionEditData($this)) {
            return ErrorCode::PERMISSION_DENY();
        }

        // if ($this->custom_table->isOneRecord()) {
        //     return ErrorCode::PERMISSION_DENY();
        // }

        // check workflow
        if ($this->lockedWorkflow()) {
            return ErrorCode::WORKFLOW_LOCK();
        }

        if (!is_null($parent_value = $this->getParentValue()) && ($code = $parent_value->enableEdit($checkFormAction)) !== true) {
            return $code;
        }

        if ($this->trashed()) {
            return ErrorCode::ALREADY_DELETED();
        }

        return true;
    }

    /**
     * User can delete this custom value
     *
     * @param bool $checkFormAction if true, check as display
     * @return bool|ErrorCode
     */
    public function enableDelete($checkFormAction = false)
    {
        if (!$this->custom_table->hasPermissionEditData($this)) {
            return ErrorCode::PERMISSION_DENY();
        }

        if ($checkFormAction && $this->custom_table->formActionDisable(FormActionType::DELETE)) {
            return ErrorCode::FORM_ACTION_DISABLED();
        }

        if ($this->custom_table->isOneRecord()) {
            return ErrorCode::PERMISSION_DENY();
        }

        // check workflow
        if ($this->lockedWorkflow()) {
            return ErrorCode::WORKFLOW_LOCK();
        }

        if (method_exists($this, 'disabled_delete_trait') && $this->disabled_delete_trait()) {
            return ErrorCode::DELETE_DISABLED();
        }

        if (!is_null($parent_value = $this->getParentValue()) && ($code = $parent_value->enableDelete($checkFormAction)) !== true) {
            return $code;
        }

        return true;
    }

    /**
     * User can share this custom value
     * @return bool
     */
    public function enableShare()
    {
        // if system doesn't use role, return false
        if (!System::permission_available()) {
            return false;
        }

        if ($this->trashed()) {
            return false;
        }

        $custom_table = $this->custom_table;

        // if master, false
        if (in_array($custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
            return false;
        }

        // if custom table has all_user_editable_flg, return false(not necessary use share)
        if (boolval($custom_table->getOption('all_user_editable_flg'))) {
            return false;
        }

        // if not has edit data, return false
        if (!$custom_table->hasPermissionEditData($this)) {
            return false;
        }

        // if not has share data, return false
        if (!$custom_table->hasPermission([Permission::CUSTOM_TABLE, Permission::CUSTOM_VALUE_SHARE])) {
            return false;
        }

        return true;
    }

    /**
     * Get all accessible users on this value. (get model)
     */
    public function getAccessibleUsers()
    {
        $custom_table = $this->custom_table;
        $ids = $this->value_authoritable_users()->pluck('authoritable_target_id')->toArray();

        // get custom table's user ids(contains all table and permission role group)
        $queryTable = AuthUserOrgHelper::getRoleUserAndOrgBelongsUserQueryTable($custom_table, Permission::AVAILABLE_ALL_CUSTOM_VALUE);

        if (!is_nullorempty($queryTable)) {
            $queryTable->withoutGlobalScope(CustomValueModelScope::class);

            $tablename = getDBTableName(SystemTableName::USER);
            $ids = array_merge($queryTable->pluck("$tablename.id")->toArray(), $ids);
        }

        // get real value
        return getModelName(SystemTableName::USER)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->whereIn('id', $ids)
            ->get()
            ->unique();
    }

    /**
     * Filter all accessible users on this value.
     */
    public function filterAccessibleUsers($userIds): \Illuminate\Support\Collection
    {
        if (is_nullorempty($userIds)) {
            return collect();
        }

        $accessibleUsers = $this->getAccessibleUsers();

        $result = collect();
        foreach ($userIds as $user) {
            if ($accessibleUsers->contains(function ($accessibleUser) use ($user) {
                return $accessibleUser->id == $user;
            })) {
                $result->push($user);
            }
        }

        return $result;
    }


    /**
     * Get all accessible organization on this value. (get model)
     */
    public function getAccessibleOrganizations()
    {
        $custom_table = $this->custom_table;
        $ids = $this->value_authoritable_organizations()->pluck('authoritable_target_id')->toArray();

        // get custom table's organization ids(contains all table and permission role group)
        $queryTable = AuthUserOrgHelper::getRoleOrganizationQueryTable($custom_table, Permission::AVAILABLE_ALL_CUSTOM_VALUE);

        if (!is_nullorempty($queryTable)) {
            $queryTable->withoutGlobalScope(CustomValueModelScope::class);

            $tablename = getDBTableName(SystemTableName::ORGANIZATION);
            $ids = array_merge($queryTable->pluck("$tablename.id")->toArray(), $ids);
        }

        // get real value
        return getModelName(SystemTableName::ORGANIZATION)::query()
            ->withoutGlobalScope(CustomValueModelScope::class)
            ->whereIn('id', $ids)
            ->get()
            ->unique();
    }

    /**
     * Filter all accessible orgs on this value.
     */
    public function filterAccessibleOrganizations($organizationIds): \Illuminate\Support\Collection
    {
        if (is_nullorempty($organizationIds)) {
            return collect();
        }

        $accessibleOrganizations = $this->getAccessibleOrganizations();

        $result = collect();
        foreach ($organizationIds as $org) {
            if ($accessibleOrganizations->contains(function ($accessibleOrganization) use ($org) {
                return $accessibleOrganization->id == $org;
            })) {
                $result->push($org);
            }
        }

        return $result;
    }
}
