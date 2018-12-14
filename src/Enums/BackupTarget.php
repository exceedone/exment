<?php

namespace Exceedone\Exment\Enums;

class BackupTarget extends EnumBase
{
    const DATABASE = 'database';
    const PLUGIN = 'plugin';
    const ATTACHMENT = 'attachment';
    const LOG = 'log';
    const CONFIG = 'config';
}
