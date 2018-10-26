<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Controllers\ModelForm;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\MailTemplate;
use DB;

class NotifyController extends AdminControllerBase
{
    use ModelForm;

    public function __construct(Request $request){
        $this->setPageInfo(exmtrans("notify.header"), exmtrans("notify.header"), exmtrans("notify.description"));
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new Notify);
        $grid->column('notify_view_name', exmtrans("notify.notify_view_name"))->sortable();
        $grid->column('custom_table_id', exmtrans("notify.custom_table_id"))->sortable()->display(function($val){
            return CustomTable::find($val)->table_view_name;
        });
        $grid->column('notify_trigger', exmtrans("notify.notify_trigger"))->sortable()->display(function($val){
            return array_get(getTransArrayValue(Define::NOTIFY_TRIGGER, 'notify.notify_trigger_options'), $val);
        });

        $grid->disableExport();
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableView();
        });
        return $grid;
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form($id = null)
    {
        $form = new Form(new Notify);
        $form->text('notify_view_name', exmtrans("notify.notify_view_name"))->rules("required");
        // TODO: only authority tables

        $form->header(exmtrans('notify.header_trigger'))->hr();
        $form->select('custom_table_id', exmtrans("notify.custom_table_id"))
        ->rules("required")
        ->options(function($custom_table_id){
            return CustomTable::all()->pluck('table_view_name', 'id');
        })->attribute(['data-changedata' => json_encode(['changedata' => [
                'trigger_settings_notify_target_column' => admin_base_path('notify/targetcolumn'),
                'action_settings_notify_action_target' => admin_base_path('notify/notify_action_target')
            ]])
        ])
        ->help(exmtrans("notify.help.custom_table_id"));

        $form->select('notify_trigger', exmtrans("notify.notify_trigger"))
            ->options(getTransArrayValue(Define::NOTIFY_TRIGGER, 'notify.notify_trigger_options'))
            ->default('1')
            ->rules("required")
            ->help(exmtrans("notify.help.notify_trigger"));

        $form->embeds('trigger_settings', exmtrans("notify.trigger_settings"), function (Form\EmbeddedForm $form){
            $form->select('notify_target_column', exmtrans("notify.notify_target_column"))->options(function($val){
                if(isset($val)){
                    return CustomColumn::find($val)->custom_table->custom_columns()->pluck('column_view_name', 'id');
                }
                return [];
            })
            ->help(exmtrans("notify.help.trigger_settings"));

            $form->number('notify_day', exmtrans("notify.notify_day"))
                ->help(exmtrans("notify.help.notify_day"))
                ;
            $form->select('notify_beforeafter', exmtrans("notify.notify_beforeafter"))
                ->options(getTransArrayValue(Define::NOTIFY_BEFOREAFTER, 'notify.notify_beforeafter_options'))
                ->default('-1')
                ->help(exmtrans("notify.help.notify_beforeafter"));
                
            $form->number('notify_hour', exmtrans("notify.notify_hour"))->min(0)->max(23)
            ->help(exmtrans("notify.help.notify_hour"));
        })->disableHeader();

        $form->header(exmtrans("notify.header_action"))->hr();
        $form->select('notify_action', exmtrans("notify.notify_action"))
            ->options(getTransArrayValue(Define::NOTIFY_ACTION, 'notify.notify_action_options'))
            ->default('1')
            ->rules("required")
            ->help(exmtrans("notify.notify_action"))
            ;

        $form->embeds('action_settings', exmtrans("notify.action_settings"), function (Form\EmbeddedForm $form){
            $form->select('notify_action_target', exmtrans("notify.notify_action_target"))
                ->options(getTransArray(Define::NOTIFY_ACTION_TARGET, 'notify.notify_action_target_options'))
                ->default('1')
                ->rules("required")
                ->help(exmtrans("notify.help.notify_action_target"));

            // get notify mail template
            $notify_mail_id = MailTemplate::where('mail_name', 'system_notify')->first()->id;

            $form->select('mail_template_id', exmtrans("notify.mail_template_id"))->options(function($val){
                return MailTemplate::all()->pluck('mail_view_name', 'id');
            })->help(exmtrans("notify.help.mail_template_id"))
            ->default($notify_mail_id);
        })->disableHeader();
        $form->disableReset();
        $form->disableViewCheck();
        $form->tools(function (Form\Tools $tools){
            $tools->disableView();
        });
        return $form;
    }

    public function targetcolumn(Request $request)
    {
        $table_id = $request->get('q');

        return CustomColumn
            ::where('custom_table_id', $table_id)
            ->whereIn('column_type', ['date', 'datetime'])
            ->get(['id', DB::raw('column_view_name as text')]);
    }

    public function notify_action_target(Request $request)
    {
        $table_id = $request->get('q');
        $array = getTransArray(Define::NOTIFY_ACTION_TARGET, 'notify.notify_action_target_options');
        $changedatas = [];
        foreach($array as $k => $v){
            $changedatas[] = ['id' => $k, 'text' => $v];
        }
        $changedatas = array_merge($changedatas,  CustomColumn
            ::where('custom_table_id', $table_id)
            ->whereIn('column_type', [Define::SYSTEM_TABLE_NAME_USER, Define::SYSTEM_TABLE_NAME_ORGANIZATION])
            ->get(['id', DB::raw('column_view_name as text')]
            )->toArray());

        return $changedatas;
    }
}
