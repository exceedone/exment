<?php

namespace Exceedone\Exment\DataItems\Form;

use Encore\Admin\Form;
use Exceedone\Exment\Form\Tools;
use Exceedone\Exment\Model\PublicForm;

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
}
