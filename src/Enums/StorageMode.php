<?php

namespace Exceedone\Exment\Enums;

/**
 * Read source of a custom table's single-valued columns (hybrid storage, 02 §2.1 / §15.7).
 * Multi-valued columns are always read from the JSON "value" field, in either mode.
 */
class StorageMode extends EnumBase
{
    public const JSON = 'json';
    public const PHYSICAL = 'physical';
}
