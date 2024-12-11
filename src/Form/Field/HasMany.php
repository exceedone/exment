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
    /**
     * Render the `HasMany` field.
     *
     * @throws \Exception
     *
     * @return \Illuminate\View\View|string
     */
    public function render()
    {
        // remove "asterisk" if has required
        if (array_has($this->attributes, 'required')) {
            $this->labelClass = array_filter($this->labelClass, function ($a) {
                return $a !== 'asterisk';
            });
        }

        // specify a view to render.
        $this->view = $this->views[$this->viewMode];

        $form = $this->buildNestedForm($this->column, $this->builder);
        list($template, $script) = $this->getTemplateHtmlAndScript($form);

        $this->setupScript($script);

        $grandParent = $this->getParentRenderClass();
        return $grandParent::render()->with([
            'forms'        => $this->buildRelatedForms(),
            'template'     => $template,
            'relationName' => $this->relationName,
            'options'      => $this->options,
            'enableHeader' => $this->enableHeader,
        ]);
    }

    /**
     * TODO: I don't know the best way
     * set html and script. It has bug about nested
     */
    protected function getTemplateHtmlAndScript($form)
    {
        list($template, $script) = $form->getTemplateHtmlAndScript();
        return [$template, $script];
        
        // // re-set $script
        // $scripts = [];
        // foreach ($form->fields() as $field) {
        //     // when NestedEmbeds item, get NestedEmbeds's getScript()
        //     if (method_exists($field, 'getScript')) {
        //         $scripts[] = $field->getScript();
        //     }
        // }

        // return [$template, implode("\r\n", $scripts)];
    }

    /**
     * Setup default template script.
     *
     * @param string $templateScript
     *
     * @return string
     */
    protected function setupScriptForDefaultView($templateScript)
    {
        $removeClass = NestedForm::REMOVE_FLAG_CLASS;
        $defaultKey = NestedForm::DEFAULT_KEY_NAME;
        $count = $this->getHasManyCount();
        $indexName = "index_{$this->column}";

        $errortitle = exmtrans("common.error");
        $requiremessage = sprintf(exmtrans("common.message.exists_row"), $this->label);

        /**
         * When add a new sub form, replace all element key in new sub form.
         *
         * @example comments[new___key__][title]  => comments[new_{index}][title]
         *
         * {count} is increment number of current sub form count.
         */
        $script = <<<EOT
var $indexName = {$count};
$('#has-many-{$this->column}').off('click.admin_add').on('click.admin_add', '.add', function () {

    var tpl = $('template.{$this->column}-tpl');

    $indexName++;

    var template = tpl.html().replace(/{$defaultKey}/g, $indexName);
    $('.has-many-{$this->column}-forms').append(template);
    {$templateScript}
    $(this).trigger('admin_hasmany_row_change');
});

$('#has-many-{$this->column}').off('click.admin_remove').on('click.admin_remove', '.remove', function () {
    $(this).closest('.has-many-{$this->column}-form').hide();
    $(this).closest('.has-many-{$this->column}-form').find('input[required], select[required]').prop('disabled', true);
    $(this).closest('.has-many-{$this->column}-form').find('.$removeClass').val(1);

    $(this).trigger('admin_hasmany_row_change');
});

$("button[type='submit']").click(function(){
    if ($('#has-many-{$this->column}').attr('required') === undefined) {
        return true;
    }
    var cnt = $('#has-many-{$this->column} .has-many-{$this->column}-forms > .fields-group').filter(':visible').length;
    if (cnt == 0) {
        swal("$errortitle", "$requiremessage", "error");
        return false;
    };
    return true;
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
            $column = $field->column();
            // if NestedEmbeds, loop hasmany items
            if ($field instanceof NestedEmbeds) {
                $nestedValues = Arr::get($input, $this->column);
                if (!is_array($nestedValues)) {
                    continue;
                }
                foreach ($nestedValues as $nestedKey => $nestedValue) {
                    if (!$fieldRules = $field->getRules()) {
                        continue;
                    }
                    foreach ($fieldRules as $key => $fieldRule) {
                        $r = Arr::has($rules, "$column.$key") ? $rules["$column.$key"]['rules'] : [];
                        $r[$nestedKey] = $fieldRule;
                        $rules["$column.$key"] = ['hasmany' => true, 'rules' => $r];
                    }
                }
                $attributes = array_merge(
                    $attributes,
                    $field->getAttributes()
                );
            } else {
                if (!$fieldRules = $field->getRules()) {
                    continue;
                }

                if (is_array($column)) {
                    foreach ($column as $key => $name) {
                        $rules[$name.$key] = ['hasmany' => false, 'rules' => $fieldRules];
                    }

                    $this->resetInputKey($input, $column);
                } else {
                    $rules[$column] = ['hasmany' => false, 'rules' => $fieldRules];
                }
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
                if ($rule['hasmany']) {
                    $newRules["{$this->column}.$key.$column"] = Arr::get($rule['rules'], $key);
                } else {
                    $newRules["{$this->column}.$key.$column"] = $rule['rules'];
                }

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
     * @return int
     */
    protected function getHasManyCount()
    {
        if (isset($this->count)) {
            return $this->count;
        }

        if (!empty($v = $this->getOld())) {
            return count($v);
        }

        return 0;
    }


    protected function getParentRenderClass()
    {
        return get_parent_class(get_parent_class($this));
    }
}
