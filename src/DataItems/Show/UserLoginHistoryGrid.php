<?php

namespace Exceedone\Exment\DataItems\Show;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginUser;
use ExmentAdminCore\Admin\Grid;

/**
 * Login histories of a user, shown in the detail page of the user (data/user/{id}). See DefaultShow::setLoginHistoryBox()
 */
class UserLoginHistoryGrid
{
    /**
     * Rows per page.
     */
    public const PER_PAGE = 10;

    /**
     * Grid name. The page parameter is "{name}_page", apart from the other grids in the page. (the child tables)
     */
    public const NAME = 'user_login_histories';

    /**
     * Only for the users who can open the login history list, and only for the users who can log in (or logged in before).
     *
     * @param CustomValue $user custom value of the table "user"
     * @return string|null HTML of the grid. null: not shown
     */
    public static function render(CustomValue $user): ?string
    {
        $login_user = \Exment::user();
        if (!$login_user || !$login_user->visible('login_history')) {
            return null;
        }
        if (!LoginUser::where('base_user_id', $user->id)->exists() && !LoginHistory::where('base_user_id', $user->id)->exists()) {
            return null;
        }

        $grid = new Grid(new LoginHistory());
        $grid->setName(static::NAME);
        $grid->setTitle(exmtrans('login_history.header'));
        // the show link goes to the login history, not to the user
        $grid->setResource(admin_url('login_history'));
        $grid->model()->where('base_user_id', $user->id)->orderBy('id', 'DESC');
        $grid->paginate(static::PER_PAGE);

        $grid->column('created_at', exmtrans('login_history.login_at'));
        $grid->column('user_name', exmtrans('login_history.user_name'));
        $grid->column('ip_address', exmtrans('login_history.ip_address'));
        $grid->column('country', exmtrans('login_history.country'))->display(function ($value, $column, $model) {
            return $model->country_text;
        })->escape(true);
        $grid->column('location', exmtrans('login_history.location'))->display(function ($value, $column, $model) {
            return $model->location;
        })->escape(true);
        $grid->column('is_new_ip', exmtrans('login_history.is_new_ip'))->display(function ($value, $column, $model) {
            return UserLoginHistoryGrid::getWarningLabel($model);
        });

        // read only, like the child tables in the detail page
        $grid->disableFilter();
        $grid->disableCreateButton();
        $grid->disableExport();
        $grid->disableRowSelector();
        $grid->tools(function (Grid\Tools $tools) {
            $tools->batch(function (Grid\Tools\BatchActions $batch) {
                $batch->disableDelete();
            });
        });
        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
            $actions->disableDelete();
        });

        return $grid->render();
    }

    /**
     * Label of the column "warning" (login from a new IP address). Also used by the login history list.
     *
     * @param mixed $is_new_ip
     * @return string HTML
     */
    public static function getNewIpLabel($is_new_ip): string
    {
        if (!boolval($is_new_ip)) {
            return '';
        }
        return '<span class="label label-danger">' . esc_html(exmtrans('login_history.is_new_ip_options.1')) . '</span>';
    }

    /**
     * Label of the column "warning": blocked login (Ex. by the login restriction by country), or login from a new IP address.
     * (A blocked attempt is never warned as a new IP address.) Also used by the login history list.
     *
     * @param LoginHistory $history
     * @return string HTML
     */
    public static function getWarningLabel(LoginHistory $history): string
    {
        $blocked = static::getBlockedLabel($history->blocked_reason);
        return $blocked !== '' ? $blocked : static::getNewIpLabel($history->is_new_ip);
    }

    /**
     * Label of a blocked login.
     *
     * @param mixed $blocked_reason LoginHistory::BLOCKED_REASON_*
     * @return string HTML
     */
    public static function getBlockedLabel($blocked_reason): string
    {
        $text = static::getBlockedText($blocked_reason);
        if (is_null($text)) {
            return '';
        }
        return '<span class="label label-warning">' . esc_html($text) . '</span>';
    }

    /**
     * Text of the reason why the login was blocked.
     *
     * @param mixed $blocked_reason LoginHistory::BLOCKED_REASON_*
     * @return string|null null if not blocked
     */
    public static function getBlockedText($blocked_reason): ?string
    {
        if (is_nullorempty($blocked_reason) || !is_string($blocked_reason)) {
            return null;
        }
        $key = "login_history.blocked_reason_options.{$blocked_reason}";
        return \Lang::has("exment::exment.{$key}") ? exmtrans($key) : $blocked_reason;
    }
}
