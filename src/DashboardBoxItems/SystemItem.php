<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Model\Define;

class SystemItem implements ItemInterface
{
    protected $dashboard_box;
    
    public function __construct($dashboard_box)
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
     * get html body
     */
    public function body()
    {
        $item = collect(Define::DASHBOARD_BOX_SYSTEM_PAGES)->first(function ($value) {
            return array_get($value, 'id') == array_get($this->dashboard_box, 'options.target_system_id');
        });
        if (isset($item)) {
            $html = view('exment::dashboard.system.'.array_get($item, 'name'))->render() ?? null;
        }
        
        return $html ?? null;
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form)
    {
        // show system item list
        $options = [];
        foreach (Define::DASHBOARD_BOX_SYSTEM_PAGES as $page) {
            $options[array_get($page, 'id')] = exmtrans('dashboard.dashboard_box_system_pages.'.array_get($page, 'name'));
        }
        $form->select('target_system_id', exmtrans("dashboard.dashboard_box_options.target_system_id"))
            ->required()
            ->options($options)
            ;
    }

    /**
     * saving event
     */
    public static function saving(&$form)
    {
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
