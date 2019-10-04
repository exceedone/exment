<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Facades\Admin;
use Encore\Admin\Form\Field;
use Illuminate\Contracts\Support\Renderable;

/**
 * Open Modal and set value selecting modal body.
 * TODO:this field is now only supported custom_column options_calc_formula.
 */
class ValueModal extends Field
{
    protected $view = 'exment::form.field.valuemodal';

    protected $valueTextScript;

    /**
     * @var string
     */
    protected $buttonlabel;

    /**
     * @var string
     */
    protected $modalContentname;

    /**
     * @var string modal ajax
     */
    protected $ajax;

    /**
     * @var array modal ajax posting names
     */
    protected $post_names = [];

    /**
     * Set text.
     *
     * @param callable|string $text
     *
     * @return $this|mixed
     */
    public function text($text = '')
    {
        $this->text = $text;
        return $this;
    }

    /**
     * Set modal content key name.
     *
     * @param string $modalContentname
     *
     * @return $this|mixed
     */
    public function modalContentname($modalContentname = '')
    {
        $this->modalContentname = $modalContentname;
        return $this;
    }

    /**
     * Set ajax.
     *
     * @param string $text
     *
     * @return $this|mixed
     */
    public function ajax($ajax = '')
    {
        $this->ajax = $ajax;
        return $this;
    }

    /**
     * Set button label.
     *
     * @param string $buttonlabel
     *
     * @return $this
     */
    public function buttonlabel(string $buttonlabel)
    {
        $this->buttonlabel = $buttonlabel;
        return $this;
    }

    protected function script()
    {
        $classname = $this->getElementClassSelector();
        $modalContentname = $this->modalContentname;
        $post_names = collect($this->post_names)->toJson();
        $ajax = $this->ajax;
        $valueTextScript = $this->valueTextScript;

        $script = <<<EOT

        // Set to close button modal event
        let keyname = '[data-contentname="$modalContentname"] .modal-submit';
        $(document).off('click', keyname).on('click', keyname, {}, function(ev){
            let valText = {$valueTextScript};
            
            // set value and text
            $('$classname').val(valText.value);
            $('$classname').closest('.block-valuemodal').find('.text-valuemodal').text(valText.text);

            $('.modal').modal('hide');
        });

        keyname = '[data-contentname="$modalContentname"] .modal-close';
        $(document).off('click', keyname).on('click', keyname, {}, function(ev){
            $('$classname').val(null);
            $('$classname').closest('.block-valuemodal').find('.text-valuemodal').text('');
        });

EOT;
        $this->script = $script;
    }

    /**
     * Set value and text script
     *
     * @param string $script
     * @return $this|mixed
     */
    public function valueTextScript($script){
        $this->valueTextScript = $script;

        return $this;
    }
    
    /**
     * {@inheritdoc}
     */
    public function render()
    {
        // set text
        if ($this->text instanceof \Closure) {
            if ($this->form) {
                $this->text = $this->text->bindTo($this->form->model());
            }

            $this->text(call_user_func($this->text, $this->value));
        }

       
        // set button label
        if (is_null($this->buttonlabel)) {
            $this->buttonlabel = exmtrans('common.change');
        }
 
        // set button label
        if (is_array($this->value)) {
            $this->value = json_encode($this->value);
        }   

        // set script
        $this->script();

        return parent::render()->with([
            'text'   => $this->text,
            'buttonlabel'   => $this->buttonlabel,
            'ajax' => $this->ajax,
        ]);
    }
}
