<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\DataMaintenance\AttachmentCleanup;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Data maintenance of a table (#1829, Features::DATA_MAINTENANCE): what the
 * administrators of a table do now and then, for all its data at once.
 *
 * The first tool: delete the attachments (the records stay). The page counts
 * what would go first; deleting asks to type the name of the table.
 *
 * Who: the users who may change the table (Permission::CUSTOM_TABLE). The
 * feature itself is checked by the middleware admin.feature (off: 404).
 */
class DataMaintenanceController extends AdminControllerTableBase
{
    public function __construct(?CustomTable $custom_table, Request $request)
    {
        parent::__construct($custom_table, $request);

        $title = exmtrans('data_maintenance.header') . ' : ' . ($custom_table ? $custom_table->table_view_name : null);
        $this->setPageInfo($title, $title, exmtrans('data_maintenance.description'), 'fa-wrench');
    }

    /**
     * @return Content|void
     */
    public function index(Request $request, Content $content)
    {
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        $this->AdminContent($content);
        $menu = (new Tools\CustomTableMenuButton('data_maintenance', $this->custom_table))->render();
        $menu = strval($menu);

        list($kinds, $before, $error) = $this->options($request);
        $summary = null;
        if ($request->has('check') && !isset($error)) {
            $summary = (new AttachmentCleanup($this->custom_table, $kinds, $before))->summary();
        }

        $content->row(view('exment::data_maintenance.index', [
            'table' => $this->custom_table,
            'menu' => $menu,
            'kinds' => $kinds,
            'before' => isset($before) ? $before->toDateString() : null,
            'checked' => $request->has('check'),
            'error' => $error,
            'summary' => $summary,
            'size' => isset($summary) ? bytesToHuman($summary['bytes']) : null,
            'checkUrl' => admin_urls('data_maintenance', $this->custom_table->table_name),
            'deleteUrl' => admin_urls('data_maintenance', $this->custom_table->table_name, 'attachments'),
        ]));
        return $content;
    }

    /**
     * Delete the attachments.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     */
    public function deleteAttachments(Request $request)
    {
        if (!$this->validateTable($this->custom_table, Permission::CUSTOM_TABLE)) {
            return;
        }
        $back = admin_urls_query('data_maintenance', $this->custom_table->table_name, array_filter([
            'kinds' => $request->input('kinds'),
            'before' => $request->input('before'),
            'check' => 1,
        ]));

        list($kinds, $before, $error) = $this->options($request);
        if (!isset($error) && trim(strval($request->input('confirm_table_name'))) !== $this->custom_table->table_name) {
            $error = exmtrans('data_maintenance.attachments.error_confirm');
        }
        if (isset($error)) {
            admin_toastr($error, 'error');
            return redirect($back);
        }

        $result = (new AttachmentCleanup($this->custom_table, $kinds, $before))->execute(intval(\Exceedone\Exment\Facades\ExmentFacade::user()->id));
        admin_toastr(exmtrans('data_maintenance.attachments.done', [
            'files' => $result['files'],
            'records' => $result['records'],
            'size' => bytesToHuman($result['bytes']),
        ]));
        return redirect(admin_urls('data_maintenance', $this->custom_table->table_name));
    }

    /**
     * The kinds and the day, from the request.
     *
     * @return array{0: string[], 1: Carbon|null, 2: string|null} kinds, before, error
     */
    protected function options(Request $request): array
    {
        $kinds = array_values(array_intersect(AttachmentCleanup::KINDS, (array)$request->input('kinds', [])));
        $before = null;
        $error = null;
        $value = trim(strval($request->input('before')));
        if ($value !== '') {
            try {
                $before = Carbon::createFromFormat('!Y-m-d', $value);
            } catch (\Throwable $e) {
                $before = null;
            }
            if (!$before) {
                $before = null;
                $error = exmtrans('data_maintenance.attachments.error_before');
            }
        }
        if (count($kinds) === 0 && ($request->has('check') || $request->isMethod('post'))) {
            $error = $error ?? exmtrans('data_maintenance.attachments.error_kinds');
        }
        return [$kinds, $before, $error];
    }
}
