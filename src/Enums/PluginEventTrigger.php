<?php

namespace Exceedone\Exment\Enums;

class PluginEventTrigger extends EnumBase
{
    public const SAVING = 'saving';
    public const SAVED = 'saved';
    public const LOADING = 'loading';
    public const LOADED = 'loaded';
    public const GRID_MENUBUTTON = 'grid_menubutton';
    public const FORM_MENUBUTTON_SHOW = 'form_menubutton_show';
    public const FORM_MENUBUTTON_CREATE = 'form_menubutton_create';
    public const FORM_MENUBUTTON_EDIT = 'form_menubutton_edit';
    public const WORKFLOW_ACTION_EXECUTING = 'workflow_action_executing';
    public const WORKFLOW_ACTION_EXECUTED = 'workflow_action_executed';
    public const NOTIFY_EXECUTING = 'notify_executing';
    public const NOTIFY_EXECUTED = 'notify_executed';
}
