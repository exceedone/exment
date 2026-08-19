<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

/**
 * Records every where* call FilterState makes instead of touching a database, so the
 * SQL SHAPE (which column expression, which operator, which bindings) can be asserted
 * without a table. Chainable like a query builder.
 */
class FakeQuery
{
    /** @var array<int, array{0:string,1:array}> method => args */
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

    /** All SQL fragments in call order ("where a=v" rendered as "a = v" for readability). */
    public function sql(): array
    {
        return array_map(function ($c) {
            [$m, $a] = $c;
            if ($m === 'where') {
                return $a[0] . ' = ' . json_encode($a[1]);
            }
            if ($m === 'whereIn') {
                return $a[0] . ' IN ' . json_encode($a[1]);
            }
            return $a[0] . (isset($a[1]) ? ' ' . json_encode($a[1]) : '');
        }, $this->calls);
    }

    public function count(): int
    {
        return count($this->calls);
    }
}
