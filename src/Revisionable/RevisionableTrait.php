<?php

namespace Exceedone\Exment\Revisionable;

/*
 * This file is part of the Revisionable package by Venture Craft
 *
 * (c) Venture Craft <http://www.venturecraft.com.au>
 *
 */

/*
 * Customized for Exment
 */

/**
 * Class RevisionableTrait
 * @package Venturecraft\Revisionable
 */
trait RevisionableTrait
{
    /**
     * @var array
     */
    private $originalData = array();

    /**
     * @var array
     */
    private $updatedData = array();

    /**
     * @var boolean
     */
    private $updating = false;

    /**
     * @var array|null
     */
    private $dontKeep = array();

    /**
     * @var array|null
     */
    private $doKeep = array();

    /**
     * @var array|null
     */
    private $doKeepTrigger = array();

    /**
     * Keeps the list of values that have been updated
     *
     * @var array
     */
    protected $dirtyData = array();

    /**
     * Remove old revisions (works only when used with $historyLimit)
     *
     * @var boolean|null
     */
    protected $revisionCleanup = true;

    /**
     * Ensure that the bootRevisionableTrait is called only
     * if the current installation is a laravel 4 installation
     * Laravel 5 will call bootRevisionableTrait() automatically
     */
    // public static function boot()
    // {
    //     parent::boot();

    //     if (!method_exists(get_called_class(), 'bootTraits')) {
    //         static::bootRevisionableTrait();
    //     }
    // }

    /**
     * Create the event listeners for the saving and saved events
     * This lets us save revisions whenever a save is made, no matter the
     * http method.
     * * define at custom_value
     */
    // public static function bootRevisionableTrait()
    // {
    //     static::saving(function ($model) {
    //         $model->preSave();
    //     });

    //     static::saved(function ($model) {
    //         $model->postSave();
    //     });

    //     static::created(function ($model) {
    //         $model->postCreate();
    //     });

    //     static::deleted(function ($model) {
    //         $model->preSave();
    //         $model->postDelete();
    //     });
    // }

    /**
     * @return mixed
     */
    public function revisionHistory()
    {
        return $this->morphMany(Revision::class, 'revisionable');
    }

    /**
     * Generates a list of the last $limit revisions made to any objects of the class it is being called from.
     *
     * @param int $limit
     * @param string $order
     * @return mixed
     */
    public static function classRevisionHistory($limit = 100, $order = 'desc')
    {
        $query = Revision::class;
        return $query::where('revisionable_type', get_called_class())
            ->orderBy('updated_at', $order)->limit($limit)->get();
    }

    /**
    * Invoked before a model is saved. Return false to abort the operation.
    */
    public function preSave()
    {
        if (!isset($this->revisionEnabled) || $this->revisionEnabled) {
            // if there's no revisionEnabled. Or if there is, if it's true

            $this->originalData = $this->original;
            $this->updatedData = $this->attributes;

            // we can only safely compare basic items,
            // so for now we drop any object based items, like DateTime
            foreach ($this->updatedData as $key => $val) {
                if (isset($this->casts[$key]) && in_array($this->casts[$key], ['object', 'array', 'json']) && isset($this->originalData[$key])) {
                    // Sorts the keys of a JSON object due Normalization performed by MySQL
                    // So it doesn't set false flag if it is changed only order of key or whitespace after comma
                    $updatedData = $this->getSortedJson($this->updatedData[$key]);
                    $this->updatedData[$key] = json_encode($updatedData);
                    $originalData = $this->getSortedJson($this->originalData[$key]);
                    $this->originalData[$key] = json_encode($originalData);
                } elseif (gettype($val) == 'object' && !method_exists($val, '__toString')) {
                    unset($this->originalData[$key]);
                    unset($this->updatedData[$key]);
                    $this->dontKeep[] = $key;
                }
            }

            // the below is ugly, for sure, but it's required so we can save the standard model
            // then use the keep / dontkeep values for later, in the isRevisionable method
            $this->dontKeep = isset($this->dontKeepRevisionOf) ?
                array_merge($this->dontKeepRevisionOf, $this->dontKeep)
                : $this->dontKeep;

            $this->doKeep = isset($this->keepRevisionOf) ?
                array_merge($this->keepRevisionOf, $this->doKeep)
                : $this->doKeep;

            $this->doKeepTrigger = isset($this->keepRevisionOfTrigger) ?
                array_merge($this->keepRevisionOfTrigger, $this->doKeepTrigger)
                : $this->doKeepTrigger;

            unset($this->attributes['dontKeepRevisionOf']);
            unset($this->attributes['keepRevisionOf']);
            unset($this->attributes['keepRevisionOfTrigger']);

            $this->dirtyData = $this->getDirty();
            $this->updating = $this->exists;
        }
    }


    /**
     * Called after a model is successfully saved.
     *
     * @return void
     */
    public function postSave()
    {
        // get historyLimit
        if (isset($this->historyLimit)) {
            $historyLimit = $this->historyLimit;
        }
        if (isset($historyLimit) && $this->revisionHistory()->count() >= $historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->revisionCleanup)) {
            $RevisionCleanup=$this->revisionCleanup;
        } else {
            $RevisionCleanup=false;
        }

        // check if the model already exists
        //if (((!isset($this->revisionEnabled) || $this->revisionEnabled) && $this->updating) && (!$LimitReached || $RevisionCleanup)) {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled) && (!$LimitReached || $RevisionCleanup)) {
            // if it does, it means we're updating

            $changes_to_record = $this->changedRevisionableFields();

            $revisions = array();

            foreach ($changes_to_record as $key => $change) {
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $key,
                    'old_value' => array_get($this->originalData, $key),
                    'new_value' => $this->updatedData[$key],
                    'create_user_id' => $this->getSystemUserId(),
                );
            }

            if (count($revisions) > 0) {
                if ($LimitReached && $RevisionCleanup) {
                    $toDelete = $this->revisionHistory()->orderBy('id', 'asc')->limit(count($revisions))->get();
                    foreach ($toDelete as $delete) {
                        $delete->delete();
                    }
                }
                $this->saveData($revisions);
                \Event::dispatch('revisionable.saved', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
    * Called after record successfully created
    */
    public function postCreate()
    {
        // Check if we should store creations in our revision history
        // Set this value to true in your model if you want to
        if (empty($this->revisionCreationsEnabled)) {
            // We should not store creations.
            return false;
        }

        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)) {
            $changes_to_record = $this->changedRevisionableFields();

            $revisions = array();

            foreach ($changes_to_record as $key => $change) {
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $key,
                    'old_value' => array_get($this->originalData, $key),
                    'new_value' => $this->updatedData[$key],
                    'create_user_id' => $this->getSystemUserId(),
                );
            }

            if (count($revisions) > 0) {
                $this->saveData($revisions);
                \Event::dispatch('revisionable.created', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
     * If softdeletes are enabled, store the deleted time
     */
    public function postDelete()
    {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)
            && $this->isSoftDelete()
        ) {
            if ($this->isRevisionable($this->getDeletedAtColumn())) {
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $this->getDeletedAtColumn(),
                    'old_value' => null,
                    'new_value' => $this->{$this->getDeletedAtColumn()},
                    'create_user_id' => $this->getSystemUserId(),
                    'delete_user_id' => $this->getSystemUserId(),
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                    'deleted_at' => new \DateTime(),
                );
                $this->saveData($revisions);
                \Event::dispatch('revisionable.deleted', array('model' => $this, 'revisions' => $revisions));
            } elseif ($this->isRevisionableTrigger($this->getDeletedAtColumn())) {
                $triggerKey = array_get($this->doKeepTrigger, $this->getDeletedAtColumn());
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $triggerKey,
                    'old_value' => null,
                    'new_value' => array_get($this->updatedData, $triggerKey),
                    'create_user_id' => $this->getSystemUserId(),
                    'delete_user_id' => $this->getSystemUserId(),
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                    'deleted_at' => new \DateTime(),
                );
                $this->saveData($revisions);
                \Event::dispatch('revisionable.deleted', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
     * Force delete are enabled, store the deleted time
     */
    public function postForceDelete()
    {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)
        ) {
            $changes_to_record = $this->changedRevisionableFields();
            $revisions = array();
            $revisions[] = array(
                'revisionable_type' => $this->getMorphClass(),
                'revisionable_id' => $this->getKey(),
            );
            $this->forceDeleteData($revisions);
        }
    }

    /**
     * If softdeletes are enabled, restore event
     */
    public function postRestore()
    {
        if ((!isset($this->revisionEnabled) || $this->revisionEnabled)
            && $this->isSoftDelete()
        ) {
            if ($this->isRevisionable($this->getDeletedAtColumn())) {
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $this->getDeletedAtColumn(),
                    'old_value' => null,
                    'new_value' => $this->{$this->getDeletedAtColumn()},
                    'create_user_id' => $this->getSystemUserId(),
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                );
                $this->saveData($revisions);
                \Event::dispatch('revisionable.saved', array('model' => $this, 'revisions' => $revisions));
            } elseif ($this->isRevisionableTrigger($this->getDeletedAtColumn())) {
                $triggerKey = array_get($this->doKeepTrigger, $this->getDeletedAtColumn());
                $revisions[] = array(
                    'revisionable_type' => $this->getMorphClass(),
                    'revisionable_id' => $this->getKey(),
                    'key' => $triggerKey,
                    'old_value' => null,
                    'new_value' => array_get($this->updatedData, $triggerKey),
                    'create_user_id' => $this->getSystemUserId(),
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                );
                $this->saveData($revisions);
                \Event::dispatch('revisionable.saved', array('model' => $this, 'revisions' => $revisions));
            }
        }
    }

    /**
     * Attempt to find the user id of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     **/
    public function getSystemUserId()
    {
        try {
            if (class_exists($class = '\SleepingOwl\AdminAuth\Facades\AdminAuth')
                || class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
                || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
            ) {
                return ($class::check()) ? $class::getUser()->id : null;
            }
            if (class_exists($class = '\Exceedone\Exment\Facades\ExmentFacade')
            ) {
                $user = $class::user();
                return isset($user) ? $user->getUserId() : null;
            } elseif (\Auth::check()) {
                return \Auth::user()->getAuthIdentifier();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    protected function saveData($revisions)
    {
        foreach ($revisions as $revision) {
            // get revision_no
            $exists_revision_no = Revision::where('revisionable_type', array_get($revision, 'revisionable_type'))
                ->where('revisionable_id', array_get($revision, 'revisionable_id'))
                ->max('revision_no') + 1;
            $obj_revision = new Revision();
            $obj_revision->revision_no = $exists_revision_no;
            foreach ($revision as $key => $r) {
                $obj_revision->{$key} = $r;
            }
            $obj_revision->save();
        }
    }

    protected function forceDeleteData($revisions)
    {
        foreach ($revisions as $revision) {
            Revision::where('revisionable_type', array_get($revision, 'revisionable_type'))
                ->where('revisionable_id', array_get($revision, 'revisionable_id'))
                ->forceDelete();
        }
    }

    /**
     * Get all of the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedRevisionableFields()
    {
        $changes_to_record = array();
        foreach ($this->dirtyData as $key => $value) {
            // check that the field is revisionable, and double check
            // that it's actually new data in case dirty is, well, clean
            if ($this->isRevisionable($key) && !is_array($value)) {
                if (!isset($this->originalData[$key]) || $this->originalData[$key] != $this->updatedData[$key]) {
                    $changes_to_record[$key] = $value;
                }
            } else {
                // we don't need these any more, and they could
                // contain a lot of data, so lets trash them.
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $changes_to_record;
    }

    /**
     * Check if this field should have a revision kept
     *
     * @param string $key
     *
     * @return bool
     */
    private function isRevisionable($key)
    {

        // If the field is explicitly revisionable, then return true.
        // If it's explicitly not revisionable, return false.
        // Otherwise, if neither condition is met, only return true if
        // we aren't specifying revisionable fields.
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }

        return empty($this->doKeep);
    }


    /**
     * Check if this field should have a revision kept as trigger
     *
     * @param string $key
     *
     * @return bool
     */
    private function isRevisionableTrigger($key)
    {
        if (isset($this->doKeepTrigger) && array_has($this->doKeepTrigger, $key)) {
            return true;
        }

        return false;
    }

    /**
     * Check if soft deletes are currently enabled on this model
     *
     * @return bool
     */
    private function isSoftDelete()
    {
        // check flag variable used in laravel 4.2+
        /** @phpstan-ignore-next-line Property Exceedone\Exment\Model\CustomValue::$forceDeleting (bool) in isset() is not nullable. */
        if (isset($this->forceDeleting)) {
            return !$this->forceDeleting;
        }

        // otherwise, look for flag used in older versions
        /** @phpstan-ignore-next-line Unreachable statement - code above always terminates. */
        if (isset($this->softDelete)) {
            return $this->softDelete;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFields()
    {
        return $this->revisionFormattedFields;
    }

    /**
     * @return mixed
     */
    public function getRevisionFormattedFieldNames()
    {
        return $this->revisionFormattedFieldNames;
    }

    /**
     * Identifiable Name
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    /**
     * Revision Unknown String
     * When displaying revision history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionNullString()
    {
        return isset($this->revisionNullString) ? $this->revisionNullString : 'nothing';
    }

    /**
     * No revision string
     * When displaying revision history, if the revisions value
     * cant be figured out, this is used instead.
     * It can be overridden.
     *
     * @return string an identifying name for the model
     */
    public function getRevisionUnknownString()
    {
        return isset($this->revisionUnknownString) ? $this->revisionUnknownString : 'unknown';
    }

    /**
     * Disable a revisionable field temporarily
     * Need to do the adding to array longhanded, as there's a
     * PHP bug https://bugs.php.net/bug.php?id=42030
     *
     * @param mixed $field
     *
     * @return void
     */
    public function disableRevisionField($field)
    {
        if (!isset($this->dontKeepRevisionOf)) {
            $this->dontKeepRevisionOf = array();
        }
        if (is_array($field)) {
            foreach ($field as $one_field) {
                $this->disableRevisionField($one_field);
            }
        } else {
            $donts = $this->dontKeepRevisionOf;
            $donts[] = $field;
            $this->dontKeepRevisionOf = $donts;
            unset($donts);
        }
    }


    /**
     * get sorted jon object
     *
     * Normalization performed by MySQL and
     * discards extra whitespace between keys, values, or elements
     * in the original JSON document.
     * To make lookups more efficient, it sorts the keys of a JSON object.
     *
     * @param mixed $attribute
     *
     * @return mixed
     */
    protected function getSortedJson($attribute)
    {
        if (empty($attribute)) {
            return $attribute;
        }
        if (is_string($attribute)) {
            $attribute = json_decode_ex($attribute, true);
        }
        foreach ($attribute as $key => $value) {
            if (is_array($value) || is_object($value)) {
                $value = $this->getSortedJson($value);
            } else {
                continue;
            }
        }
        ksort($attribute);
        return $attribute;
    }
}
