<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Auth\Permission as Checker;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\TemplateExportTarget;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\TemplateImportExport;
use Exceedone\Exment\Exceptions\PublicFormNotFoundException;
use Illuminate\Http\Request;

/**
 * Custom Form public
 */
class CustomFormPublicController extends AdminControllerTableBase
{
    use HasResourceTableActions;
    use NotifyTrait;

    protected $mailTemplates = [];

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_form_public.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_form_public.description"), 'fa-share-alt');
    }

    /**
     * @param Request $request
     * @param Content $content
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function index(Request $request, Content $content)
    {
        return redirect(admin_urls('form', $this->custom_table->table_name));
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        if (!isset($id) && request()->has('template')) {
            return $this->importForm();
        }
        return $this->basicForm($id);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return mixed
     */
    public function store()
    {
        if (request()->has('template')) {
            return $this->importFormStore();
        }
        return $this->form()->store();
    }

    /**
     * Make a form builder.
     *
     * @return Form|void
     */
    protected function basicForm($id = null)
    {
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }

        $form = new Form(new PublicForm());
        $form->disableValidate(); // Not working validation if tab.
        $public_form = PublicForm::find($id);
        $custom_table = $this->custom_table;

        // Basic setting ----------------------------------------------------
        $form->tab(exmtrans("common.basic_setting"), function ($form) use ($public_form, $custom_table) {
            $form->exmheader(exmtrans("common.basic_setting"))->hr();

            $form->descriptionHtml(exmtrans('common.help.more_help'));

            if (isset($public_form)) {
                if ($public_form->active_flg) {
                    $form->url('share_url', exmtrans('custom_form_public.share_url'))
                        ->attribute(['copyScript' => 1])
                        ->help(exmtrans('custom_form_public.help.share_url'))
                        ->default($public_form->getUrl())
                        ->readonly();
                    $form->ignore('share_url');

                    $form->display('proxy_user_id', exmtrans('common.executed_user'))->displayText(function ($user_id) {
                        return getUserName($user_id, true);
                    })->help(exmtrans('custom_form_public.help.proxy_user_id'))->escape(false);
                }

                $form->display('active_flg', exmtrans("plugin.active_flg"))->displayText(function ($value) {
                    return boolval($value) ? exmtrans('common.available_true') : exmtrans('common.available_false');
                })->help(exmtrans("custom_form_public.help.active_flg"));
            }



            $form->select('custom_form_id', exmtrans("custom_form_public.custom_form_id"))
                ->requiredRule()
                ->help(exmtrans("custom_form_public.help.custom_form_id"))
                ->options(function ($value) use ($custom_table) {
                    return $custom_table->custom_forms->mapWithKeys(function ($item) {
                        return [$item['id'] => $item['form_view_name']];
                    });
                });

            $form->text('public_form_view_name', exmtrans("custom_form_public.public_form_view_name"))
                ->requiredRule()
                ->rules("max:40")
                ->help(exmtrans('common.help.view_name'));

            $form->embeds("basic_setting", exmtrans("common.basic_setting"), function ($form) {
                $form->dateTimeRange('validity_period_start', 'validity_period_end', exmtrans("custom_form_public.validity_period"))
                    ->help(exmtrans("custom_form_public.help.validity_period"))
                    ->default(true);
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.design_setting"), function ($form) use ($id, $custom_table) {
            $form->embeds("design_setting", exmtrans("common.design_setting"), function ($form) use ($id, $custom_table) {
                $form->exmheader(exmtrans("custom_form_public.header_setting"))->hr();

                $form->switchbool('use_header', exmtrans("custom_form_public.use_header"))
                    ->help(exmtrans("custom_form_public.help.use_header"))
                    ->default(true)
                    ->attribute(['data-filtertrigger' => true])
                ;

                $form->color('header_background_color', exmtrans("custom_form_public.header_background_color"))
                    ->help(exmtrans("custom_form_public.help.header_background_color"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_header', 'value' => '1'])])
                    ->default('#3c8dbc')
                ;

                $fileOption = static::getFileOptions($custom_table, $id);
                $form->image('header_logo', exmtrans("custom_form_public.header_logo"))
                    ->help(exmtrans("custom_form_public.help.header_logo", ['size' => array_get($fileOption, 'maxFileSizeHelp')]) . exmtrans("common.message.cannot_preview", ['name' => exmtrans("custom_form_public.header_logo")]))
                    ->options($fileOption)
                    ->removable()
                    ->attribute(['accept' => "image/*"])
                    ->move("publicform/{$custom_table->table_name}")
                    ->callableName(function ($file) use ($custom_table) {
                        return \Exment::setFileInfo($this, $file, FileType::PUBLIC_FORM, $custom_table);
                    })
                    ->caption(function ($caption) {
                        $file = ExmentFile::getData($caption);
                        return $file->filename ?? basename($caption);
                    })
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_header', 'value' => '1'])])
                ;

                $form->text('header_label', exmtrans("custom_form_public.header_label"))
                    ->help(exmtrans("custom_form_public.help.header_label"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_header', 'value' => '1'])])
                ;

                $form->color('header_text_color', exmtrans("custom_form_public.header_text_color"))
                    ->help(exmtrans("custom_form_public.help.header_text_color"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_header', 'value' => '1'])])
                    ->default('#FFFFFF')
                ;


                $form->exmheader(exmtrans("custom_form_public.body_setting"))->hr();
                ;

                $form->color('background_color_outer', exmtrans("custom_form_public.background_color_outer"))
                    ->help(exmtrans("custom_form_public.help.background_color_outer"))
                    ->default('#FFFFFF')
                ;
                $form->color('background_color', exmtrans("custom_form_public.background_color"))
                    ->help(exmtrans("custom_form_public.help.background_color"))
                    ->default('#FFFFFF')
                ;

                $form->exmheader(exmtrans("custom_form_public.footer_setting"))->hr();
                $form->switchbool('use_footer', exmtrans("custom_form_public.use_footer"))
                    ->help(exmtrans("custom_form_public.help.use_footer"))
                    ->attribute(['data-filtertrigger' => true])
                    ->default(true);
                ;

                $form->color('footer_background_color', exmtrans("custom_form_public.footer_background_color"))
                    ->help(exmtrans("custom_form_public.help.footer_background_color"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_footer', 'value' => '1'])])
                    ->default('#000000')
                ;
                $form->color('footer_text_color', exmtrans("custom_form_public.footer_text_color"))
                    ->help(exmtrans("custom_form_public.help.footer_text_color"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_use_footer', 'value' => '1'])])
                    ->default('#FFFFFF')
                ;
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.confirm_complete_setting"), function ($form) use ($custom_table) {
            $form->embeds("confirm_complete_setting", exmtrans("common.confirm_complete_setting"), function ($form) {
                $form->exmheader(exmtrans("custom_form_public.confirm_setting"))->hr();

                $form->switchbool('use_confirm', exmtrans("custom_form_public.use_confirm"))
                    ->help(exmtrans("custom_form_public.help.use_confirm"))
                    ->attribute(['data-filtertrigger' => true])
                    ->default(true);
                ;
                $form->text('confirm_title', exmtrans("custom_form_public.confirm_title"))
                    ->help(exmtrans("custom_form_public.help.confirm_title"))
                    ->default(exmtrans("custom_form_public.message.confirm_title"))
                    ->attribute(['data-filter' => json_encode(['key' => 'confirm_complete_setting_use_confirm', 'value' => '1'])]);
                ;
                $form->textarea('confirm_text', exmtrans("custom_form_public.confirm_text"))
                    ->help((exmtrans("custom_form_public.help.confirm_text", ['url' => \Exment::getManualUrl('params')])))
                    ->default(exmtrans("custom_form_public.message.confirm_text"))
                    ->attribute(['data-filter' => json_encode(['key' => 'confirm_complete_setting_use_confirm', 'value' => '1'])])
                    ->rows(3);
                ;

                $form->exmheader(exmtrans("custom_form_public.complate_setting"))->hr();
                $form->text('complete_title', exmtrans("custom_form_public.complete_title"))
                    ->help(exmtrans("custom_form_public.help.complete_title"))
                    ->default(exmtrans("custom_form_public.message.complete_title"));
                ;
                $form->textarea('complete_text', exmtrans("custom_form_public.complete_text"))
                    ->help((exmtrans("custom_form_public.help.complete_text", ['url' => \Exment::getManualUrl('params')])))
                    ->default(exmtrans("custom_form_public.message.complete_text"))
                    ->rows(3);
                ;
                $form->url('complete_link_url', exmtrans("custom_form_public.complete_link_url"))
                    ->help(exmtrans("custom_form_public.help.complete_link_url"));
                ;
                $form->text('complete_link_text', exmtrans("custom_form_public.complete_link_text"))
                    ->help(exmtrans("custom_form_public.help.complete_link_text"));
                ;

                $form->exmheader(exmtrans("custom_form_public.notify_complete_user"))->hr();

                $form->description(exmtrans("custom_form_public.help.notify_complete_user"));

                $form->switchbool('use_notify_complete_user', exmtrans("custom_form_public.use_notify_complete_user"))
                    ->help(exmtrans("custom_form_public.help.use_notify_complete_user"))
                    ->default(false);
                ;
            })->disableHeader();

            // get notify mail template
            $this->setNotifyMailTemplate($form, 'notify_mail_template_complete_user', MailKeyName::PUBLICFORM_COMPLETE_USER, 'confirm_complete_setting_use_notify_complete_user');
            $form->embeds("notify_actions_complete_user", exmtrans("common.confirm_complete_setting"), function ($form) use ($custom_table) {
                $form->internal('notify_action')
                    ->default(NotifyAction::EMAIL);

                $form->multipleSelect('notify_action_target', exmtrans("notify.notify_action_target"))
                ->options(function ($val, $field, $notify) use ($custom_table) {
                    $options = [
                        'as_default' => false,
                        'get_email' => true,
                        'get_select_table_email' => false,
                        'as_fixed_email' => false,
                    ];
                    return collect(NotifyService::getNotifyTargetColumns($custom_table, array_get($field->data(), 'notify_action'), $options))
                        ->pluck('text', 'id');
                })
                ->help(exmtrans("custom_form_public.help.notify_action_target_complete_user"));
            })->disableHeader();


            $form->embeds("confirm_complete_setting2", exmtrans("common.confirm_complete_setting"), function ($form) {
                $form->exmheader(exmtrans("custom_form_public.notify_complete_admin"))->hr();
                $form->description(exmtrans("custom_form_public.help.notify_complete_admin"));

                $form->switchbool('use_notify_complete_admin', exmtrans("custom_form_public.use_notify_complete_admin"))
                    ->help(exmtrans("custom_form_public.help.use_notify_complete_admin"))
                    ->default(false);
                ;
            })->disableHeader();

            // get notify mail template
            $this->setNotifyMailTemplate($form, 'notify_mail_template_complete_admin', MailKeyName::PUBLICFORM_COMPLETE_ADMIN, 'use_notify_complete_admin');

            $form->exmheader(exmtrans("notify.notify_action_target"))->no(5);
            $form->hasManyJson('notify_actions_complete_admin', exmtrans("custom_form_public.notify_target"), function ($form) use ($custom_table) {
                $form->select('notify_action', exmtrans("notify.notify_action"))
                    ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
                    ->required()
                    ->disableClear()
                    ->attribute([
                        'data-filtertrigger' =>true,
                        'data-linkage' => json_encode([
                            'notify_action_target' => admin_urls('formpublic', $this->custom_table->table_name, 'notify_action_target'),
                        ]),
                    ])
                    ->help(exmtrans("notify.help.notify_action"))
                ;

                $this->setActionForm($form, null, $custom_table, null, [
                    'as_default' => false,
                    'get_custom_columns' => false,
                    'as_administrator' => true, // Only use "as_default" is false
                    'as_has_roles' => true, // Only use "as_default" is false
                ]);
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.error_setting"), function ($form) use ($custom_table) {
            $form->embeds("error_setting", exmtrans("common.confirm_complete_setting"), function ($form) {
                $form->exmheader(exmtrans("custom_form_public.error_setting"))->hr();
                $form->text('error_title', exmtrans("custom_form_public.error_title"))
                    ->help(exmtrans("custom_form_public.help.error_title"))
                    ->default(exmtrans("custom_form_public.message.error_title"));
                ;
                $form->textarea('error_text', exmtrans("custom_form_public.error_text"))
                    ->help(exmtrans("custom_form_public.help.error_text"))
                    ->default(exmtrans("custom_form_public.message.error_text"))
                    ->rows(3);
                ;
                $form->url('error_link_url', exmtrans("custom_form_public.error_link_url"))
                    ->help(exmtrans("custom_form_public.help.error_link_url"));
                ;
                $form->text('error_link_text', exmtrans("custom_form_public.error_link_text"))
                    ->help(exmtrans("custom_form_public.help.error_link_text"));
                ;

                $form->exmheader(exmtrans("custom_form_public.notify_error"))->hr();
                $form->description(exmtrans("custom_form_public.help.notify_error"));
                $form->switchbool('use_notify_error', exmtrans("custom_form_public.use_notify_error"))
                    ->help(exmtrans("custom_form_public.help.use_notify_error"))
                    ->default(false);
                ;
            })->disableHeader();

            // get notify mail template
            $this->setNotifyMailTemplate($form, 'notify_mail_template_error', MailKeyName::PUBLICFORM_ERROR, 'error_setting_use_notify_error');

            $form->exmheader(exmtrans("notify.notify_action_target"))->no(5);
            $form->hasManyJson('notify_actions_error', exmtrans("custom_form_public.notify_target"), function ($form) use ($custom_table) {
                $form->select('notify_action', exmtrans("notify.notify_action"))
                    ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
                    ->required()
                    ->disableClear()
                    ->attribute([
                        'data-filtertrigger' =>true,
                        'data-linkage' => json_encode([
                            'notify_action_target' => admin_urls('formpublic', $this->custom_table->table_name, 'notify_action_target'),
                        ]),
                    ])
                    ->help(exmtrans("notify.help.notify_action"))
                ;

                $this->setActionForm($form, null, $custom_table, null, [
                    'as_default' => false,
                    'get_custom_columns' => false,
                    'as_administrator' => true, // Only use "as_default" is false
                    'as_has_roles' => true, // Only use "as_default" is false
                ]);
            })->disableHeader();
        })
        ->tab(exmtrans("custom_form_public.css_js_setting"), function ($form) {
            $form->exmheader(exmtrans("custom_form_public.css_js_setting"))->hr();

            $form->embeds("css_js_setting", exmtrans("common.css_js_setting"), function ($form) {
                $form->textarea('custom_css', exmtrans("custom_form_public.custom_css"))
                    ->help(exmtrans("custom_form_public.help.custom_css"))
                ;
                $form->multipleSelect('plugin_css', exmtrans("custom_form_public.plugin_css"))
                    ->help(exmtrans("custom_form_public.help.plugin_css"))
                    ->options(function () {
                        return Plugin::getByPluginTypes(PluginType::STYLE)->pluck('plugin_view_name', 'id')->toArray();
                    })
                ;
                $form->textarea('custom_js', exmtrans("custom_form_public.custom_js"))
                    ->help(exmtrans("custom_form_public.help.custom_js"))
                ;
                $form->multipleSelect('plugin_js', exmtrans("custom_form_public.plugin_js"))
                    ->help(exmtrans("custom_form_public.help.plugin_js"))
                    ->options(function () {
                        return Plugin::getByPluginTypes(PluginType::SCRIPT)->pluck('plugin_view_name', 'id')->toArray();
                    })
                ;
            })->disableHeader();
        })
        ->tab(exmtrans("custom_form_public.option_setting"), function ($form) {
            $form->exmheader(exmtrans("custom_form_public.option_setting"))->hr();

            $form->embeds("option_setting", exmtrans("common.option_setting"), function ($form) {
                $form->switchbool('use_default_query', exmtrans("custom_form_public.use_default_query"))
                    ->help(exmtrans("custom_form_public.help.use_default_query") . \Exment::getMoreTag('publicform'))
                    ->default(false);
                ;

                $form->text('analytics_tag', exmtrans("custom_form_public.analytics_tag"))
                    ->rules(['nullable', 'regex:/^(UA-|G-)/u'])
                    ->help(exmtrans("custom_form_public.help.analytics_tag"));
                ;

                if (($message = PublicForm::isEnableRecaptcha()) === true) {
                    $form->switchbool('use_recaptcha', exmtrans("custom_form_public.use_recaptcha"))
                        ->help(exmtrans("custom_form_public.help.use_recaptcha"))
                        ->default(false);
                    ;
                } else {
                    $form->display('use_recaptcha_display', exmtrans("custom_form_public.use_recaptcha"))
                        ->displayText($message)
                        ->escape(false);
                }
            })->disableHeader();
        })
        ;

        $this->setFormInfo($form, $id, $public_form);

        return $form;
    }



    /**
     * Make a form builder, for import.
     *
     * @return Form|void
     */
    protected function importForm()
    {
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }

        $form = new Form(new PublicForm());
        $custom_table = $this->custom_table;

        $transes = ['header_logo', 'analytics_tag', 'use_recaptcha'];
        $message = collect($transes)->map(function ($trans) {
            return "<li>" . exmtrans("custom_form_public.$trans") . "</li>";
        })->implode('');
        $form->description('<div class="red">' . exmtrans('custom_form_public.message.template_import_caution', ['list' => $message]) . '</div>')
            ->escape(false);

        $form->select('custom_form_id', exmtrans("custom_form_public.custom_form_id"))
            ->requiredRule()
            ->help(exmtrans("custom_form_public.help.custom_form_id"))
            ->options(function ($value) use ($custom_table) {
                return $custom_table->custom_forms->mapWithKeys(function ($item) {
                    return [$item['id'] => $item['form_view_name']];
                });
            });

        $form->text('public_form_view_name', exmtrans("custom_form_public.public_form_view_name"))
            ->requiredRule()
            ->rules("max:40")
            ->help(exmtrans('common.help.view_name'));

        $form->file('upload_template', exmtrans('template.upload_template'))
            ->rules('mimes:zip')
            ->attribute(['accept' => ".zip"])
            ->help(exmtrans('custom_form_public.help.upload_template'))
            ->removable()
            ->required()
            ->options(Define::FILE_OPTION());

        $this->setFormInfo($form, null, null, false);

        $form->hidden('template')->default(1);
        $form->ignore('template');

        return $form;
    }


    protected function setFormInfo($form, $id, $public_form, $preview = true)
    {
        $custom_table = $this->custom_table;
        $form->editing(function ($form, $arr) {
            $form->model()->append([
                'basic_setting',
                'design_setting',
                'confirm_complete_setting',
                'confirm_complete_setting2',
                'error_setting',
                'css_js_setting',
                'option_setting',
                'notify_actions_error',
                'notify_mail_template_error',
                'notify_actions_complete_user',
                'notify_mail_template_complete_user',
                'notify_actions_complete_admin',
                'notify_mail_template_complete_admin',
            ]);
        });
        $form->disableEditingCheck(false);

        $form->tools(function (Form\Tools $tools) use ($custom_table, $id, $public_form, $preview) {
            $tools->add(new Tools\CustomTableMenuButton('form', $custom_table));
            $tools->setListPath(admin_urls('form', $custom_table->table_name));

            if (isset($public_form)) {
                if (!$public_form->active_flg) {
                    // check relation table's count. if has select_table etc, showing modal.
                    if ($public_form->getListOfTablesUsed()->count() > 0) {
                        $tools->append(new Tools\ModalMenuButton(
                            admin_urls("formpublic", $custom_table->table_name, $public_form->id, "activeModal"),
                            [
                                'label' => exmtrans('common.activate'),
                                'button_class' => 'btn-success',
                                'icon' => 'fa-check-circle',
                            ]
                        ));
                    }
                    // default, only message.
                    else {
                        $tools->append(new Tools\SwalInputButton([
                            'url' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "activate"),
                            'label' => exmtrans('common.activate'),
                            'icon' => 'fa-check-circle',
                            'btn_class' => 'btn-success',
                            'title' => exmtrans('common.activate'),
                            'text' => exmtrans('custom_form_public.message.activate'),
                            'method' => 'post',
                            'redirectUrl' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "edit"),
                        ]));
                    }
                } else {
                    $tools->append(new Tools\SwalInputButton([
                        'url' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "deactivate"),
                        'label' => exmtrans('common.deactivate'),
                        'icon' => 'fa-check-circle',
                        'btn_class' => 'btn-default',
                        'title' => exmtrans('common.deactivate'),
                        'text' => exmtrans('custom_form_public.message.deactivate'),
                        'method' => 'post',
                        'redirectUrl' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "edit"),
                    ]));
                }
            }

            if ($preview) {
                $tools->append(view('exment::tools.button', [
                    'href' => 'javascript:void(0);',
                    'label' => exmtrans('common.preview'),
                    'icon' => 'fa-check-circle',
                    'btn_class' => 'btn-warning',
                    'attributes' => [
                        'data-preview' => true,
                        'data-preview-url' => admin_urls('formpublic', $custom_table->table_name, $id, 'preview'),
                        'data-preview-error-title' => '',
                        'data-preview-error-text' => '',
                    ],
                ])->render());

                if (isset($id)) {
                    $tools->append(view('exment::tools.button', [
                        'href' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "export"),
                        'label' => exmtrans('template.header_export'),
                        'icon' => 'fa-clone',
                        'btn_class' => 'btn-warning',
                        'attributes' => [
                            'target' => '_blank',
                        ],
                    ])->render());
                }
            }
        });

        $table_name = $this->custom_table->table_name;

        $form->saved(function ($form) use ($table_name) {
            admin_toastr(trans('admin.update_succeeded'));
            if (!is_nullorempty(request()->get('after-save'))) {
                return;
            }
            return redirect(admin_url("form/$table_name"));
        });
    }


    protected function setNotifyMailTemplate($form, string $field_name, ?string $notify_mail_template, string $filter_key_name)
    {
        if (\is_nullorempty($this->mailTemplates)) {
            $this->mailTemplates = getModelName(SystemTableName::MAIL_TEMPLATE)::all()->pluck('label', 'id');
        }

        // get notify mail template
        $notify_mail = $notify_mail_template ? getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $notify_mail_template)->first() : null;
        $form->select($field_name, exmtrans("notify.mail_template_id"))
            ->options($this->mailTemplates)
            ->help(exmtrans("notify.help.mail_template_id"))
            ->disableClear()
            ->default($notify_mail ? $notify_mail->id : null);
    }


    /**
     * Store form for import
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    protected function importFormStore()
    {
        $request = request();
        $form = $this->importForm();
        if (($response = $form->validateRedirect($request->all())) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        // get json from zip
        $importer = new TemplateImportExport\TemplateImporter();
        $file = $request->file('upload_template');
        $json = $importer->getJsonFromZip($file);

        $public_form = null;
        \ExmentDB::transaction(function () use (&$public_form, $json, $request) {
            // new json ignored notify
            $json_ignored = array_get($json, 'public_form');
            array_forget($json_ignored, ['notify_complete_admin', 'notify_complete_user', 'notify_error']);
            $public_form = new PublicForm($json_ignored);
            $public_form->fill($request->all([
                'custom_form_id',
                'public_form_view_name',
            ]));
            $public_form->save();

            // create notify after saved
            $public_form->createNotifyImported(array_get($json, 'public_form'));
            $public_form->setPluginImported(array_get($json, 'public_form'));
        });

        /** @phpstan-ignore-next-line fix laravel-admin documentation */
        return $form->setModel($public_form)->redirectAfterStore();
    }

    /**
     * Showing preview
     *
     * @param Request $request
     * @param $tableKey
     * @param string|int|null $id If already saved model, set id
     * @return PublicContent
     * @throws PublicFormNotFoundException
     */
    public function preview(Request $request, $tableKey, $id = null)
    {
        $original_public_form = PublicForm::find($id);
        // get this form's info
        $form = $this->form();

        $model = $form->getModelByInputs(null, $original_public_form);

        // Now, cannot set header logo by getModelByInputs.
        if ($original_public_form) {
            /** @phpstan-ignore-next-line fix laravel-admin documentation */
            $model->setOption('header_logo', $original_public_form->getOption('header_logo'));
        }

        // get public form
        /** @phpstan-ignore-next-line fix laravel-admin documentation */
        $preview_form = $model->getForm($request);
        if (!$preview_form) {
            throw new PublicFormNotFoundException();
        }
        $preview_form->disableSubmit();

        // add admin url etc
        foreach (\Exceedone\Exment\Form\Navbar\Hidden::getHiddenItemsCommon() as $key => $value) {
            $preview_form->hidden($key)->default($value)
                ->attribute('id', $key);
        }

        // set content
        $content = new PublicContent();
        /** @phpstan-ignore-next-line fix laravel-admin documentation */
        $model->setContentOption($content);
        $content->row($preview_form);

        admin_info(exmtrans('common.preview'), exmtrans('common.message.preview'));

        return $content;
    }


    /**
     * file delete. Now only header_logo, If other file, todo refactor.
     */
    public function filedelete(Request $request, $tableKey, $id)
    {
        // If not id, sot saving, so return nothing doing.
        $trueResult = getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
        if (!$id) {
            return $trueResult;
        }

        $original_public_form = PublicForm::find($id);
        if (!$original_public_form) {
            return $trueResult;
        }

        $uri = $original_public_form->getOption('header_logo');
        if (!$uri) {
            return $trueResult;
        }

        ExmentFile::deleteFileInfo($uri);

        $original_public_form->forgetOption('header_logo')
            ->save();

        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.delete_succeeded'),
        ]);
    }


    // Active・DeActive ----------------------------------------------------
    /**
     * get copy modal
     */
    public function activeModal(Request $request, $tableKey, $id)
    {
        $public_form = PublicForm::find($id);
        if (!isset($public_form)) {
            abort(404);
        }

        // create form fields
        $form = new ModalForm();
        $form->action(admin_urls("formpublic", $this->custom_table->table_name, $public_form->id, "activate"));
        $form->method('POST');

        // add form
        $form->display('foobar', trans('admin.alert'))
            ->displayText(exmtrans('custom_form_public.help.activate_modal_header'))
            ->escape(false);

        $tableUseds = $public_form->getListOfTablesUsed();
        $html = "<ul>" . $tableUseds->map(function ($tableUsed) {
            return "<li>" . esc_html($tableUsed->table_view_name) . "</li>";
        })->implode("") . "</ul>";
        $form->descriptionHtml($html);

        $form->description(exmtrans('custom_form_public.help.activate_modal_footer'));

        $form->setWidth(10, 2);

        return getAjaxResponse([
            'body'  => $form->render(),
            'script' => $form->getScript(),
            'title' => trans('admin.alert'),
            'submitlabel' => trans('admin.setting'),
        ]);
    }


    /**
     * Active form
     *
     * @param Request $request
     * @param string|int|null $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function activate(Request $request, $tableKey, $id)
    {
        return $this->toggleActivate($request, $id, true);
    }

    /**
     * Deactive form
     *
     * @param Request $request
     * @param string|int|null $id
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function deactivate(Request $request, $tableKey, $id)
    {
        return $this->toggleActivate($request, $id, false);
    }

    /**
     * export
     *
     * @param Request $request
     * @param $tableKey
     * @param $id
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function export(Request $request, $tableKey, $id)
    {
        $public_form = PublicForm::find($id);

        // execute export
        return TemplateImportExport\TemplateExporter::exportTemplate(
            make_uuid(),
            $public_form->public_form_view_name,
            null,
            null,
            [
                'export_target' => [TemplateExportTarget::PUBLIC_FORM],
                'public_form_uuid' => $public_form->uuid,
                'zip_name' => $public_form->public_form_view_name,
            ]
        );
    }

    /**
     * Toggle activate and deactivate
     *
     * @param Request $request
     * @param $id
     * @param bool $active_flg
     * @return \Symfony\Component\HttpFoundation\Response|void
     */
    protected function toggleActivate(Request $request, $id, bool $active_flg)
    {
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }

        $public_form = PublicForm::find($id);
        $public_form->active_flg = $active_flg;
        $public_form->save();

        return getAjaxResponse([
            'result'  => true,
            'toastr' => trans('admin.update_succeeded'),
        ]);
    }


    public function notify_action_target(Request $request)
    {
        $options = NotifyService::getNotifyTargetColumns($this->custom_table, $request->get('q'), [
            'as_default' => false,
            'get_custom_columns' => false,
            'as_administrator' => true, // Only use "as_default" is false
            'as_has_roles' => true, // Only use "as_default" is false
        ]);

        return $options;
    }


    protected static function getFileOptions($custom_table, $id)
    {
        return array_merge(
            Define::FILE_OPTION(),
            [
                'showPreview' => true,
                'deleteUrl' => admin_urls('formpublic', $custom_table->table_name, $id, 'filedelete'),
                'deleteExtraData'      => [
                    Field::FILE_DELETE_FLAG         => 'header_logo',
                    '_token'                         => csrf_token(),
                    '_method'                        => 'PUT',
                ],
                'deletedEvent' => 'Exment.CommonEvent.CallbackExmentAjax(jqXHR.responseJSON);',
            ]
        );
    }

    /**
     * validation table
     * @param mixed $table id or customtable
     */
    protected function validateTable($table, $role_name)
    {
        $table = CustomTable::getEloquent($table);
        if (boolval($table->getOption('one_record_flg'))) {
            Checker::error(exmtrans("custom_form_public.message.cannot_set_master_table"));
            return false;
        }
        if (in_array($this->custom_table->table_name, SystemTableName::SYSTEM_TABLE_NAME_MASTER())) {
            Checker::error(exmtrans("custom_form_public.message.cannot_set_master_table"));
            return false;
        }
        return parent::validateTable($table, $role_name);
    }
}
