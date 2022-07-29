<?php

namespace Exceedone\Exment\DataItems\Form;

use Encore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\ColumnItems\ItemInterface;

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
    protected function setColumnItemOption(ItemInterface $column_item, $custom_form_columns)
    {
        $column_item->options(['public_form' => $this->public_form]);
        parent::setColumnItemOption($column_item, $custom_form_columns);
    }
}
