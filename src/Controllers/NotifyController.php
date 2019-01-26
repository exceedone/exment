<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Encore\Admin\Controllers\HasResourceActions;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use DB;

class NotifyController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
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
        $grid->column('custom_table_id', exmtrans("notify.custom_table_id"))->sortable()->display(function ($val) {
            return esc_html(CustomTable::getEloquent($val)->table_view_name);
        }); 
        $grid->column('notify_trigger', exmtrans("notify.notify_trigger"))->sortable()->display(function ($val) {
            return NotifyTrigger::getEnum($val)->transKey('notify.notify_trigger_options');
        });

        $grid->column('notify_action', exmtrans("notify.notify_action"))->sortable()->display(function ($val) {
            return NotifyAction::getEnum($val)->transKey('notify.notify_action_options');
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
        $form->text('notify_view_name', exmtrans("notify.notify_view_name"))->required();
        // TODO: only role tables

        $form->header(exmtrans('notify.header_trigger'))->hr();
        $form->select('custom_table_id', exmtrans("notify.custom_table_id"))
        ->required()
        ->options(function ($custom_table_id) {
            return CustomTable::filterList()->pluck('table_view_name', 'id');
        })->attribute(['data-linkage' => json_encode(
            [
                'trigger_settings_notify_target_column' =>  admin_base_path('notify/targetcolumn'),
                'action_settings_notify_action_target' => admin_base_path('notify/notify_action_target'),
            ]
        )
        ])
        ->help(exmtrans("notify.help.custom_table_id"));

        $form->select('notify_trigger', exmtrans("notify.notify_trigger"))
            ->options(NotifyTrigger::transKeyArray("notify.notify_trigger_options"))
            ->default(NotifyTrigger::TIME)
            ->required()
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("notify.help.notify_trigger"));

        $form->embeds('trigger_settings', exmtrans("notify.trigger_settings"), function (Form\EmbeddedForm $form) {
            // Notify Time --------------------------------------------------
            $controller = $this;
            $form->select('notify_target_column', exmtrans("notify.notify_target_column"))
            ->options(function ($val) use($controller) {
                if (!isset($val)) {
                    return [];
                }
                return $controller->getTargetColumnOptions(CustomColumn::getEloquent($val)->custom_table, false);
            })
            ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
            ->help(exmtrans("notify.help.trigger_settings"));

            $form->number('notify_day', exmtrans("notify.notify_day"))
                ->help(exmtrans("notify.help.notify_day"))
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ;
            $form->select('notify_beforeafter', exmtrans("notify.notify_beforeafter"))
                ->options(NotifyBeforeAfter::transKeyArray('notify.notify_beforeafter_options'))
                ->default(NotifyBeforeAfter::BEFORE)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_beforeafter"));
                
            $form->number('notify_hour', exmtrans("notify.notify_hour"))
                ->min(0)
                ->max(23)
                ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'notify_trigger', 'value' => [NotifyTrigger::TIME]])])
                ->help(exmtrans("notify.help.notify_hour"));
        })->disableHeader();

        $form->header(exmtrans("notify.header_action"))->hr();
        $form->select('notify_action', exmtrans("notify.notify_action"))
            ->options(NotifyAction::transKeyArray("notify.notify_action_options"))
            ->default(NotifyAction::EMAIL)
            ->required()
            ->help(exmtrans("notify.notify_action"))
            ;

        $form->embeds('action_settings', exmtrans("notify.action_settings"), function (Form\EmbeddedForm $form) {
            $controller = $this;
            $form->select('notify_action_target', exmtrans("notify.notify_action_target"))
                ->options(function ($val) use($controller) {
                    return $controller->getNotifyActionTargetOptions($this->custom_table_id ?? null, false);
                })
                ->default(NotifyActionTarget::HAS_ROLES)
                ->required()
                ->help(exmtrans("notify.help.notify_action_target"));

            // get notify mail template
            $notify_mail_id = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::TIME_NOTIFY)->first()->id;

            $form->select('mail_template_id', exmtrans("notify.mail_template_id"))->options(function ($val) {
                return getModelName(SystemTableName::MAIL_TEMPLATE)::all()->pluck('label', 'id');
            })->help(exmtrans("notify.help.mail_template_id"))
            ->default($notify_mail_id);
        })->disableHeader();
        
        disableFormFooter($form);
        $form->tools(function (Form\Tools $tools) {
            $tools->disableView();
        });
        return $form;
    }

    public function targetcolumn(Request $request)
    {
        return $this->getTargetColumnOptions($request->get('q'), true);
    }

    public function notify_action_target(Request $request)
    {
        return $this->getNotifyActionTargetOptions($request->get('q'), true);
    }

    protected function getTargetColumnOptions($custom_table, $isApi){
        $custom_table = CustomTable::getEloquent($custom_table);

        $options = CustomColumn
            ::where('custom_table_id', $custom_table->id)
            ->whereIn('column_type', [ColumnType::DATE, ColumnType::DATETIME])
            ->get(['id', DB::raw('column_view_name as text')]);

        if($isApi){
            return $options;
        }else{
            return $options->pluck('text', 'id');
        }
    }

    protected function getNotifyActionTargetOptions($custom_table, $isApi){
        $array = NotifyActionTarget::transArray('notify.notify_action_target_options');
        $options = [];
        foreach ($array as $k => $v) {
            $options[] = ['id' => $k, 'text' => $v];
        }

        if (isset($custom_table)) {
            $custom_table = CustomTable::getEloquent($custom_table);
            $options = array_merge($options, CustomColumn
            ::where('custom_table_id', $custom_table->id)
            ->whereIn('column_type', [ColumnType::USER, ColumnType::ORGANIZATION])
            ->get(
                ['id', DB::raw('column_view_name as text')]
            )->toArray());
        }
        if($isApi){
            return $options;
        }else{
            return collect($options)->pluck('text', 'id')->toArray();
        }
    }
}
