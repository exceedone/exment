<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field  as AdminField;
use Exceedone\Exment\Form\EmbeddedForm;

class Embeds extends AdminField\Embeds
{
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
     * @return \Illuminate\View\View
     */
    public function render()
    {
        $form = $this->buildEmbeddedForm();

        // default
        if(!$this->gridEmbeds){
            return parent::render()->with([
                'form' => $form, 
                'enableHeader' => $this->enableHeader, 
                'footer_hr' => $this->footer_hr,
            ]);
        }


        ////// for grid column
        // sort by option row and column
        $fieldGroups = collect($form->getFieldAndOptions())->sortBy(function($fieldOption){
            $row = array_get($fieldOption, 'options.row', 1);
            $column = array_get($fieldOption, 'options.column', 1);
            return "{$row}-{$column}";
        })
        // grid form
        ->groupBy(function ($fieldOption, $key) {
            return array_get($fieldOption, 'options.row', 1);
        });

        // get column's max width
        $maxWidth = $fieldGroups->max(function($fieldGroups){
            return $fieldGroups->map(function($fieldOption) use($fieldGroups){
                return array_get($fieldOption, 'options.width', 1);
            })->sum();
        });

        // set group sm size
        $fieldGroups = $fieldGroups->map(function($fieldGroups) use($maxWidth){
            return $fieldGroups->map(function($fieldOption) use($fieldGroups, $maxWidth){
                $fieldOption['col_sm'] = array_get($fieldOption, 'options.width', 1) * (12 / $maxWidth);
                return $fieldOption;
            });
        });

        $this->view = 'exment::form.field.gridembeds';
        return parent::render()->with([
            'form' => $form, 
            'enableHeader' => $this->enableHeader, 
            'footer_hr' => $this->footer_hr,
            'fieldGroups' => $fieldGroups,
        ]);
    }
}
