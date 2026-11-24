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
    // @phpstan-ignore-next-line
    public function require(array $dependencies)
    {
        foreach ($dependencies as $name => $version) {
            // EXMENT_COMPOSER_PATH is one program (a path or "composer"), passed as one quoted argument
            $composer = escapeshellarg(\Exment::getComposerPath());
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
