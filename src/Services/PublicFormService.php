<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormBlock;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;

/**
 * Public forms made by code (ISS02942): a plugin (the support desk, ISS02941) or the easy setup of the
 * public form screen makes or updates a public form of a table with a list of columns, and gets the
 * tags to put it in a web site.
 *
 * - ensure(): the same owner key updates the same public form in place (its uuid, so its URL and
 *   its tags, stay). The custom form behind it is made or updated with the columns, in order.
 * - fixed_values: set on every submission (PublicFormController::create, before the model is
 *   saved: the saving and saved events of the table see them), not shown on the form.
 * - rate_limit: submissions a hour from one IP address (0: no limit).
 * - embedTags(): the URL, an iframe, and a script tag (embed.js puts an iframe there that takes
 *   the height of the form).
 */
class PublicFormService
{
    /** The option of a public form that keeps who made it. */
    public const OWNER_KEY = 'owner_key';

    public const FIXED_VALUES = 'fixed_values';

    public const RATE_LIMIT = 'rate_limit';

    /**
     * Make, or update in place, the public form of an owner.
     *
     * @param array<int, string> $columns the names of the columns of the table, in order
     * @param array<string, mixed> $opts view_name, active (true), fixed_values ([column name => value]),
     *        complete_title, complete_text, confirm (false), notify_user_column (an email column: the
     *        receipt mail to the person who sent it), notify_admin_users (user ids: a mail on each
     *        submission), recaptcha (true when reCAPTCHA is set on the site), rate_limit (0),
     *        required (column names that must be filled on this form; the columns themselves do not change)
     */
    public static function ensure(CustomTable $table, string $ownerKey, array $columns, array $opts = []): PublicForm
    {
        if (trim($ownerKey) === '') {
            throw new \InvalidArgumentException('PublicFormService: the owner key is empty');
        }
        $customColumns = static::columns($table, $columns);
        $required = array_values(array_map('strval', (array) ($opts['required'] ?? [])));
        foreach ($required as $name) {
            if (!in_array($name, $columns, true)) {
                throw new \InvalidArgumentException("PublicFormService: \"{$name}\" is required but not on the form");
            }
        }
        $fixed = (array) ($opts['fixed_values'] ?? []);
        foreach (array_keys($fixed) as $name) {
            if (is_null(CustomColumn::getEloquent(strval($name), $table))) {
                throw new \InvalidArgumentException("PublicFormService: no column \"{$name}\" for a fixed value");
            }
            if (in_array(strval($name), $columns, true)) {
                throw new \InvalidArgumentException("PublicFormService: \"{$name}\" is both on the form and a fixed value");
            }
        }

        $viewName = mb_substr(strval($opts['view_name'] ?? $table->table_view_name), 0, 40);

        return \ExmentDB::transaction(function () use ($table, $ownerKey, $customColumns, $opts, $fixed, $viewName, $required) {
            $publicForm = static::findByOwner($ownerKey);
            $customForm = isset($publicForm) ? $publicForm->custom_form : null;
            if (!($customForm instanceof CustomForm) || intval($customForm->custom_table_id) !== intval($table->id)) {
                $customForm = new CustomForm();
                $customForm->custom_table_id = $table->id;
                $customForm->default_flg = false;
            }
            $customForm->form_view_name = $viewName;
            $customForm->save();
            static::setFormColumns($table, $customForm, $customColumns, $required);

            if (!isset($publicForm)) {
                $publicForm = new PublicForm();
                $publicForm->proxy_user_id = \Exment::getUserId() ?? static::firstSystemAdmin();
            }
            $publicForm->custom_form_id = $customForm->id;
            $publicForm->public_form_view_name = $viewName;
            $publicForm->active_flg = boolval($opts['active'] ?? true);

            // the options: the defaults of the screen, then what is asked
            $options = array_merge(static::defaultOptions($viewName), jsonToArray($publicForm->options));
            $options[static::OWNER_KEY] = $ownerKey;
            $options[static::FIXED_VALUES] = $fixed;
            $options[static::RATE_LIMIT] = max(0, intval($opts['rate_limit'] ?? 0));
            $options['use_confirm'] = boolval($opts['confirm'] ?? false);
            $options['use_recaptcha'] = boolval($opts['recaptcha'] ?? (PublicForm::isEnableRecaptcha() === true));
            foreach (['complete_title', 'complete_text'] as $key) {
                if (array_key_exists($key, $opts)) {
                    $options[$key] = strval($opts[$key]);
                }
            }

            // the receipt mail to the person who sent it, and the mail to the staff
            $userColumn = isset($opts['notify_user_column']) ? CustomColumn::getEloquent(strval($opts['notify_user_column']), $table) : null;
            $options['use_notify_complete_user'] = isset($userColumn);
            if (isset($userColumn)) {
                $publicForm->notify_actions_complete_user = ['notify_action' => NotifyAction::EMAIL, 'notify_action_target' => [strval($userColumn->id)]];
                $publicForm->notify_mail_template_complete_user = static::mailTemplateId(MailKeyName::PUBLICFORM_COMPLETE_USER);
            }
            $adminUsers = array_values(array_filter(array_map('intval', (array) ($opts['notify_admin_users'] ?? []))));
            $options['use_notify_complete_admin'] = count($adminUsers) > 0;
            if (count($adminUsers) > 0) {
                $publicForm->notify_actions_complete_admin = [[
                    'notify_action' => NotifyAction::EMAIL,
                    'notify_action_target' => [NotifyActionTarget::FIXED_USER],
                    'target_users' => $adminUsers,
                ]];
                $publicForm->notify_mail_template_complete_admin = static::mailTemplateId(MailKeyName::PUBLICFORM_COMPLETE_ADMIN);
            }
            $publicForm->options = $options;
            $publicForm->save();
            // the notifies were read before they were made (toggleNotify): read again when asked
            $publicForm->unsetRelations();

            return $publicForm;
        });
    }

    /**
     * The public form of an owner, or null.
     */
    public static function findByOwner(string $ownerKey): ?PublicForm
    {
        /** @var PublicForm|null $publicForm */
        $publicForm = PublicForm::query()->where('options->' . static::OWNER_KEY, $ownerKey)->first();
        return $publicForm;
    }

    /**
     * The tags to put the public form in a web site.
     *
     * @return array{url: string, iframe: string, script: string}
     */
    public static function embedTags(PublicForm $form): array
    {
        $url = $form->getUrl();
        $title = e(strval($form->public_form_view_name));
        $src = e($url . (str_contains($url, '?') ? '&' : '?') . 'embed=1');
        return [
            'url' => $url,
            'iframe' => '<iframe src="' . $src . '" title="' . $title . '" style="width:100%;min-height:600px;border:0" loading="lazy"></iframe>',
            'script' => '<script src="' . e($form->getUrl('embed.js')) . '" async></script>',
        ];
    }

    /**
     * The values a submission of the public form always gets.
     *
     * @return array<string, mixed>
     */
    public static function fixedValues(PublicForm $form): array
    {
        return (array) $form->getOption(static::FIXED_VALUES, []);
    }

    /**
     * @param array<int, string> $names
     * @return array<int, CustomColumn>
     */
    protected static function columns(CustomTable $table, array $names): array
    {
        if (count($names) == 0) {
            throw new \InvalidArgumentException('PublicFormService: no columns');
        }
        $result = [];
        foreach ($names as $name) {
            $column = CustomColumn::getEloquent(strval($name), $table);
            if (!($column instanceof CustomColumn)) {
                throw new \InvalidArgumentException("PublicFormService: no column \"{$name}\" in " . $table->table_name);
            }
            $result[] = $column;
        }
        return $result;
    }

    /**
     * One block of the table with the columns, in order (the columns not in it any more go).
     *
     * @param array<int, CustomColumn> $columns
     * @param array<int, string> $required the names of the columns that must be filled on this form
     */
    protected static function setFormColumns(CustomTable $table, CustomForm $customForm, array $columns, array $required = []): void
    {
        /** @var CustomFormBlock|null $block */
        $block = CustomFormBlock::query()->where('custom_form_id', $customForm->id)->where('form_block_type', FormBlockType::DEFAULT)->first();
        if (!isset($block)) {
            $block = new CustomFormBlock();
            $block->custom_form_id = $customForm->id;
            $block->form_block_type = FormBlockType::DEFAULT;
            $block->form_block_target_table_id = $table->id;
        }
        $block->available = 1;
        $block->save();

        CustomFormColumn::query()->where('custom_form_block_id', $block->id)->delete();
        $order = 1;
        foreach ($columns as $column) {
            $formColumn = new CustomFormColumn();
            $formColumn->custom_form_block_id = $block->id;
            $formColumn->form_column_type = FormColumnType::COLUMN;
            $formColumn->form_column_target_id = $column->id;
            $formColumn->row_no = 1;
            $formColumn->column_no = 1;
            $formColumn->width = 4;
            $formColumn->order = $order++;
            // required on this form only (CustomItem::required reads the options of the form column)
            $formColumn->options = in_array(strval($column->column_name), $required, true) ? ['required' => '1'] : [];
            $formColumn->save();
        }
    }

    /**
     * The options the public form screen starts with.
     *
     * @return array<string, mixed>
     */
    public static function defaultOptions(string $viewName): array
    {
        return [
            'use_header' => true,
            'header_background_color' => '#3c8dbc',
            'header_label' => $viewName,
            'header_text_color' => '#FFFFFF',
            'background_color_outer' => '#FFFFFF',
            'background_color' => '#FFFFFF',
            'use_footer' => true,
            'footer_background_color' => '#000000',
            'footer_text_color' => '#FFFFFF',
            'use_confirm' => false,
            'confirm_title' => strval(exmtrans('custom_form_public.message.confirm_title')),
            'confirm_text' => strval(exmtrans('custom_form_public.message.confirm_text')),
            'complete_title' => strval(exmtrans('custom_form_public.message.complete_title')),
            'complete_text' => strval(exmtrans('custom_form_public.message.complete_text')),
            'error_title' => strval(exmtrans('custom_form_public.message.error_title')),
            'error_text' => strval(exmtrans('custom_form_public.message.error_text')),
            'use_default_query' => false,
        ];
    }

    protected static function mailTemplateId(string $keyName): ?int
    {
        $template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $keyName)->first();
        return isset($template) ? intval($template->id) : null;
    }

    protected static function firstSystemAdmin(): ?int
    {
        $ids = (array) (System::system_admin_users() ?? []);
        return count($ids) > 0 ? intval(reset($ids)) : null;
    }
}
