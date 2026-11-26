<?php

namespace Exceedone\Exment\Services\LineWorks;

/**
 * A message to LINE WORKS not sent (ISS00114). Its message has no secret.
 */
class LineWorksException extends \RuntimeException
{
    public function __construct(string $message, public bool $retry = true)
    {
        parent::__construct($message);
    }
}
