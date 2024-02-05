<?php

namespace Exceedone\Exment\Enums;

class PluginEventType extends EnumBase
{
    public const SAVING = 'saving';
    public const SAVED = 'saved';
    public const DELETED = 'deleted';
    public const LOADING = 'loading';
    public const LOADED = 'loaded';
    public const WORKFLOW_ACTION_EXECUTING = 'workflow_action_executing';
    public const WORKFLOW_ACTION_EXECUTED = 'workflow_action_executed';
    public const NOTIFY_EXECUTING = 'notify_executing';
    public const NOTIFY_EXECUTED = 'notify_executed';
}
