<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\PluginType;

class CustomViewMenuButton extends ModalTileMenuButton
{
    protected $custom_table;
    protected $current_custom_view;

    protected $addMenuList = true;

    // this views items, if already get, set this params. if this value is null, not init items.
    protected $items = null;

    public function __construct($custom_table, $current_custom_view = null, $addMenuList = true)
    {
        $this->custom_table = $custom_table;
        $this->current_custom_view = $current_custom_view;
        $this->addMenuList = $addMenuList;

        // init as Custom value's menu
        if ($addMenuList) {
            parent::__construct([
                'label' => exmtrans('custom_view.custom_view_button_label') . '&nbsp;:&nbsp;' . $current_custom_view->view_view_name,
                'icon' => 'fa-th-list',
                'button_class' => 'btn-default',
            ]);
            $this->modal_title = exmtrans("custom_view.custom_view_button_label");
        }
        // init as Custom view's new list
        else {
            parent::__construct([
                'label' => trans('admin.new'),
                'icon' => 'fa-plus',
                'button_class' => 'btn-success',
            ]);
            $this->modal_title = trans('admin.new');
        }
    }

    public function render()
    {
        if ($this->addMenuList) {
            $this->setMenu();
        }

        return parent::render();
    }

    /**
     * Set view menu list
     *
     * @return void
     */
    protected function setMenu()
    {
        $systemviews = [];
        $userviews = [];
        // get custom view
        $custom_views = $this->custom_table->custom_views;

        foreach ($custom_views as $v) {
            if ($v->view_kind_type == ViewKindType::FILTER) {
                continue;
            }
            if ($v->view_type == ViewType::USER) {
                $userviews[] = $v->toArray();
            } else {
                $systemviews[] = $v->toArray();
            }
        }

        $sort_options = config('exment.sort_custom_view_options', 0);
        $compare = $this->getCompare($sort_options);
        usort($userviews, $compare);
        usort($systemviews, $compare);


        $menulist = [];

        $baseUrl = admin_urls('data', $this->custom_table->table_name);
        $setMenuFunc = function ($headerLabel, $views, &$menulist) use ($baseUrl) {
            if (count($views) == 0) {
                return;
            }

            $menulist[] = [
                'header' => true,
                'label' => $headerLabel,
            ];

            foreach ($views as $view) {
                $menulist[] = [
                    'label' => array_get($view, 'view_view_name'),
                    'url' => "$baseUrl?view={$view['suuid']}",
                ];
            }
        };

        $setMenuFunc(exmtrans('custom_view.custom_view_type_options.system'), $systemviews, $menulist);
        $setMenuFunc(exmtrans('custom_view.custom_view_type_options.user'), $userviews, $menulist);

        // get menu setting only has items.
        if (!is_nullorempty($this->getItems())) {
            $menulist[] = [
                'header' => true,
                'label' => trans('admin.setting'),
            ];
            $menulist[] = [
                'label' => exmtrans('custom_view.custom_view_menulist.setting'),
                'isHtml' => true,
            ];
        }

        $this->menulist = $menulist;

        return;
    }

    public function html()
    {
        $items = $this->getItems();

        // if no menu, return
        if (count($items) == 0) {
            return null;
        }

        $this->groups = [[
            'items' => $items
        ]];

        return parent::html();
    }


    protected function getCompare(int $sort_options)
    {
        switch ($sort_options) {
            case 0:
                return function ($a, $b) {
                    $atype = array_get($a, 'view_kind_type');
                    $btype = array_get($b, 'view_kind_type');
        
                    if ($atype == ViewKindType::ALLDATA) {
                        return -1;
                    } elseif ($btype == ViewKindType::ALLDATA) {
                        return 1;
                    } else {
                        return $atype <=> $btype;
                    }
                };
            case 1:
                return function ($a, $b) {
                    $atype = array_get($a, 'view_kind_type');
                    $btype = array_get($b, 'view_kind_type');
    
                    if ($atype == $btype) {
                        $aorder = array_get($a, 'order');
                        $border = array_get($b, 'order');
                        return $aorder <=> $border;
                    } else {
                        if ($atype == ViewKindType::ALLDATA) {
                            return -1;
                        } elseif ($btype == ViewKindType::ALLDATA) {
                            return 1;
                        } else {
                            return $atype <=> $btype;
                        }
                    }
                };
            case 2:
                return function ($a, $b) {
                    $aorder = array_get($a, 'order');
                    $border = array_get($b, 'order');
                    return $aorder <=> $border;
                };
                        
        }
    }


    protected function getItems()
    {
        if (!is_null($this->items)) {
            return $this->items;
        }

        $items = [];

        //role check
        if ($this->custom_table->hasViewPermission()) {
            if (isset($this->current_custom_view)) {
                $query_str = '?view_kind_type='.$this->current_custom_view->view_kind_type.'&from_data=1';

                if ($this->current_custom_view->hasEditPermission()) {
                    $items[] = [
                        'href' => admin_urls('view', $this->custom_table->table_name, $this->current_custom_view->id, 'edit'.$query_str),
                        'header' => exmtrans('custom_view.custom_view_menulist.current_view_edit'),
                        'description' => exmtrans('custom_view.custom_view_menulist.help.current_view_edit'),
                        'icon' => 'fa-cog',
                    ];
                }

                $items[] = [
                    'href' => admin_urls('view', $this->custom_table->table_name, 'create?from_data=1&copy_id=' . $this->current_custom_view->id),
                    'header' => exmtrans('custom_view.custom_view_menulist.current_view_replicate'),
                    'description' => exmtrans('custom_view.custom_view_menulist.help.current_view_replicate'),
                    'icon' => 'fa-copy',
                ];
            }

            $items[] = [
                'href' => admin_urls('view', $this->custom_table->table_name, 'create?from_data=1'),
                'header' => exmtrans('custom_view.custom_view_menulist.create'),
                'description' => exmtrans('custom_view.custom_view_menulist.help.create'),
                'icon' => 'fa-list',
            ];
            $items[] = [
                'href' => admin_urls('view', $this->custom_table->table_name, 'create?view_kind_type=1&from_data=1'),
                'header' => exmtrans('custom_view.custom_view_menulist.create_sum'),
                'description' => exmtrans('custom_view.custom_view_menulist.help.create_sum'),
                'icon' => 'fa-bar-chart',
            ];
            $items[] = [
                'href' => admin_urls('view', $this->custom_table->table_name, 'create?view_kind_type=2&from_data=1'),
                'header' => exmtrans('custom_view.custom_view_menulist.create_calendar'),
                'description' => exmtrans('custom_view.custom_view_menulist.help.create_calendar'),
                'icon' => 'fa-calendar',
            ];

            if ($this->custom_table->hasSystemViewPermission()) {
                $items[] = [
                    'href' => admin_urls('view', $this->custom_table->table_name, 'create?view_kind_type=3&from_data=1'),
                    'header' => exmtrans('custom_view.custom_view_menulist.create_filter'),
                    'description' => exmtrans('custom_view.custom_view_menulist.help.create_filter'),
                    'icon' => 'fa-filter',
                ];
            }

            // Append grid plugins
            $plugins = Plugin::getPluginsByTable($this->custom_table, false)->filter(function ($plugin) {
                return $plugin->matchPluginType(PluginType::VIEW);
            });
            foreach ($plugins as $plugin) {
                $items[] = [
                    'href' => admin_urls_query('view', $this->custom_table->table_name, 'create', [
                        'view_kind_type' => ViewKindType::PLUGIN,
                        'plugin' => $plugin->uuid,
                        'from_data' => 1,
                    ]),
                    'header' => $plugin->getOption('grid_menu_title') ?? $plugin->plugin_view_name,
                    'description' => $plugin->getOption('grid_menu_description'),
                    'icon' => $plugin->getOption('icon') ?? 'fa-plug',
                ];
            }
        }

        $this->items = $items;
        return $items;
    }
}
