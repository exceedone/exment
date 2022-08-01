<?php

namespace Exceedone\Exment\Tests\Constraints;

class ContainsSelectOption extends ExactSelectOption
{
    /**
     * test 2 array result.
     *
     * @return bool
     */
    protected function test2Array(): bool
    {
        return $this->contains2Array($this->options, $this->realOptions);
    }
}
