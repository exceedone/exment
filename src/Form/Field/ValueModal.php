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

    /**
     * @var string
     */
    protected $buttonlabel;

    /**
     * @var string modal url
     */
    protected $url;

    /**
     * @var callable|string modal body
     */
    protected $modalbody;

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
     * Available buttons.
     *
     * @var array
     */
    protected $buttons = ['reset', 'setting'];

    /**
     * Set modal body
     *
     *
     * @return $this|mixed
     */
    public function modalbody($modalbody)
    {
        $this->modalbody = $modalbody;
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

    protected function script(){
        $classname = $this->getElementClassString();
        $post_names = collect($this->post_names)->toJson();
        $script = <<<EOT
$('.{$classname}-block').on('click', '.btn-valuemodal', function () {
    $('.{$classname}-block .modal').modal();
});

EOT;

        Admin::script($script);

    }


    /**
     * {@inheritdoc}
     */
    public function render()
    {
        // $configs = array_merge([
        //     'allowClear'  => true,
        //     'placeholder' => $this->label,
        // ], $this->config);

        //$configs = json_encode($configs);

        // set text
        if ($this->text instanceof \Closure) {
            if ($this->form) {
                $this->text = $this->text->bindTo($this->form->model());
            }

            $this->text(call_user_func($this->text, $this->value));
        }

        // set modalbody
        if ($this->modalbody instanceof \Closure) {
            if ($this->form) {
                $this->modalbody = $this->modalbody->bindTo($this->form->model());
            }

            $this->modalbody(call_user_func($this->modalbody, $this->value));
        }
        if ($this->modalbody instanceof Renderable) {
            $this->modalbody = $this->modalbody->render();
        }

        // set button label
        if(is_null($this->buttonlabel)){
            $this->buttonlabel = exmtrans('common.change');
        }

        // set script
        $this->script();

        return parent::render()->with([
            'text'   => $this->text,
            'buttonlabel'   => $this->buttonlabel,
            'buttons'   => $this->buttons,
            'modalbody' => $this->modalbody,
        ]);
    }
}
