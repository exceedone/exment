<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field  as AdminField;
use Exceedone\Exment\Form\EmbeddedForm;

class Embeds extends AdminField\Embeds
{
    use FieldGroupTrait;

    protected $view = 'exment::form.field.embeds';

    protected $enableHeader = true;

    protected $footer_hr = false;

    /**
     * Whether grid embeds
     *
     * @var boolean
     */
    protected $gridEmbeds = false;

    public function disableHeader()
    {
        $this->enableHeader = false;

        return $this;
    }

    public function footerHr($footer_hr = true)
    {
        $this->footer_hr = $footer_hr;

        return $this;
    }

    /**
     * Set as gridEmbeds
     *
     * @return $this
     */
    public function gridEmbeds()
    {
        $this->gridEmbeds = true;
        return $this;
    }

    /**
     * get fields in NestedEmbeddedForm
     */
    public function fields()
    {
        return $this->buildEmbeddedForm()->fields();
    }

    /**
     * Build a Embedded Form and fill data.
     *
     * @return EmbeddedForm
     */
    protected function buildEmbeddedForm()
    {
        $form = new EmbeddedForm($this->column);
        return $this->setFormField($form);
    }


    /**
     * Set form field.
     *
     * @param EmbeddedForm $form
     * @return EmbeddedForm
     */
    protected function setFormField($form)
    {
        // reset
        $form->setParent($this->form);

        call_user_func($this->builder, $form);

        $form->fill($this->getEmbeddedData());

        return $form;
    }

    /**
     * Render the form.
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        $form = $this->buildEmbeddedForm();

        // default
        if (!$this->gridEmbeds) {
            return parent::render()->with([
                'form' => $form,
                'enableHeader' => $this->enableHeader,
                'footer_hr' => $this->footer_hr,
            ]);
        }


        ////// for grid column
        // sort by option row and column
        $fieldGroups = $this->convertRowColumnGroups($form->getFieldAndOptions());

        $this->view = 'exment::form.field.gridembeds';
        return parent::render()->with([
            'form' => $form,
            'enableHeader' => $this->enableHeader,
            'footer_hr' => $this->footer_hr,
            'fieldGroups' => $fieldGroups,
        ]);
    }
}
