<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;

class Html
{
    // @phpstan-ignore-next-line
    protected $dashboard_box;

    public function __construct(?DashboardBox $dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;
    }

    /**
     * get header
     */
    // @phpstan-ignore-next-line
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    // @phpstan-ignore-next-line
    public function footer()
    {
        return null;
    }

    /**
     * get html body
     */
    // @phpstan-ignore-next-line
    public function body()
    {
        // not escape.
        return '<div class="dashboard-box-editor">' . $this->dashboard_box->getOption('html') . '</div>';
    }

    /**
     * set laravel admin embeds option
     */
    // @phpstan-ignore-next-line
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->textarea('html', exmtrans('dashboard.dashboard_box_options.html'))
            ->rows(10)
            ->help(exmtrans('dashboard.help.dashboard_box_options.html'))
            ->attribute(['data-filter' => json_encode(['key' => 'options_target_system_id', 'value' => [DashboardBoxSystemPage::HTML]])])
        ;
    }
}
