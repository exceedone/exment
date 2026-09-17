<?php

namespace Exceedone\Exment\Exceptions;

class LineSendFailedException extends \RuntimeException
{
    /** @var array{status:int,ok:bool,body:array,raw:string} */
    protected $result;

    public function __construct(string $to, array $result)
    {
        parent::__construct(sprintf('LINE push to %s failed with status %s', $to, $result['status'] ?? 0));
        $this->result = $result;
    }

    public function getResult(): array
    {
        return $this->result;
    }
}
