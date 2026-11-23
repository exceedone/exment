<?php

namespace Exceedone\Exment\Form\Navbar;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\Features;

class HelpNav
{
    public function __toString()
    {
        // get manual url
        $manual_url = htmlspecialchars(getManualUrl());

        // set help urls: the pages of the features first (their own manual), then the others
        $help_urls = esc_html(json_encode(array_merge(static::featureHelpUrls(), Define::HELP_URLS)));

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

    /**
     * @return array<int, array{uri: string, help_uri: string}>
     */
    protected static function featureHelpUrls(): array
    {
        try {
            return Features::helpUrls();
        } catch (\Throwable $e) {
            // the header is on every page: without the features, the button opens the others
            \Log::warning('[HelpNav] the manuals of the features: ' . $e->getMessage());
            return [];
        }
    }
}
