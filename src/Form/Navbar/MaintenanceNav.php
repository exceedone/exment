<?php

namespace Exceedone\Exment\Form\Navbar;

use Exceedone\Exment\Controllers\MaintenanceController;
use Exceedone\Exment\Services\MaintenanceMode;

/**
 * The maintenance banner (ISS01396): to who is in during the maintenance, and
 * to everyone logged in before a planned one. Part of the navbar, so it stays
 * when a page is changed by pjax; fixed at the top of the content, it does not
 * catch clicks.
 */
class MaintenanceNav
{
    /**
     * The banner to show now, or null.
     */
    public static function make(): ?self
    {
        try {
            $current = MaintenanceMode::current();
            if (isset($current)) {
                $text = exmtrans('maintenance.banner.on');
                if (!empty($current['end_at'])) {
                    $text .= ' ' . exmtrans('maintenance.end_at', ['end_at' => MaintenanceController::toLocal($current['end_at'], 'Y-m-d H:i')]);
                }
                return new self($text, true);
            }
            $upcoming = MaintenanceMode::upcoming();
            if (isset($upcoming)) {
                return new self(exmtrans('maintenance.banner.planned', [
                    'start_at' => MaintenanceController::toLocal($upcoming['start_at'], 'Y-m-d H:i'),
                    'end_at' => MaintenanceController::toLocal($upcoming['end_at'], 'Y-m-d H:i') ?: '-',
                ]), false);
            }
        } catch (\Throwable $e) {
        }
        return null;
    }

    public function __construct(protected string $text, protected bool $active)
    {
    }

    public function __toString()
    {
        $text = esc_html($this->text);
        $style = $this->active
            ? 'background: #f39c12; color: #fff;'
            : 'background: #d9edf7; color: #31708f; border-bottom: 1px solid #bce8f1;';
        $class = $this->active ? 'exment-maintenance-on' : 'exment-maintenance-planned';

        return <<<HTML

<li class="exment-maintenance {$class}" role="presentation">
    <style>.content-wrapper { padding-top: 28px; }</style>
    <div class="exment-maintenance-banner" role="status" style="position: fixed; left: 0; right: 0; top: 50px; z-index: 1029; padding: 4px 16px; {$style} font-size: 13px; line-height: 1.5; text-align: center; pointer-events: none;">
        <i class="fa fa-wrench"></i> {$text}
    </div>
</li>

HTML;
    }
}
