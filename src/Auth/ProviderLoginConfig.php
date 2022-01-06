<?php

namespace Exceedone\Exment\Auth;

interface ProviderLoginConfig
{
    /**
     * Set custom config for login setting controller for Exment.
     *
     * @param mixed $form
     * @return void
     */
    public function setLoginCustomConfig($form);
}
