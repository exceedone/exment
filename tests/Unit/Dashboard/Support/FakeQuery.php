<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

/**
 * Records every where* call instead of touching a database, so the SQL shape can be
 * asserted. Chainable like a query builder.
 */
class FakeQuery
{
    /** @var array<int, array{0:string,1:array}> */
    public $calls = [];

    public function where(...$args)
    {
        $this->calls[] = ['where', $args];
        return $this;
    }

    public function whereIn(...$args)
    {
        $this->calls[] = ['whereIn', $args];
        return $this;
    }

    public function whereRaw(...$args)
    {
        $this->calls[] = ['whereRaw', $args];
        return $this;
    }

    /** Every call rendered as one readable line, in order. */
    public function sql(): array
    {
        return array_map(function ($call) {
            [$method, $args] = $call;
            if ($method === 'where') {
                return $args[0] . ' = ' . json_encode($args[1]);
            }
            if ($method === 'whereIn') {
                return $args[0] . ' IN ' . json_encode($args[1]);
            }
            return $args[0] . (isset($args[1]) ? ' ' . json_encode($args[1]) : '');
        }, $this->calls);
    }
}
