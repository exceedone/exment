<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Auth\Permission as Checker;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\PluginEventType;
use Exceedone\Exment\Enums\PluginButtonType;
use Exceedone\Exment\Enums\PluginCrudAuthType;
use Illuminate\Http\Request;

class PluginController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct()
    {
        $this->setPageInfo(exmtrans("plugin.header"), exmtrans("plugin.header"), exmtrans("plugin.description"), 'fa-plug');
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);

        if (\Exment::user()->hasPermission(Permission::PLUGIN_ALL)) {
            $content->row(view('exment::plugin.upload'));
        }

        $content->body($this->grid());
        return $content;
    }

    /**
     * execute batch
     *
     * @param Request $request
     * @param $id
     * @return false|\Illuminate\Http\RedirectResponse
     */
    public function executeBatch(Request $request, $id)
    {
        if (!\Exment::user()->hasPermission(Permission::PLUGIN_ACCESS)) {
            Checker::error();
            return false;
        }

        \Artisan::call('exment:batch', ['id' => $id]);

        admin_toastr(exmtrans('common.message.success_execute'));
        return back();
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Plugin());
        $grid->filter(function ($filter) {
            $filter->disableIdFilter();
            $filter->like('uuid', exmtrans("plugin.uuid"));
            $filter->like('plugin_name', exmtrans("plugin.plugin_name"));
            $filter->like('plugin_view_name', exmtrans("plugin.plugin_view_name"));

            $filter->like('author', exmtrans("plugin.author"));
            $filter->like('version', exmtrans("plugin.version"));
            $filter->like('active_flg', exmtrans("plugin.active_flg"))->radio(\Exment::getYesNoAllOption());
        });

        $grid->column('plugin_name', exmtrans("plugin.plugin_name"))->sortable();
        $grid->column('plugin_view_name', exmtrans("plugin.plugin_view_name"))->sortable();
        $grid->column('plugin_types', exmtrans("plugin.plugin_type"))->display(function ($plugin_types) {
            return implode(exmtrans('common.separate_word'), collect($plugin_types)->map(function ($plugin_type) {
                $enum = PluginType::getEnum($plugin_type);
                return $enum ? $enum->transKey("plugin.plugin_type_options") : null;
            })->toArray());
        })->sortable();
        $grid->column('author', exmtrans("plugin.author"));
        $grid->column('version', exmtrans("plugin.version"));
        $grid->column('active_flg', exmtrans("plugin.active_flg"))->display(function ($active_flg) {
            return \Exment::getTrueMark($active_flg);
        })->escape(false);

        $grid->disableCreateButton();
        $grid->disableExport();

        $grid->actions(function ($actions) {
            $actions->disableView();

            if ($actions->row->disabled_delete) {
                $actions->disableDelete();
            }
        });

        if (!\Exment::user()->hasPermission(Permission::PLUGIN_ALL)) {
            $grid->model()->whereIn('id', Plugin::getIdsHasSettingPermission());
        }

        return $grid;
    }

    //Function use to upload file and update or add new record
    protected function store(Request $request)
    {
        //Check file existed in Request
        if ($request->hasfile('fileUpload')) {
            return PluginInstaller::uploadPlugin($request->file('fileUpload'));
        }
        // if not exists, return back and message
        return back()->with('errorMess', exmtrans("plugin.help.errorMess"));
    }

    //Delete record from database (one or multi records)
    protected function destroy($id)
    {
        foreach (stringToArray($id) as $i) {
            if ($this->form($i, true)->destroy($i)) {
                $this->deleteFolder($i);
            } else {
                return response()->json([
                    'status' => false,
                    'message' => trans('admin.delete_failed'),
                ]);
            }
        }
        return response()->json([
            'status' => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }

    //Delete one or multi folder corresponds to the plugins
    protected function deleteFolder($id)
    {
        $idlist = explode(",", $id);
        foreach ($idlist as $id) {
            $plugin = Plugin::getEloquent($id);
            if (!isset($plugin)) {
                continue;
            }

            // get disk
            $disk = \Storage::disk(Define::DISKNAME_ADMIN);
            $folder = $plugin->getPath();
            if ($disk->exists($folder)) {
                $disk->deleteDirectory($folder);
            }
        }
    }

    //Check request when edit record to delete null values in event_triggers
    protected function update(Request $request, $id)
    {
        $plugin = Plugin::getEloquent($id);
        if (!$plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::error();
            return false;
        }

        if (isset($request->get('options')['event_triggers']) === true) {
            $event_triggers = $request->get('options')['event_triggers'];
            $options = $request->get('options');
            $event_triggers = array_filter($event_triggers, 'strlen');
            $options['event_triggers'] = $event_triggers;
            $request->merge(['options' => $options]);
        }
        return $this->form($id)->update($id);
    }

    /**
     * Make a form builder.
     *
     * @param $id
     * @param $isDelete
     * @return Form|false
     */
    protected function form($id = null, $isDelete = false)
    {
        $plugin = Plugin::getEloquent($id);
        if (!$plugin || !$plugin->hasPermission(Permission::PLUGIN_SETTING)) {
            Checker::notFoundOrDeny();
            return false;
        }
        $command_only = boolval(array_get($plugin, 'options.command_only'));

        // create form
        $form = new Form(new Plugin());
        $form->exmheader(exmtrans("common.basic_setting"))->hr();

        $form->display('uuid', exmtrans("plugin.uuid"));
        $form->display('plugin_name', exmtrans("plugin.plugin_name"));
        $form->display('plugin_view_name', exmtrans("plugin.plugin_view_name"));
        // create as label
        $form->display('plugin_types', exmtrans("plugin.plugin_type"))->with(function ($plugin_types) {
            return implode(exmtrans('common.separate_word'), collect($plugin_types)->map(function ($plugin_type) {
                return PluginType::getEnum($plugin_type)->transKey("plugin.plugin_type_options") ?? null;
            })->toArray());
        });
        $form->display('author', exmtrans("plugin.author"));
        $form->display('version', exmtrans("plugin.version"));
        $form->switchbool('active_flg', exmtrans("plugin.active_flg"));

        $form->exmheader(exmtrans("common.detail_setting"))->hr();
        $form->embeds('options', exmtrans("plugin.options.header"), function ($form) use ($plugin, $command_only) {
            if ($plugin->matchPluginType(PluginType::PLUGIN_TYPE_CUSTOM_TABLE())) {
                $form->multipleSelect('target_tables', exmtrans("plugin.options.target_tables"))->options(function ($value) {
                    $options = CustomTable::filterList(null, ['checkPermission' => false])->pluck('table_view_name', 'table_name')->toArray();
                    return $options;
                })->help(exmtrans("plugin.help.target_tables"));

                // only trigger
                $enumClass = null;
                if ($plugin->matchPluginType(PluginType::BUTTON)) {
                    $enumClass = PluginButtonType::class;
                } elseif ($plugin->matchPluginType(PluginType::EVENT)) {
                    $enumClass = PluginEventType::class;
                } elseif ($plugin->matchPluginType(PluginType::TRIGGER)) {
                    $enumClass = PluginEventTrigger::class;
                }

                if (isset($enumClass)) {
                    $form->multipleSelect('event_triggers', exmtrans("plugin.options.event_triggers"))
                    ->options($enumClass::transArray("plugin.options.event_trigger_options"))
                    ->help(exmtrans("plugin.help.event_triggers"));
                }
            }

            if ($plugin->matchPluginType(PluginType::PAGE)) {
                // Plugin_type = 'page'
                $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"));
            }

            if ($plugin->matchPluginType(PluginType::PLUGIN_TYPE_URL())) {
                $form->text('uri', exmtrans("plugin.options.uri"))->required();

                if ($plugin->matchPluginType(PluginType::PAGE)) {
                    $form->display('endpoint_page', exmtrans("plugin.options.endpoint_page"))->default($plugin->getRootUrl(PluginType::PAGE))->help(exmtrans("plugin.help.endpoint"));
                }
                if ($plugin->matchPluginType(PluginType::API)) {
                    $form->display('endpoint_api', exmtrans("plugin.options.endpoint_api"))->default($plugin->getRootUrl(PluginType::API))->help(exmtrans("plugin.help.endpoint"));
                }
                if ($plugin->matchPluginType(PluginType::CRUD)) {
                    // get all endpoints
                    $pluginClass = $this->getPluginClass($plugin);
                    if ($pluginClass) {
                        // get all url
                        $urls = [];
                        $endpoints = $pluginClass->getAllEndpoints();
                        // If not set endpoints, set empty endpoint.
                        if (is_nullorempty($endpoints)) {
                            $urls[] = $plugin->getRootUrl(PluginType::CRUD);
                        }
                        // else, set all endpoints.
                        else {
                            foreach ($pluginClass->getAllEndpoints() as $endpoint) {
                                $urls[] = url_join($plugin->getRootUrl(PluginType::CRUD), $endpoint);
                            }
                        }
                        $form->display('endpoint_crud', exmtrans("plugin.options.endpoint_crud"))
                            ->default(implode("<br/>", $urls))
                            ->escape(false)
                            ->help(exmtrans("plugin.help.endpoint"));
                    }
                }
            } elseif ($plugin->matchPluginType(PluginType::BATCH) && !$command_only) {
                $form->number('batch_hour', exmtrans("plugin.options.batch_hour"))
                    ->help(exmtrans("plugin.help.batch_hour") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more?id='.exmtrans('common.help.task_schedule_id'))))
                    ->default(3);

                $form->text('batch_cron', exmtrans("plugin.options.batch_cron"))
                    ->help(exmtrans("plugin.help.batch_cron") . sprintf(exmtrans("common.help.task_schedule"), getManualUrl('quickstart_more?id='.exmtrans('common.help.task_schedule_id'))))
                    ->rules('max:100');
            }

            if ($plugin->matchPluginType(PluginType::VIEW)) {
                $form->text('grid_menu_title', exmtrans("plugin.options.grid_menu_title"))
                    ->help(exmtrans("plugin.help.grid_menu_title"))
                    ->rules('max:50');
                $form->text('grid_menu_description', exmtrans("plugin.options.grid_menu_description"))
                    ->help(exmtrans("plugin.help.grid_menu_description"))
                    ->rules('max:200');
                $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"));
            }

            if ($plugin->matchPluginType(PluginType::CRUD)) {
                $pluginClass = $this->getPluginClass($plugin);
                if (isset($pluginClass) && !is_nullorempty($crudAuthType = $pluginClass->getAuthType())) {
                    if ($crudAuthType == PluginCrudAuthType::KEY) {
                        $form->text('crud_auth_key', $pluginClass->getAuthSettingLabel())
                            ->help($pluginClass->getAuthSettingHelp());
                    } elseif ($crudAuthType == PluginCrudAuthType::ID_PASSWORD) {
                        $form->text('crud_auth_id', $pluginClass->getAuthSettingLabel())
                            ->help($pluginClass->getAuthSettingHelp());
                        $form->encpassword('crud_auth_password', $pluginClass->getAuthSettingPasswordLabel())
                        ->updateIfEmpty()
                        ->help($pluginClass->getAuthSettingPasswordHelp());
                    } elseif ($crudAuthType == PluginCrudAuthType::OAUTH) {
                        $form->select('crud_auth_oauth')
                            ->options(function () {
                                return Model\LoginSetting::where('login_type', LoginType::OAUTH)
                                    ->pluck('login_view_name', 'id');
                            })
                            ->help($pluginClass->getAuthSettingHelp());
                    }
                }
            }

            if ($plugin->matchPluginType(PluginType::PLUGIN_TYPE_FILTER_ACCESSIBLE())) {
                $form->switchbool('all_user_enabled', exmtrans("plugin.options.all_user_enabled"))->help(exmtrans("plugin.help.all_user_enabled"));
            }
            if ($plugin->matchPluginType([PluginType::BUTTON, PluginType::TRIGGER, PluginType::DOCUMENT])) {
                $form->text('label', exmtrans("plugin.options.label"));
                $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"));
                $form->text('button_class', exmtrans("plugin.options.button_class"))->help(exmtrans("plugin.help.button_class"));
            }

            if ($plugin->matchPluginType([PluginType::EXPORT])) {
                $form->multipleSelect('export_types', exmtrans("plugin.options.export_types"))->options([
                    'all' => trans('admin.all'),
                    'current_page' => trans('admin.current_page'),
                ])->required()
                ->default(['all', 'current_page'])
                ->help(exmtrans("plugin.help.export_types"));
                $form->text('label', exmtrans("plugin.options.label"));
                $form->textarea('export_description', exmtrans("plugin.options.export_description"))->help(exmtrans("plugin.help.export_description"))->rows(3);
                $form->icon('icon', exmtrans("plugin.options.icon"))->help(exmtrans("plugin.help.icon"));
            }
        })->disableHeader();

        if (!$isDelete) {
            $this->setCustomOptionForm($plugin, $form);
        }

        $form->tools(function (Form\Tools $tools) use ($plugin, $id, $command_only) {
            if ($plugin->disabled_delete) {
                $tools->disableDelete();
            }

            $tools->append(view('exment::tools.button', [
                'href' => admin_url("plugin/edit_code/$id"),
                'label' => exmtrans('plugin.edit_plugin'),
                'icon' => 'fa-edit',
                'btn_class' => 'btn-warning',
            ]));

            if ($plugin->matchPluginType(PluginType::PAGE)) {
                $tools->append(view('exment::tools.button', [
                    'href' => admin_url($plugin->getRouteUri()),
                    'label' => exmtrans('plugin.show_plugin_page'),
                    'icon' => 'fa-desktop',
                    'btn_class' => 'btn-purple',
                ]));
            }

            if ($plugin->matchPluginType(PluginType::BATCH) && !$command_only) {
                $tools->append(view('exment::tools.button', [
                    'href' => admin_urls('plugin', $plugin->id, 'executeBatch'),
                    'label' => exmtrans('plugin.execute_plugin_batch'),
                    'icon' => 'fa-exclamation-circle',
                    'btn_class' => 'btn-purple',
                ]));
            }
        });

        $form->disableReset();
        $form->disableEditingCheck(false);
        return $form;
    }

    /**
     * Get plugin custom option
     *
     * @param Plugin|null $plugin
     * @return void
     */
    protected function setCustomOptionForm($plugin, &$form)
    {
        $pluginClass = $this->getPluginClass($plugin);
        if (!isset($pluginClass)) {
            return;
        }

        if (!$pluginClass->useCustomOption()) {
            return;
        }

        $form->exmheader(exmtrans("plugin.options.custom_options_header"))->hr();
        $form->embeds('custom_options', exmtrans("plugin.options.custom_options_header"), function ($form) use ($pluginClass) {
            $pluginClass->setCustomOptionForm($form);
        })->disableHeader();
    }


    /**
     * Get plguin class.
     * Plugin class is that user defined.
     *
     * @return mixed
     */
    protected function getPluginClass($plugin)
    {
        if (!isset($plugin)) {
            return;
        }

        $pluginClass = $plugin->getClass(null, ['throw_ex' => false, 'as_setting' => true]);
        if (!isset($pluginClass)) {
            return null;
        }

        return $pluginClass;
    }
}
