<?php

namespace Exceedone\Exment\Enums;

class WorkflowGetAuthorityType extends EnumBase
{
    /**
     * Getting current workflow user on custom value detail.
     */
    public const CURRENT_WORK_USER = 'current_work_user';

    /**
     * Getting next user on executing workflow modal.
     */
    public const NEXT_USER_ON_EXECUTING_MODAL = 'next_user_on_executing_modal';

    /**
     * Getting next user count on executing workflow modal.
     */
    public const CALC_NEXT_USER_COUNT = 'calc_next_user_count';

    /**
     * Getting tageting user Real execute workflow
     */
    public const EXEXCUTE = 'exexcute';

    /**
     * By notify
     */
    public const NOTIFY = 'notify';
}
