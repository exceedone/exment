<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Admin;
use Encore\Admin\Form\NestedForm;
use Encore\Admin\Form\Field\HasMany as AdminHasMany;
use Illuminate\Support\Arr;

/**
 * Class HasMany.
 */
class HasMany extends AdminHasMany
{
    protected $countscript;
  
    /**
     * Render the `HasMany` field.
     *
     * @throws \Exception
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        // specify a view to render.
        $this->view = $this->views[$this->viewMode];

        $form = $this->buildNestedForm($this->column, $this->builder);
        list($template, $script) = $this->getTemplateHtmlAndScript($form);

        $this->setupScript($script);

        $grandParent = get_parent_class(get_parent_class($this));
        return $grandParent::render()->with([
            'forms'        => $this->buildRelatedForms(),
            'template'     => $template,
            'relationName' => $this->relationName,
            'options'      => $this->options,
//            'header'       => $this->header
        ]);
    }
    public function setCountScript($targets)
    {
        if (empty($targets)) {
            return;
        }
        $data = json_encode($targets);
        $this->countscript .= <<<EOT
Exment.CommonEvent.setCalc(null, $data);
EOT;
    }
    
    /**
     * TODO: I don't know the best way
     * set html and script. It has bug about nested
     */
    protected function getTemplateHtmlAndScript($form)
    {
        list($template, $script) = $form->getTemplateHtmlAndScript();

        // re-set $script
        $scripts = [];
        foreach ($form->fields() as $field) {
            // when NestedEmbeds item, get NestedEmbeds's getScript()
            if (method_exists($field, 'getScript')) {
                $scripts[] = $field->getScript();
            }
        }

        return [$template, implode("\r\n", $scripts)];
    }

    /**
     * Setup default template script.
     *
     * @param string $templateScript
     *
     * @return void
     */
    protected function setupScriptForDefaultView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;
        $count = $this->getHasManyCount();
        $indexName = "index_{$this->column}";

        /**
         * When add a new sub form, replace all element key in new sub form.
         *
         * @example comments[new___key__][title]  => comments[new_{index}][title]
         *
         * {count} is increment number of current sub form count.
         */
        $script = <<<EOT
var $indexName = {$count};
$('#has-many-{$this->column}').on('click', '.add', function () {

    var tpl = $('template.{$this->column}-tpl');

    $indexName++;

    var template = tpl.html().replace(/{$defaultKey}/g, $indexName);
    $('.has-many-{$this->column}-forms').append(template);
    {$templateScript}
    {$this->countscript}
});

$('#has-many-{$this->column}').on('click', '.remove', function () {
    $(this).closest('.has-many-{$this->column}-form').hide();
    $(this).closest('.has-many-{$this->column}-form').find('input[required], select[required]').prop('disabled', true);
    $(this).closest('.has-many-{$this->column}-form').find('.$removeClass').val(1);
    {$this->countscript}
});

EOT;

        Admin::script($script);

        return $script;
    }

    public function getScript()
    {
        list($template, $script) = $this->buildNestedForm($this->column, $this->builder)
            ->getTemplateHtmlAndScript();

        return $this->setupScript($script);
    }

    /**
     * Setup default template script.
     *
     * @param string $templateScript
     *
     * @return void
     */
    protected function setupScriptForTableView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;
        $count = !isset($this->value) ? 0 : count($this->value);
        $indexName = "index_{$this->column}";

        /**
         * When add a new sub form, replace all element key in new sub form.
         *
         * @example comments[new___key__][title]  => comments[new_{index}][title]
         *
         * {count} is increment number of current sub form count.
         */
        $script = <<<EOT
var $indexName = {$count};
$('#has-many-{$this->column}').on('click', '.add', function () {

    var tpl = $('template.{$this->column}-tpl');

    $indexName++;

    var template = tpl.html().replace(/{$defaultKey}/g, $indexName);
    $('.has-many-{$this->column}-forms').append(template);
    {$templateScript}
});

$('#has-many-{$this->column}').on('click', '.remove', function () {
    $(this).closest('.has-many-{$this->column}-form').hide();
    $(this).closest('.has-many-{$this->column}-form').find('input[required], select[required]').prop('disabled', true);
    $(this).closest('.has-many-{$this->column}-form').find('.$removeClass').val(1);
});

EOT;

        Admin::script($script);
    }

    /**
     * Get validator for this field.
     *
     * @param array $input
     *
     * @return bool|\Illuminate\Contracts\Validation\Validator
     */
    public function getValidator(array $input)
    {
        if (!array_key_exists($this->column, $input)) {
            return false;
        }

        $input = Arr::only($input, $this->column);

        // remove NestedForm::REMOVE_FLAG_NAME = 1 in input
        foreach ($input as $key => &$i) {
            if ($key !== $this->column) {
                continue;
            }

            $i = collect($i)->filter(function ($i) {
                if (Arr::has($i, NestedForm::REMOVE_FLAG_NAME) && boolval(Arr::get($i, NestedForm::REMOVE_FLAG_NAME))) {
                    return false;
                }
                return true;
            })->toArray();
        }

        $form = $this->buildNestedForm($this->column, $this->builder);

        $rules = $attributes = [];

        /* @var Field $field */
        foreach ($form->fields() as $field) {
            if (!$fieldRules = $field->getRules()) {
                continue;
            }

            $column = $field->column();

            if (is_array($column)) {
                foreach ($column as $key => $name) {
                    $rules[$name.$key] = $fieldRules;
                }

                $this->resetInputKey($input, $column);
            } elseif ($field instanceof NestedEmbeds) {
                foreach ($fieldRules as $key => $fieldRule) {
                    $rules["$column.$key"] = $fieldRule;
                }
                $attributes = array_merge(
                    $attributes,
                    $field->getAttributes()
                );
            } else {
                $rules[$column] = $fieldRules;
            }

            $attributes = array_merge(
                $attributes,
                $this->formatValidationAttribute($input, $field->label(), $column)
            );
        }

        Arr::forget($rules, NestedForm::REMOVE_FLAG_NAME);

        if (empty($rules)) {
            return false;
        }

        $newRules = [];
        $newInput = [];

        foreach ($rules as $column => $rule) {
            foreach (array_keys($input[$this->column]) as $key) {
                $newRules["{$this->column}.$key.$column"] = $rule;
                if (isset($attributes[$column])) {
                    $attributes["{$this->column}.$key.$column"] = $attributes[$column];
                }
                if (isset($input[$this->column][$key][$column]) &&
                    is_array($input[$this->column][$key][$column])) {
                    foreach ($input[$this->column][$key][$column] as $vkey => $value) {
                        $newInput["{$this->column}.$key.{$column}$vkey"] = $value;
                    }
                }
            }
        }

        if (empty($newInput)) {
            $newInput = $input;
        }

        return \validator($newInput, $newRules, $this->getValidationMessages(), $attributes);
    }

    /**
     * Get hasmany Count
     *
     * @return void
     */
    protected function getHasManyCount()
    {
        if (isset($this->count)) {
            return $this->count;
        }

        if (!empty($v = $this->value())) {
            return count($v);
        }

        return 0;
    }
}
