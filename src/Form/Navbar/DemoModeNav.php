<?php

namespace Exceedone\Exment\Form\Navbar;

use Exceedone\Exment\Services\DemoMode;

/**
 * The demo mode banner (config "exment.demo_mode").
 * It is part of the navbar, so it stays when a page is changed by pjax, and it is shown
 * fixed at the bottom of the window. It does not catch clicks, so it never hides a button.
 */
class DemoModeNav
{
    public function __toString()
    {
        $text = esc_html(DemoMode::bannerText());

        return <<<HTML

<li class="exment-demo-mode" role="presentation">
    <style>.content-wrapper { padding-bottom: 32px; }</style>
    <div class="exment-demo-mode-banner" role="status" style="position: fixed; left: 0; right: 0; bottom: 0; z-index: 1035; padding: 4px 16px; background: #fcf8e3; color: #8a6d3b; border-top: 1px solid #faebcc; font-size: 13px; line-height: 1.6; text-align: center; pointer-events: none;">
        <i class="fa fa-info-circle"></i> {$text}
    </div>
</li>

HTML;
    }
}
