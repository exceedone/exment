<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginFailure;
use Exceedone\Exment\Model\LoginIpBlock;
use Exceedone\Exment\Services\Login\LoginIpBlockService;
use ExmentAdminCore\Admin\Grid;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Show;
use Illuminate\Http\Request;

/**
 * IP addresses blocked now by too many failed logins. Deleting a row releases the block.
 * (The record itself is kept for a while, to make the next block of the same IP address longer. See LoginIpBlockService)
 */
class LoginIpBlockController extends AdminControllerBase
{
    /**
     * Number of the recent failed logins shown in the detail.
     */
    public const RECENT_FAILURES_COUNT = 20;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('login_ip_block.header'), exmtrans('login_ip_block.header'), exmtrans('login_ip_block.description'), 'fa-ban');
    }

    /**
     * @return Grid
     */
    protected function grid()
    {
        $grid = new Grid(new LoginIpBlock());
        $grid->model()->blocked()->orderBy('blocked_at', 'DESC');

        $grid->column('ip_key', exmtrans('login_ip_block.ip_key'));
        $grid->column('country', exmtrans('login_ip_block.country'))->display(function ($value, $column, $model) {
            return $model->country_text;
        })->escape(true);
        $grid->column('blocked_at', exmtrans('login_ip_block.blocked_at'))->sortable();
        $grid->column('blocked_until', exmtrans('login_ip_block.blocked_until'))->sortable();
        $grid->column('block_level', exmtrans('login_ip_block.block_level'))->display(function ($value) {
            return LoginIpBlockController::getBlockLevelText($value);
        })->escape(true);
        $grid->column('failed_count', exmtrans('login_ip_block.failed_count'));
        $grid->column('blocked_attempts', exmtrans('login_ip_block.blocked_attempts'));
        $grid->column('last_username', exmtrans('login_ip_block.last_username'));

        // Same as the login history: show / delete (= release) per row, checkbox for batch release, no edit.
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
        });

        $grid->disableCreateButton();
        $grid->disableExport();

        $grid->filter(function (Grid\Filter $filter) {
            $filter->disableIdFilter();
            $filter->like('ip_key', exmtrans('login_ip_block.ip_key'));
            $filter->like('last_username', exmtrans('login_ip_block.last_username'));
        });

        $grid->tools(function (Grid\Tools $tools) {
            $tools->append('<a href="' . esc_html(admin_url('login_setting')) . '" class="btn btn-sm btn-default"><i class="fa fa-cog"></i> ' . esc_html(exmtrans('login_ip_block.setting_link')) . '</a>');
        });

        return $grid;
    }

    /**
     * Show the detail. The record may have been deleted (by the schedule) after the notification linking to it was sent:
     * go to the list with the "data not found" message, not an error page.
     *
     * @param Request $request
     * @param Content $content
     * @param mixed $id
     * @return Content|\Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function show(Request $request, Content $content, $id)
    {
        if (!LoginIpBlock::where('id', $id)->exists()) {
            admin_toastr(exmtrans('common.message.notfound'), 'error');
            return redirect(admin_url('login_ip_block'));
        }
        return parent::show($request, $content, $id);
    }

    /**
     * Make a show builder. Also shows the block already ended (from the notification), and the recent failed logins of the IP address.
     *
     * @param mixed $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = LoginIpBlock::query()->where('id', $id)->firstOrFail();
        return new Show($model, function (Show $show) use ($model) {
            $show->field('ip_key', exmtrans('login_ip_block.ip_key'));
            $show->field('ip_address', exmtrans('login_ip_block.ip_address'));
            $show->field('country', exmtrans('login_ip_block.country'))->as(function ($value) use ($model) {
                return $model->country_text;
            });
            $show->field('status', exmtrans('login_ip_block.status'))->as(function () use ($model) {
                return exmtrans('login_ip_block.status_options.' . $model->getStatus());
            });
            $show->field('blocked_at', exmtrans('login_ip_block.blocked_at'));
            $show->field('blocked_until', exmtrans('login_ip_block.blocked_until'));
            $show->field('released_at', exmtrans('login_ip_block.released_at'));
            $show->field('block_level', exmtrans('login_ip_block.block_level'))->as(function ($value) {
                return LoginIpBlockController::getBlockLevelText($value);
            });
            $show->field('failed_count', exmtrans('login_ip_block.failed_count'));
            $show->field('blocked_attempts', exmtrans('login_ip_block.blocked_attempts'));
            $show->field('last_username', exmtrans('login_ip_block.last_username'));
            $show->field('recent_failures', exmtrans('login_ip_block.recent_failures'))->as(function () use ($model) {
                return LoginIpBlockController::getRecentFailuresHtml($model);
            })->setEscape(false);

            $show->panel()->tools(function ($tools) use ($model) {
                $tools->disableEdit();
                // only a block still active can be released
                if (!$model->isBlocked()) {
                    $tools->disableDelete();
                }
            });
        });
    }

    /**
     * Release the blocks. $id is a single id, or ids joined by "," (batch). Already ended blocks are ignored.
     *
     * @param mixed $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function destroy($id)
    {
        $ids = array_filter(explode(',', (string)$id), function ($id) {
            return is_numeric($id);
        });

        $service = app(LoginIpBlockService::class);
        $released = 0;
        foreach (LoginIpBlock::query()->whereIn('id', $ids)->get() as $block) {
            if (!$block->isBlocked()) {
                continue;
            }
            $service->release($block);
            $released++;
        }

        if ($released > 0) {
            return response()->json([
                'status'  => true,
                'message' => exmtrans('login_ip_block.message.released'),
            ]);
        }

        return response()->json([
            'status'  => false,
            'message' => exmtrans('login_ip_block.message.release_failed'),
        ]);
    }

    /**
     * "1st", "2nd", "3rd or later". 0: released.
     *
     * @param mixed $level
     * @return string
     */
    public static function getBlockLevelText($level): string
    {
        $level = intval($level);
        if ($level <= 0) {
            return exmtrans('login_ip_block.status_options.' . LoginIpBlock::STATUS_RELEASED);
        }
        if ($level >= LoginIpBlockService::MAX_LEVEL) {
            return exmtrans('login_ip_block.block_level_max', LoginIpBlockService::MAX_LEVEL);
        }
        return exmtrans('login_ip_block.block_level_text', $level);
    }

    /**
     * Table of the recent failed logins of the IP address. (wrong ID / password, wrong CAPTCHA)
     *
     * @param LoginIpBlock $block
     * @return string HTML
     */
    public static function getRecentFailuresHtml(LoginIpBlock $block): string
    {
        $failures = LoginFailure::query()
            ->where('ip_key', $block->ip_key)
            ->whereIn('reason', LoginFailure::IP_REASONS)
            ->orderBy('id', 'desc')
            ->limit(static::RECENT_FAILURES_COUNT)
            ->get();
        if ($failures->isEmpty()) {
            return esc_html(exmtrans('login_ip_block.no_failures'));
        }

        $html = '<table class="table table-bordered table-condensed" style="margin-bottom:0;"><thead><tr>'
            . '<th>' . esc_html(exmtrans('login_ip_block.failed_at')) . '</th>'
            . '<th>' . esc_html(exmtrans('login_ip_block.ip_address')) . '</th>'
            . '<th>' . esc_html(exmtrans('login_ip_block.username')) . '</th>'
            . '<th>' . esc_html(exmtrans('login_ip_block.reason')) . '</th>'
            . '</tr></thead><tbody>';
        foreach ($failures as $failure) {
            $created_at = $failure->created_at instanceof Carbon ? $failure->created_at->format('Y-m-d H:i:s') : (string)$failure->created_at;
            $html .= '<tr>'
                . '<td>' . esc_html($created_at) . '</td>'
                . '<td>' . esc_html($failure->ip_address) . '</td>'
                . '<td>' . esc_html($failure->username) . '</td>'
                . '<td>' . esc_html(exmtrans('login_ip_block.reason_options.' . $failure->reason)) . '</td>'
                . '</tr>';
        }
        return $html . '</tbody></table>';
    }
}
