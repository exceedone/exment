<?php

namespace Exceedone\Exment\Services\Update;

use UpdateHelper\UpdateHelper as UpdateHelperBase;

class UpdateHelper extends UpdateHelperBase
{
    /**
     * Call require.
     * *Property $dependencies is private, so cannot get.*
     *
     * @param array $dependencies key is labrary name, value is version. If newest version,Please set "*".
     * @return $this
     */
    public function require(array $dependencies)
    {
        foreach ($dependencies as $name => $version) {
            $composer =  \Exment::getComposerPath();
            //$output = shell_exec("composer require {$name}={$version} --no-scripts");
            chdir(base_path());
            exec("{$composer} require {$name}={$version} --no-scripts", $output, $return_var);

            if ($return_var != 0) {
                // TODO:error
            }
        }

        return $this;
    }
}
