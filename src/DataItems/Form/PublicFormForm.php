<?php

namespace Exceedone\Exment\DataItems\Form;

use ExmentAdminCore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\ColumnItems\ItemInterface;
use Exceedone\Exment\Enums\FormBlockType;
use Exceedone\Exment\Services\PublicFormService;

/**
 * Public form's form
 */
class PublicFormForm extends DefaultForm
{
    /**
     * Public Form
     *
     * @var PublicForm
     */
    protected $public_form;

    /**
     * If true, all disabled tools button
     *
     * @var boolean
     */
    protected $disableToolsButton = true;

    /**
     * If true, disableSavedRedirectCheck
     *
     * @var boolean
     */
    protected $disableSavedRedirectCheck = true;

    /**
     * If true, disableDefaultSavedRedirect.
     *
     * @var boolean
     */
    protected $disableDefaultSavedRedirect = true;

    /**
     * Set public Form
     *
     * @param  PublicForm  $public_form  Public Form
     *
     * @return  self
     */
    public function setPublicForm(PublicForm $public_form)
    {
        $this->public_form = $public_form;

        return $this;
    }
    /**
     * Set ColumnItem's option to column item
     *
     * @param ItemInterface $column_item
     * @return void
     */
    /**
     * The values every submission of the public form gets (ISS02942, PublicFormService): internal
     * fields of the block of the table, not shown, their value the fixed one.
     *
     * @param mixed $form
     * @param mixed $custom_form_block
     */
    protected function appendEmbedFields($form, $custom_form_block): void
    {
        if (intval($custom_form_block->form_block_type) !== FormBlockType::DEFAULT) {
            return;
        }
        foreach (PublicFormService::fixedValues($this->public_form) as $name => $value) {
            $form->internal(strval($name))->default($value);
        }
    }

    // @phpstan-ignore-next-line
    protected function setColumnItemOption(ItemInterface $column_item, $custom_form_columns)
    {
        $column_item->options(['public_form' => $this->public_form]);
        parent::setColumnItemOption($column_item, $custom_form_columns);
    }
}
