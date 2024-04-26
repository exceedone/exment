<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Exceedone\Exment\Model\DashboardBox;
use Exceedone\Exment\Model\Define;
use Encore\Admin\Grid\Linker;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar as NotifyNavbarModel;
use Encore\Admin\Widgets\Table as WidgetTable;
use Illuminate\Support\Collection;

class NotifyNavbar
{
    protected $dashboard_box;

    /**
     * WordPress Page Items
     *
     * @var array|Collection
     */
    protected $items = [];

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
        $link = admin_url('notify_navbar');
        $label = trans('admin.list');
        return "<div style='padding:8px;'><a href='{$link}'>{$label}</a></div>";
    }

    /**
     * get html body
     */
    public function body()
    {
        $this->setItems();

        // get table items
        $headers = [
            exmtrans('notify_navbar.read_flg'),
            exmtrans('notify_navbar.parent_type'),
            exmtrans('notify_navbar.notify_subject'),
            exmtrans('common.created_at'),
            trans('admin.action'),
        ];
        $bodies = [];

        foreach ($this->items as $item) {
            $body = [];

            $read_flg = array_get($item, 'read_flg') ?? 0;
            $body[] = exmtrans("notify_navbar.read_flg_options.$read_flg");

            $parent_type = array_get($item, 'parent_type');
            if (is_null($parent_type) || is_null($custom_table = CustomTable::getEloquent($parent_type))) {
                $body[] = null;
            } else {
                $body[] =  $custom_table->table_view_name;
            }

            $body[] =  array_get($item, 'notify_subject');
            $body[] =  array_get($item, 'created_at');


            // reference target data
            $html = '';
            if (array_key_value_exists('parent_id', $item)) {
                $linker = (new Linker())
                    ->url(admin_url("notify_navbar/rowdetail/{$item->id}"))
                    ->icon('fa-list')
                    ->tooltip(exmtrans('notify_navbar.data_refer'));
                $html .= $linker->render();
            }

            $linker = (new Linker())
                ->url(admin_url("notify_navbar/{$item->id}"))
                ->icon('fa-eye')
                ->tooltip(trans('admin.show'));
            $html .= $linker->render();

            $html .= '<input type="hidden" data-id="' . esc_html($item->id) . '">';
            $body[] =  $html;

            $bodies[] = $body;
        }

        $widgetTable = new WidgetTable($headers, $bodies);
        $widgetTable->class('table table-hover');

        /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
        return $widgetTable->render() ?? null;
    }

    /**
     * Set exment news items
     *
     * @return void
     */
    protected function setItems()
    {
        if (!\is_nullorempty($this->items)) {
            return;
        }

        try {
            $pager_count = $this->dashboard_box->getOption('pager_count');
            if (!isset($pager_count) || $pager_count == 0) {
                $pager_count = System::datalist_pager_count() ?? 5;
            }

            $this->items = NotifyNavbarModel::take($pager_count)->get();
        } catch (\Exception $ex) {
            \Log::error($ex);
        }
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        $grid_per_pages = stringToArray(config('exment.grid_per_pages'));
        if (empty($grid_per_pages)) {
            $grid_per_pages = Define::PAGER_DATALIST_COUNTS;
        }

        $form->select('pager_count', trans("admin.show"))
            ->required()
            ->options(getPagerOptions(true, $grid_per_pages))
            ->disableClear()
            ->default(0);
    }
}
