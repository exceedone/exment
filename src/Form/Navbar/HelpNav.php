<?php

namespace Exceedone\Exment\Form\Navbar;

use Exceedone\Exment\Model\Define;

class HelpNav
{
    public function __toString()
    {
        // get manual url
        $manual_url = htmlspecialchars(getManualUrl());

        // set help urls
        $help_urls = esc_html(json_encode(Define::HELP_URLS));

        return <<<HTML

<li>
    <a href="$manual_url" target="_blank" id="manual_link" style="font-size:25px; padding-top:12.5px; padding-bottom:12.5px;">
        <i class="fa fa-question-circle"></i>
    </a>
</li>
<input type="hidden" value="$manual_url" id="manual_base_uri">
<input type="hidden" value="$help_urls" id="help_urls">

HTML;
    }
}
