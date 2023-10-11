<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\DashboardBoxItems\SystemItems\Editor;
use Exceedone\Exment\DashboardBoxItems\SystemItems\Guideline;
use Exceedone\Exment\DashboardBoxItems\SystemItems\News;
use Exceedone\Exment\DashboardBoxItems\SystemItems\NotifyNavbar;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Model\Notify;

class SystemItem implements ItemInterface
{
    protected $dashboard_box;
    protected $systemItem;

    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;

        $item = collect(DashboardBoxSystemPage::options())->first(function ($value) {
            return array_get($value, 'id') == array_get($this->dashboard_box, 'options.target_system_id');
        });
        if (!isset($item)) {
            return;
        }

        // get class
        $class = $item['class'];
        $this->systemItem = new $class($this->dashboard_box);
    }

    /**
     * get header
     */
    public function header()
    {
        return $this->systemItem->header();
    }

    /**
     * get footer
     */
    public function footer()
    {
        return $this->systemItem->footer();
    }

    /**
     * get html body
     */
    public function body()
    {
        return $this->systemItem->body();
    }

    /**
     * get dashboard attributes for display html
     *
     * @return array
     */
    public function attributes()
    {
        $target_system_id = $this->dashboard_box->getOption('target_system_id');
        $enum = isset($target_system_id) ? DashboardBoxSystemPage::getEnum($target_system_id) : null;
        $target_system_name = isset($enum) ? $enum->option()['name'] : null;

        return [
            'target_system_name' => $target_system_name
        ];
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        // show system item list
        $options = [];
        foreach (DashboardBoxSystemPage::options() as $page) {
            $options[array_get($page, 'id')] = exmtrans('dashboard.dashboard_box_system_pages.'.array_get($page, 'name'));
        }
        $form->select('target_system_id', exmtrans("dashboard.dashboard_box_options.target_system_id"))
            ->required()
            ->attribute(['data-filtertrigger' =>true])
            ->options($options)
        ;

        // set embed options
        foreach (DashboardBoxSystemPage::options() as $page) {
            /** @var Guideline|News|Editor|Notify|NotifyNavbar|null $classname */
            $classname = array_get($page, 'class');
            if (isset($classname) && method_exists($classname, "setAdminOptions")) {
                /** @var Editor|NotifyNavbar $classname */
                $classname::setAdminOptions($form, $dashboard);
            }
        }
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
