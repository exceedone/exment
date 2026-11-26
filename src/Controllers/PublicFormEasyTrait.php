<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Services\PublicFormService;
use ExmentAdminCore\Admin\Layout\Content;
use ExmentAdminCore\Admin\Widgets\Box;
use Illuminate\Http\Request;

/**
 * The easy way to a web form (ISS02942), in three steps on one page: the fields (each one shown,
 * and must be filled or not), who is told and the text after sending, "Publish". The rest (the
 * name, the receipt mail, the robot guard...) is folded under "More settings", and the design,
 * the confirm page, CSS and so on stay on the edit screen. Made through PublicFormService. Then the
 * page with the URL and the code to paste on a web site, copy buttons, and the form itself.
 * Plain words on these pages: no "iframe", "reCAPTCHA" or "UUID".
 */
trait PublicFormEasyTrait
{
    /** The owner key of a public form made by the easy way (then edited as any other). */
    public static string $easyOwnerPrefix = 'exment_easy:';

    /**
     * The columns that may be on a web form, with what to tell about each.
     *
     * @return array<string, array{label: string, warning: string|null, table_required: bool, checked: bool}>
     */
    protected function easyColumns(): array
    {
        $result = [];
        foreach ($this->custom_table->custom_columns_cache as $column) {
            /** @var CustomColumn $column */
            $warning = null;
            if (in_array($column->column_type, [ColumnType::USER, ColumnType::ORGANIZATION], true)) {
                // the names of the users (organizations) are shown to anybody who opens the form
                $warning = 'users';
            } elseif ($column->column_type === ColumnType::SELECT_TABLE) {
                $warning = 'select_table';
            } elseif ($column->column_type === ColumnType::AUTO_NUMBER) {
                continue;
            }
            $required = boolval($column->required);
            $result[strval($column->column_name)] = [
                'label' => strval($column->column_view_name),
                'warning' => $warning,
                'table_required' => $required,
                'checked' => $required || is_null($warning),
            ];
        }
        return $result;
    }

    /**
     * Whether the user who publishes (the form adds data as this user) may add data to the table.
     */
    protected function easyCanAdd(): bool
    {
        return $this->custom_table->hasPermission(Permission::AVAILABLE_EDIT_CUSTOM_VALUE);
    }

    /**
     * The page of the three steps.
     *
     * @return Content|void
     */
    public function easy(Request $request, Content $content)
    {
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }
        $this->AdminContent($content);
        $emails = $this->easyEmailColumns();
        $content->row(new Box(exmtrans('custom_form_public.easy.header'), view('exment::public-form.easy', [
            'action' => admin_urls('formpublic', $this->custom_table->table_name, 'easy'),
            'detail_url' => admin_urls('formpublic', $this->custom_table->table_name, 'create'),
            'columns' => $this->easyColumns(),
            'users' => $this->easyUsers(),
            'default_users' => [strval(\Exment::getUserId())],
            'email_columns' => $emails,
            'default_email' => array_key_first($emails),
            'complete_text' => exmtrans('custom_form_public.easy.complete_sample'),
            'name' => strval($this->custom_table->table_view_name),
            'robot_available' => PublicForm::isEnableRecaptcha() === true,
            'can_add' => $this->easyCanAdd(),
        ])->render()));
        return $content;
    }

    /**
     * Make the web form, then show its code.
     *
     * @return \Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector|void
     */
    public function easyStore(Request $request)
    {
        if (!$this->validateTable($this->custom_table, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }
        $back = redirect()->to(admin_urls('formpublic', $this->custom_table->table_name, 'easy'))->withInput();
        if (!$this->easyCanAdd()) {
            admin_toastr(exmtrans('custom_form_public.easy.warning_permission'), 'error');
            return $back;
        }
        $known = $this->easyColumns();
        $asked = array_filter((array) $request->get('columns', []), 'is_string');
        // the columns the table requires are always on the form
        $columns = array_values(array_filter(array_keys($known), function ($name) use ($asked, $known) {
            return in_array($name, $asked, true) || $known[$name]['table_required'];
        }));
        $required = array_values(array_intersect($columns, array_filter((array) $request->get('required', []), 'is_string')));
        $name = trim(strval($request->get('public_form_view_name', $this->custom_table->table_view_name)));
        if (count($columns) == 0 || $name === '' || mb_strlen($name) > 40) {
            admin_toastr(exmtrans('custom_form_public.easy.error_input'), 'error');
            return $back;
        }
        $emailColumn = strval($request->get('notify_user_column'));
        $users = array_values(array_intersect(array_keys($this->easyUsers()), array_map('strval', (array) $request->get('notify_admin_users', []))));

        $publicForm = PublicFormService::ensure($this->custom_table, static::$easyOwnerPrefix . make_uuid(), $columns, [
            'view_name' => $name,
            'active' => boolval($request->get('active')),
            'complete_text' => strval($request->get('complete_text')),
            'notify_user_column' => array_key_exists($emailColumn, $this->easyEmailColumns()) ? $emailColumn : null,
            'notify_admin_users' => $users,
            'recaptcha' => boolval($request->get('recaptcha')) && PublicForm::isEnableRecaptcha() === true,
            'required' => $required,
        ]);
        admin_toastr(exmtrans('custom_form_public.easy.published'));
        return redirect(admin_urls('formpublic', $this->custom_table->table_name, $publicForm->id, 'embed'));
    }

    /**
     * The URL and the code to put a public form on a web site, and the form itself.
     *
     * @param string $tableKey
     * @param string|int $id
     * @return Content|void
     */
    public function embed(Request $request, Content $content, $tableKey, $id)
    {
        if (!$this->validateRowsOfTable(PublicForm::class, $id, Permission::EDIT_CUSTOM_FORM_PUBLIC)) {
            return;
        }
        /** @var PublicForm $publicForm */
        $publicForm = PublicForm::findOrFail($id);
        $this->AdminContent($content);
        $content->row(new Box(exmtrans('custom_form_public.easy.embed_header') . ' : ' . $publicForm->public_form_view_name, view('exment::public-form.embed-tags', [
            'tags' => PublicFormService::embedTags($publicForm),
            'active' => boolval($publicForm->active_flg),
            'secure' => str_starts_with(strtolower($publicForm->getUrl()), 'https://'),
            'no_notice' => is_null($publicForm->notify_complete_admin),
            'edit_url' => admin_urls('formpublic', $this->custom_table->table_name, $publicForm->id, 'edit'),
        ])->render()));
        return $content;
    }

    /**
     * @return array<string, string> the email columns: name => label
     */
    protected function easyEmailColumns(): array
    {
        return $this->custom_table->custom_columns_cache->filter(function ($column) {
            return $column->column_type === ColumnType::EMAIL;
        })->mapWithKeys(function ($column) {
            return [strval($column->column_name) => strval($column->column_view_name)];
        })->toArray();
    }

    /**
     * @return array<string, string> the users who may be told of each submission: id => name
     */
    protected function easyUsers(): array
    {
        return getModelName(SystemTableName::USER)::query()->limit(500)->get()->mapWithKeys(function ($user) {
            return [strval($user->id) => strval($user->getLabel())];
        })->toArray();
    }
}
