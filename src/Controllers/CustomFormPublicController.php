<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\PublicContent;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Illuminate\Http\Request;

/**
 * Custom Form public
 */
class CustomFormPublicController extends AdminControllerTableBase
{
    use HasResourceTableActions;

    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans("custom_form_public.header") . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans("custom_form_public.description"), 'fa-share-alt');
    }

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
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }

        $form = new Form(new PublicForm);
        $public_form = PublicForm::find($id);
        $custom_table = $this->custom_table;

        // Basic setting ----------------------------------------------------
        $form->tab(exmtrans("common.basic_setting"), function ($form) use ($public_form, $id, $custom_table) {
            $form->exmheader(exmtrans("common.basic_setting"))->hr();
                
            if(isset($public_form)){
                $form->descriptionHtml(exmtrans('common.help.more_help'));
            
                $form->url('share_url', exmtrans('custom_form_public.share_url'))
                    ->setElementClass(['copyScript'])
                    ->help(exmtrans('custom_form_public.help.share_url'))
                    ->default($public_form->getUrl())
                    ->readonly();
                $form->ignore('share_url');

                $form->display('proxy_user_id', exmtrans('common.executed_user'))->displayText(function ($user_id) {
                    return getUserName($user_id, true);
                })->help(exmtrans('custom_form_public.help.proxy_user_id'))->escape(false);

                $form->display('active_flg', exmtrans("plugin.active_flg"))->displayText(function ($value) {
                    return boolval($value) ? exmtrans('common.available_true') : exmtrans('common.available_false');
                })->help(exmtrans("custom_form_public.help.active_flg"));
            }

            
            
            $form->select('custom_form_id', exmtrans("custom_form_public.custom_form_id"))
                ->tabRequired()
                ->help(exmtrans("custom_form_public.help.custom_form_id"))
                ->options(function ($value) use ($custom_table) {
                    return $custom_table->custom_forms->mapWithKeys(function ($item) {
                        return [$item['id'] => $item['form_view_name']];
                    });
                });
                
            $form->text('public_form_view_name', exmtrans("custom_form_public.public_form_view_name"))
                ->required()
                ->rules("max:40")
                ->help(exmtrans('common.help.view_name'));
            
            $form->embeds("basic_setting", exmtrans("common.basic_setting"), function($form) use ($custom_table){
                
                $form->dateTimeRange('validity_period_start', 'validity_period_end', exmtrans("custom_form_public.validity_period"))
                    ->help(exmtrans("custom_form_public.help.validity_period"))
                    ->default(true);

            })->disableHeader();
        })->tab(exmtrans("custom_form_public.design_setting"), function ($form) {
            $form->embeds("design_setting", exmtrans("common.design_setting"), function($form){
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

                $form->image('header_logo', exmtrans("custom_form_public.header_logo"))
                    ->help(exmtrans("custom_form_public.help.header_logo"))
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
                $form->radio('body_content_type', exmtrans("custom_form_public.body_content_type"))
                    ->help(exmtrans("custom_form_public.help.body_content_type"))
                    ->options([
                        'width100' => exmtrans("custom_form_public.body_content_type_options.width100"), 
                        'centering' => exmtrans("custom_form_public.body_content_type_options.centering"), 
                    ])
                    ->attribute(['data-filtertrigger' => true])
                    ->default('width100');
                    ;
                
                $form->color('background_color_outer', exmtrans("custom_form_public.background_color_outer"))
                    ->help(exmtrans("custom_form_public.help.background_color_outer"))
                    ->attribute(['data-filter' => json_encode(['key' => 'design_setting_body_content_type', 'value' => 'centering'])])
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
        })->tab(exmtrans("custom_form_public.confirm_complete_setting"), function ($form) {
            $form->embeds("confirm_complete_setting", exmtrans("common.confirm_complete_setting"), function($form){
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
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.error_setting"), function ($form) {
            $form->embeds("error_setting", exmtrans("common.confirm_complete_setting"), function($form){
                $form->exmheader(exmtrans("custom_form_public.error_setting"))->hr();

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

                $form->switchbool('use_error_notify', exmtrans("custom_form_public.use_error_notify"))
                    ->help(exmtrans("custom_form_public.help.use_error_notify"))
                    ->default(false);
                ;
            })->disableHeader();
        })
        ->tab(exmtrans("custom_form_public.option_setting"), function ($form) use ($public_form, $id, $custom_table) {
            $form->exmheader(exmtrans("custom_form_public.option_setting"))->hr();
             
            $form->embeds("option_setting", exmtrans("common.option_setting"), function($form) use ($custom_table){
                $form->text('analytics_tag', exmtrans("custom_form_public.analytics_tag"))
                    ->rules(['nullable', 'regex:/^(UA-|G-)/u'])
                    ->help(exmtrans("custom_form_public.help.analytics_tag"));
                ;
                
                if(($message = PublicForm::isEnableRecaptcha()) === true){
                    $form->switchbool('use_recaptcha', exmtrans("custom_form_public.use_recaptcha"))
                        ->help(exmtrans("custom_form_public.help.use_recaptcha"))
                        ->default(false);
                    ;
                }
                else{
                    $form->display('use_recaptcha_display', exmtrans("custom_form_public.use_recaptcha"))
                        ->displayText($message)
                        ->escape(false);
                }
                
            })->disableHeader();
        })
        ;


        $form->editing(function($form, $arr){
            $form->model()->append(['basic_setting', 'design_setting', 'confirm_complete_setting', 'error_setting', 'option_setting']);
        });
        $form->saving(function($form){
            if(!isset($form->model()->proxy_user_id)){
                $form->model()->proxy_user_id = \Exment::getUserId();
            }
        });
        $form->disableEditingCheck(false);
            
        $form->tools(function (Form\Tools $tools) use ($custom_table, $public_form) {
            $tools->prepend(view('exment::tools.button', [
                'href' => 'javascript:void(0);',
                'label' => exmtrans('common.preview'),
                'icon' => 'fa-check-circle',
                'btn_class' => 'btn-warning',
                'attributes' => [
                    'data-preview' => true,
                    'data-preview-url' => admin_urls('formpublic', $custom_table->table_name, 'preview'),
                    'data-preview-error-title' => '',
                    'data-preview-error-text' => '',
                ],
            ])->render());
            $tools->add(new Tools\CustomTableMenuButton('form', $custom_table));
            $tools->setListPath(admin_urls('form', $custom_table->table_name));

            if(isset($public_form)){
                if (!$public_form->active_flg) {
                    // check relation table's count. if has select_table etc, showing modal.
                    if($public_form->getListOfTablesUsed()->count() > 0){
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
                    else{
                        $tools->append(new Tools\SwalInputButton([
                            'url' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "activate"),
                            'label' => exmtrans('common.activate'),
                            'icon' => 'fa-check-circle',
                            'btn_class' => 'btn-success',
                            'title' => exmtrans('common.activate'),
                            'text' => exmtrans('login.help.activate'),
                            'method' => 'post',
                            'redirectUrl' => admin_urls("formpublic", $custom_table->table_name, $public_form->id, "edit"),
                        ]));
                    }
                } 
                else {
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
        });

        $table_name = $this->custom_table->table_name;

        $form->saved(function ($form) use ($table_name) {
            admin_toastr(trans('admin.update_succeeded'));
            if(!is_nullorempty(request()->get('after-save'))){
                return;
            }
            return redirect(admin_url("form/$table_name"));
        });

        return $form;
    }
    

    /**
     * Showing preview
     *
     * @param Request $request
     * @return void
     */
    public function preview(Request $request)
    {
        // get this form's info
        $form = $this->form();
        $model = $form->getModelByInputs();

        // get public form
        $preview_form = $model->getForm($request);
        $preview_form->disableSubmit();

        // set content
        $content = new PublicContent;
        $model->setContentOption($content);
        $content->row($preview_form);
        
        admin_info(exmtrans('common.preview'), exmtrans('common.message.preview'));

        return $content;
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
        $html = "<ul>" . $tableUseds->map(function($tableUsed){
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
     * @return void
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
     * @return void
     */
    public function deactivate(Request $request, $tableKey, $id)
    {
        return $this->toggleActivate($request, $id, false);
    }

    /**
     * Toggle activate and deactivate
     *
     * @param Request $request
     * @param string $id
     * @param boolean $active_flg
     * @return void
     */
    protected function toggleActivate(Request $request, $id, bool $active_flg){
        $login_setting = PublicForm::find($id);
        $login_setting->active_flg = $active_flg;
        $login_setting->save();
        
        return getAjaxResponse([
            'result'  => true,
            'message' => trans('admin.update_succeeded'),
        ]);
    }
    
}
