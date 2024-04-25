<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Illuminate\Support\Collection;

/**
 * Open Modal and set value selecting modal body.
 * TODO:this field is now only supported custom_column options_calc_formula.
 */
class ValueModal extends Field
{
    protected $view = 'exment::form.field.valuemodal';

    protected $valueTextScript;

    /**
     * @var string|array|\Closure|Collection
     */
    protected $text;

    /**
     * @var string
     */
    protected $nullText;

    /**
     * @var string|\Closure
     */
    protected $nullValue;

    /**
     * @var string|null
     */
    protected $buttonlabel;

    /**
     * @var string|null
     */
    protected $buttonClass;

    /**
     * @var string
     */
    protected $modalContentname;

    /**
     * @var \Closure|string
     */
    protected $hiddenFormat;

    /**
     * @var string modal ajax
     */
    protected $ajax;

    protected $escape = true;

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
     * Set nullText.
     *
     * @param string $nullText
     *
     * @return $this|mixed
     */
    public function nullText($nullText = '')
    {
        $this->nullText = $nullText;
        return $this;
    }

    /**
     * Set nullValue.
     *
     * @param string $nullValue
     *
     * @return $this|mixed
     */
    public function nullValue($nullValue = '')
    {
        $this->nullValue = $nullValue;
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
     * @param string $ajax
     *
     * @return $this
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

    /**
     * Set button class.
     *
     * @param string $buttonClass
     *
     * @return $this
     */
    public function buttonClass($buttonClass)
    {
        $this->buttonClass = $buttonClass;
        return $this;
    }

    public function escape(bool $escape = true)
    {
        $this->escape = $escape;

        return $this;
    }

    protected function script()
    {
        $classname = $this->getElementClassSelector();
        $modalContentname = $this->modalContentname;
        $post_names = collect($this->post_names)->toJson();
        $ajax = $this->ajax;
        $valueTextScript = $this->valueTextScript ?? '{value:null, text:null}';

        $script = <<<EOT

        // Set to submit button modal event
        {
            let keyname = '[data-contentname="$modalContentname"] .modal-submit';
            $(document).off('click', keyname).on('click', keyname, {}, function(ev){
                ev.preventDefault();

                let valText = {$valueTextScript};
                if(!hasValue(valText)){
                    return;
                }

                // set value and text
                let target = getValueModalTarget();
                target.find('.value-valuemodal').val(valText.value);
                target.find('.text-valuemodal').html(valText.text);

                if(!hasValue(valText.text)){
                    let nullText = target.find('.nulltext-valuemodal').val();
                    target.find('.text-valuemodal').text(nullText);
                }

                let forms = $('.modal form').get();

                if(forms.length > 0 &&!forms[0].reportValidity()){
                    return;
                }

                $('.modal').modal('hide');
            });

            // Set to reset event
            keyname = '[data-contentname="$modalContentname"] .modal-reset';
            $(document).off('click', keyname).on('click', keyname, {}, function(ev){
                ev.preventDefault();

                let target = getValueModalTarget();
                let nullValue = target.find('.nullvalue-valuemodal').val();
                target.find('.value-valuemodal').val(nullValue);

                let nullText = target.find('.nulltext-valuemodal').val();
                target.find('.text-valuemodal').text(nullText);
            });

            function getValueModalTarget(){
                let valueModalUuid = $('.modal .valueModalUuid').val();
                if(hasValue(valueModalUuid)){
                    return $('[data-widgetmodal_uuid="' + valueModalUuid + '"]').closest('.block-valuemodal');
                }

                return  $('$classname').closest('.block-valuemodal');
            }
        }
EOT;
        $this->script = $script;
    }

    /**
     * Set value and text script
     *
     * @param string $script
     * @return $this|mixed
     */
    public function valueTextScript($script)
    {
        $this->valueTextScript = $script;

        return $this;
    }

    /**
     * Callback hidden value
     *
     * @param string $hiddenFormat
     * @return $this
     */
    public function hiddenFormat($hiddenFormat)
    {
        $this->hiddenFormat = $hiddenFormat;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        // set text
        if ($this->text instanceof \Closure) {
            /** @phpstan-ignore-next-line Left side of && is always true and Right side of && is always true. */
            if ($this->form && $this->form->model()) {
                $this->text = $this->text->bindTo($this->form->model());
            }

            $this->text(call_user_func($this->text, $this->value, $this));
        }

        if (is_array($this->text) || $this->text instanceof \Illuminate\Support\Collection) {
            $this->text = collect($this->text)->map(function ($t) {
                return $this->escape ? esc_html($t) : $t;
            })->implode('<br />');
        } else {
            $this->text = $this->escape ? esc_html($this->text) : $this->text;
        }

        // convert value
        $this->value = $this->convertString($this->value);

        // set hidden
        $hidden = $this->value;
        if ($this->hiddenFormat instanceof \Closure) {
            $hidden = call_user_func($this->hiddenFormat, $this->value, $this);
        }

        $nullValue = $this->nullValue;
        if ($this->nullValue instanceof \Closure) {
            $nullValue = call_user_func($this->nullValue, $this->value, $this);
        }

        // set button label
        if (is_null($this->buttonlabel)) {
            $this->buttonlabel = exmtrans('common.change');
        }

        // set button class
        if (is_null($this->buttonClass)) {
            $this->buttonClass = 'btn-default';
        }

        // set script
        $this->script();

        return parent::render()->with([
            'text'   => $this->text,
            'hidden' => $hidden,
            'nullText'   => $this->nullText,
            'nullValue'   => $nullValue,
            'buttonlabel'   => $this->buttonlabel,
            'buttonClass'   => $this->buttonClass,
            'ajax' => $this->ajax,
            'modalContentname' => $this->modalContentname,
        ]);
    }

    /**
     * convert string if value is array
     *
     * @return string
     */
    protected function convertString($value)
    {
        if (is_array($value)) {
            return json_encode($value);
        }

        return $value;
    }
}
