<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;

/**
 * The settings of the full-text search (Meilisearch; the system settings of the group "meili"),
 * once on the detail page of the system settings (#2157). Services\Meili\MeiliConfig reads
 * them by their names.
 */
class MeiliSettingController extends SystemSettingPageControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('system.meili'), exmtrans('system.meili'), exmtrans('system.meili_description'), 'fa-search');
    }

    protected function uri(): string
    {
        return 'meili_setting';
    }

    protected function group(): string
    {
        return 'meili';
    }

    protected function title(): string
    {
        return exmtrans('system.meili');
    }

    protected function setFields(WidgetForm $form): void
    {
        $form->text('meili_host', exmtrans('system.meili_host'))->help(exmtrans('system.help.meili_host'));
        $form->password('meili_key', exmtrans('system.meili_key'))->help(exmtrans('system.help.meili_key'));
        $form->text('meili_index', exmtrans('system.meili_index'))->help(exmtrans('system.help.meili_index'));
        $form->switchbool('meili_global_search', exmtrans('system.meili_global_search'))->help(exmtrans('system.help.meili_global_search'));
        $form->switchbool('meili_realtime_sync', exmtrans('system.meili_realtime_sync'))->help(exmtrans('system.help.meili_realtime_sync'));
        $form->number('meili_batch_size', exmtrans('system.meili_batch_size'))
            ->min(1)
            ->rules(['nullable', 'integer', 'min:1'])
            ->help(exmtrans('system.help.meili_batch_size'));
        $form->switchbool('meili_repair_enabled', exmtrans('system.meili_repair_enabled'))->help(exmtrans('system.help.meili_repair_enabled'));
        $form->text('meili_repair_at', exmtrans('system.meili_repair_at'))
            ->rules(['nullable', 'regex:/^([01][0-9]|2[0-3]):[0-5][0-9]$/'])
            ->help(exmtrans('system.help.meili_repair_at'));
        $form->select('meili_filter_mode', exmtrans('system.meili_filter_mode'))
            ->options(['override' => exmtrans('system.meili_filter_mode_options.override'), 'manual' => exmtrans('system.meili_filter_mode_options.manual')])
            ->default('override')
            ->disableClear()
            ->help(exmtrans('system.help.meili_filter_mode'));
    }
}
