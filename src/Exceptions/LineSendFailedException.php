<?php

namespace Exceedone\Exment\Exceptions;

class LineSendFailedException extends \RuntimeException
{
    /** @var array<string, mixed> push result: status, ok, body, raw */
    protected $result;

    /**
     * @param array<string, mixed> $result
     */
    public function __construct(string $to, array $result)
    {
        parent::__construct(sprintf('LINE push to %s failed with status %s', $to, $result['status'] ?? 0));
        $this->result = $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function getResult(): array
    {
        return $this->result;
    }
}
