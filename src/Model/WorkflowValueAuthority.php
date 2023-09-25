<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Model\Interfaces\WorkflowAuthorityInterface;

/**
 * @phpstan-consistent-constructor
 * @method static \Illuminate\Database\Query\Builder insert(array $values)
 * @property mixed $related_id
 * @property mixed $related_type
 */
class WorkflowValueAuthority extends ModelBase implements WorkflowAuthorityInterface
{
}
