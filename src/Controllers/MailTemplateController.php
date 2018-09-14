<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Facades\Admin;
use Encore\Admin\Layout\Content;
use Encore\Admin\Controllers\ModelForm;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\MailTemplate;

class MailTemplateController extends AdminControllerBase
{
    use ModelForm;

    public function __construct(Request $request){
        $this->setPageInfo(exmtrans("mail_template.header"), exmtrans("mail_template.header"), exmtrans("mail_template.description"));  
    }

    /**
     * Index interface.
     *
     * @return Content
     */
    public function index()
    {
        return $this->AdminContent(function (Content $content) {
            $content->body($this->grid());
        });
    }

    /**
     * Edit interface.
     *
     * @param $id
     * @return Content
     */
    public function edit($id)
    {
        return $this->AdminContent(function (Content $content) use ($id) {
            $content->body($this->form($id)->edit($id));
        });
    }

    /**
     * Create interface.
     *
     * @return Content
     */
    public function create(Request $request)
    {
        return $this->AdminContent(function (Content $content) {
            $content->body($this->form());
        });
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Admin::grid(MailTemplate::class, function (Grid $grid) {
            $grid->column('mail_name', exmtrans("mail_template.mail_name"));
            $grid->column('mail_view_name', exmtrans("mail_template.mail_view_name"));

            $grid->disableExport();
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if (boolval($actions->row->system_flg)) {
                    $actions->disableDelete();
                }
                $actions->disableView();
            });
            
            $grid->tools(function (Grid\Tools $tools) {
                $tools->batch(function (Grid\Tools\BatchActions $actions) {
                    $actions->disableDelete();
                });
            });
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        return Admin::form(MailTemplate::class, function (Form $form) use($id){

            if (!isset($id)) {
                $form->text('mail_name', exmtrans("mail_template.mail_name"))->rules("required|regex:/".Define::RULES_REGEX_ALPHANUMERIC_UNDER_HYPHEN."/")
                    ->help(exmtrans("mail_template.help.mail_name").exmtrans("common.help_code"));
            } else {
                $form->display('mail_name', exmtrans("mail_template.mail_name"))
                ->help(exmtrans("mail_template.help.mail_name"));
            }

            // get manual url abou mail temlate valiable value
            $manual_url = url_join(config('exment.manual_url'), 'mail');
            $form->text('mail_view_name', exmtrans("mail_template.mail_view_name"))->rules("required")
                ->help(exmtrans("mail_template.help.mail_view_name"));
            
            $form->select('mail_template_type', exmtrans("mail_template.mail_template_type"))->rules("required")
                ->options(getTransArray(Define::MAIL_TEMPLATE_TYPE, 'mail_template.mail_template_type_options'))
                ->default(Define::MAIL_TEMPLATE_TYPE_BODY);

            $form->text('mail_subject', exmtrans("mail_template.mail_subject"))
                ->rules("required_if:mail_template_type,".Define::MAIL_TEMPLATE_TYPE_BODY)
                ->help(exmtrans("mail_template.help.mail_subject"));
                
            $form->textarea('mail_body', exmtrans("mail_template.mail_body"))->rows(10)
               ->help(exmtrans("mail_template.help.mail_body"));
            $form->disableReset();
            $form->disableViewCheck();
            $form->tools(function (Form\Tools $tools){
                $tools->disableView();
            });
        });
    }
}
