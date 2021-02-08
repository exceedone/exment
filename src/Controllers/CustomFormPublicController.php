<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\CustomTable;
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
        $form = new Form(new PublicForm);
        $public_form = PublicForm::find($id);
        $custom_table = $this->custom_table;

        // Basic setting ----------------------------------------------------
        $form->tab(exmtrans("common.basic_setting"), function ($form) use ($public_form, $id, $custom_table) {
            $form->exmheader(exmtrans("common.basic_setting"))->hr();
                
            if(isset($public_form)){
                $form->url('share_url', exmtrans('custom_form_public.share_url'))
                    ->setElementClass(['copyScript'])
                    ->default($public_form->getUrl())
                    ->readonly();
                $form->ignore('share_url');
            }
            
            $form->select('custom_form_id', exmtrans("custom_form_public.custom_form_id"))
                ->tabRequired()
                ->help(exmtrans("custom_form_public.help.custom_form_id"))
                ->options(function ($value) use ($custom_table) {
                    return $custom_table->custom_forms->mapWithKeys(function ($item) {
                        return [$item['id'] => $item['form_view_name']];
                    });
                });
                
            $form->embeds("basic_setting", exmtrans("common.basic_setting"), function($form) use ($custom_table){
                $form->switchbool('active_flg', exmtrans("plugin.active_flg"))
                    ->help(exmtrans("custom_form_public.help.active_flg"))
                    ->default(true);
    
                $form->dateTimeRange('validity_period_start', 'validity_period_end', exmtrans("custom_form_public.validity_period"))
                    ->help(exmtrans("custom_form_public.help.validity_period"))
                    ->default(true);
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.design_setting"), function ($form) {
            $form->embeds("design_setting", exmtrans("common.design_setting"), function($form){
                $form->exmheader(exmtrans("custom_form_public.header_setting"))->hr();            
                
                $form->switchbool('use_header', exmtrans("custom_form_public.use_header"))
                    ->help(exmtrans("custom_form_public.help.use_header"))
                    ->default(true);
                    ;
                
                $form->color('header_background_color', exmtrans("custom_form_public.header_background_color"))
                    ->help(exmtrans("custom_form_public.help.header_background_color"))
                    ->default('#3c8dbc')
                ;

                $form->image('header_logo', exmtrans("custom_form_public.header_logo"))
                    ->help(exmtrans("custom_form_public.help.header_logo"))
                ;

                $form->text('header_label', exmtrans("custom_form_public.header_label"))
                    ->help(exmtrans("custom_form_public.help.header_label"))
                ;

                $form->color('header_text_color', exmtrans("custom_form_public.header_text_color"))
                    ->help(exmtrans("custom_form_public.help.header_text_color"))
                    ->default('#FFFFFF')
                    ;


                $form->exmheader(exmtrans("custom_form_public.body_setting"))->hr();
                $form->select('body_content_type', exmtrans("custom_form_public.body_content_type"))
                    ->help(exmtrans("custom_form_public.help.body_content_type"))
                    ->options([
                        'width100' => exmtrans("custom_form_public.body_content_type_options.width100"), 
                        'centering' => exmtrans("custom_form_public.body_content_type_options.centering"), 
                    ])
                    ->default('width100');
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
                    ->default(true);
                ;
                
                $form->color('footer_background_color', exmtrans("custom_form_public.footer_background_color"))
                    ->help(exmtrans("custom_form_public.help.footer_background_color"))
                    ->default('#000000')
                ;
                $form->color('footer_text_color', exmtrans("custom_form_public.footer_text_color"))
                    ->help(exmtrans("custom_form_public.help.footer_text_color"))
                    ->default('#FFFFFF')
                    ;
            })->disableHeader();
        })->tab(exmtrans("custom_form_public.confirm_complete_setting"), function ($form) {
            $form->embeds("confirm_complete_setting", exmtrans("common.confirm_complete_setting"), function($form){
                $form->exmheader(exmtrans("custom_form_public.confirm_complete_setting"))->hr();

                $form->switchbool('use_confirm', exmtrans("custom_form_public.use_confirm"))
                    ->help(exmtrans("custom_form_public.help.use_confirm"))
                    ->default(true);
                ;
                
                $form->textarea('confirm_text', exmtrans("custom_form_public.confirm_text"))
                    ->help((exmtrans("custom_form_public.help.confirm_text", ['url' => \Exment::getManualUrl('params')])))
                    ->default(exmtrans("custom_form_public.message.confirm_text"))
                    ->rows(3);
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
        });
        

        $form->editing(function($form, $arr){
            $form->model()->append(['basic_setting', 'design_setting', 'confirm_complete_setting', 'error_setting']);
        });
        $form->saving(function($form){
            if(!isset($form->model()->proxy_user_id)){
                $form->model()->proxy_user_id = \Exment::getUserId();
            }
        });
        $form->disableEditingCheck(false);
            
        $form->tools(function (Form\Tools $tools) use ($custom_table) {
            $tools->prepend(view('exment::tools.button', [
                'href' => 'javascript:void(0);',
                'label' => exmtrans('common.preview'),
                'icon' => 'fa-eye',
                'btn_class' => 'preview-custom_form btn-warning',
                'attributes' => [
                    'data-preview' => true,
                    'data-preview-url' => '',
                    'data-preview-error-title' => '',
                    'data-preview-error-text' => '',
                ],
            ])->render());
            $tools->add(new Tools\CustomTableMenuButton('form', $custom_table));
            $tools->setListPath(admin_urls('form', $custom_table->table_name));
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
        $form = $this->form();

        $model = $form->getModelByInputs();

        $content = new \Exceedone\Exment\Form\PublicContent;
        $content->row($form);
        return $content;
        
        $content = new Content;
        $this->setPageInfo($this->custom_table->table_view_name, $this->custom_table->table_view_name, $this->custom_table->description, $this->custom_table->getOption('icon'));
        $this->AdminContent($content);
        $content->row($form);
        
        admin_info(exmtrans('common.preview'), exmtrans('common.message.preview'));

        return $content;
    }

}
