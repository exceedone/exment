<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Widgets\Box;
use ExmentAdminCore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * A page with one group of the system settings (Define::SYSTEM_SETTING_NAME_VALUE), as the
 * safety check and the full-text search, once on the detail page of the system settings (#2157).
 * Only the system administrators (Auth\Permission). The values are the same system settings
 * as before: only the page changed.
 */
abstract class SystemSettingPageControllerBase extends AdminControllerBase
{
    use InitializeFormTrait;

    /**
     * The URI of the page, below the prefix of the admin pages.
     */
    abstract protected function uri(): string;

    /**
     * The group of the system settings the page saves.
     */
    abstract protected function group(): string;

    /**
     * The title of the box.
     */
    abstract protected function title(): string;

    /**
     * Add the fields of the settings.
     */
    abstract protected function setFields(WidgetForm $form): void;

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);

        $box = new Box($this->title(), $this->form());
        $box->tools(new Tools\SystemChangePageMenu());
        $content->row($box);

        return $content;
    }

    protected function form(): WidgetForm
    {
        $form = new WidgetForm(DemoMode::maskSystemValues(System::get_system_values([$this->group()])));
        $form->disableReset();
        $form->action(admin_url($this->uri()));

        if (DemoMode::enabled()) {
            $form->descriptionHtml(esc_html(exmtrans('demo_mode.message_system_setting')));
        }

        $this->setFields($form);

        return $form;
    }

    /**
     * Save the settings of the page (only its group: the other settings stay).
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     * @throws \Throwable
     */
    public function post(Request $request)
    {
        $form = $this->form();
        if (($response = $form->validateRedirect($request)) instanceof \Illuminate\Http\RedirectResponse) {
            return $response;
        }

        DB::beginTransaction();
        try {
            $result = $this->postInitializeForm($request, [$this->group()], false, false);
            if ($result instanceof \Illuminate\Http\RedirectResponse) {
                // close the transaction before leaving
                DB::rollback();
                return $result;
            }

            DB::commit();
        } catch (\Exception $exception) {
            DB::rollback();
            throw $exception;
        }

        System::clearCache();
        admin_toastr(trans('admin.save_succeeded'));

        return redirect(admin_url($this->uri()));
    }
}
