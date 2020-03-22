<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Encore\Admin\Widgets\Table as WidgetTable;
use Carbon\Carbon;

class Editor
{
    protected $dashboard_box;
    
    public function __construct(?DashboardBox $dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;
    }

    /**
     * get header
     */
    public function header()
    {
        return null;
    }
    
    /**
     * get footer
     */
    public function footer()
    {
        return null;
    }
    
    /**
     * get html body
     */
    public function body()
    {
        // escape script.
        return esc_script_tag('<div class="dashboard-box-editor">' . $this->dashboard_box->getOption('content') . '</div>');
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $form->tinymce('content', exmtrans('dashboard.dashboard_box_options.content'))
            ->config('height', '250')
            ->help(exmtrans('dashboard.help.dashboard_box_options.content'))
            ->attribute(['data-filter' => json_encode(['key' => 'options_target_system_id', 'value' => [DashboardBoxSystemPage::EDITOR]])])
            ;
    }
}
