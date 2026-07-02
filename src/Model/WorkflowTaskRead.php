<?php

namespace Exceedone\Exment\Model;

/**
 * Feature 1: per-user "seen" state for un-actioned workflow tasks.
 *
 * @property mixed $target_user_id
 * @property mixed $task_key
 */
class WorkflowTaskRead extends ModelBase
{
    protected $guarded = ['id'];
}
