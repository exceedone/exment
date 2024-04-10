<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\NestedForm;
use Illuminate\Support\Arr;

/**
 * Class HasMany.
 */
trait HasManyJsonTrait
{
    /**
     * Get the HasMany relation key name.
     *
     * @return string|null
     */
    protected function getKeyName()
    {
        return null;
    }


    public function prepare($input)
    {
        $input = parent::prepare($input);

        $values =  collect($input)->reject(function ($item) {
            return $item[NestedForm::REMOVE_FLAG_NAME] == 1;
        })->map(function ($item) {
            unset($item[NestedForm::REMOVE_FLAG_NAME]);

            return $item;
        })->filter(function ($item) {
            return !is_nullorempty($item);
        })->values();

        if (is_nullorempty($values)) {
            return null;
        }

        return $values->toArray();
    }

    /**
     * Build Nested form for json data.
     *
     * @throws \Exception
     *
     * @return array
     */
    protected function buildRelatedForms()
    {
        $forms = [];

        /** @phpstan-ignore-next-line Call to function is_null() with array will always evaluate to false. */
        if (!is_null($this->relatedValue)) {
            foreach ($this->relatedValue as $index => $data) {
                $forms[$index] = $this->buildNestedForm($this->column, $this->builder, null, $index)
                    ->fill($data, $index);
            }
        }

        /** @phpstan-ignore-next-line Call to function is_null() with Encore\Admin\Form will always evaluate to false. */
        if (is_null($this->form)) {
            return $forms;
        }

        $model = $this->form->model();

        /** @phpstan-ignore-next-line Call to function is_null() with array will always evaluate to false. */
        if (is_null($this->value)) {
            $this->value = [];
            /** @phpstan-ignore-next-line Left side of && is always false. */
        } elseif (is_string($this->value) && is_json($this->value)) {
            $this->value = json_decode_ex($this->value, true);
        }

        /*
         * If redirect from `exception` or `validation error` page.
         *
         * Then get form data from session flash.
         *
         * Else get data from database.
         */
        if ($values = old($this->column)) {
            $index = 0;
            foreach ($values as $key => $data) {
                if ($data[NestedForm::REMOVE_FLAG_NAME] == 1) {
                    continue;
                }

                // If has value, reset forms
                if ($index === 0) {
                    $forms = [];
                }

                $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $key, $index)
                    ->fill($data, $index);
                $index++;
            }
        } else {
            foreach ($this->value as $index => $data) {
                if (is_int($index)) {
                    $key = make_uuid();
                } else {
                    $key = $index;
                }
                // $key = Arr::get($data, $relation->getRelated()->getKeyName());

                // if(!isset($key)){
                //     $key = 'new_' . ($index + 1);
                // }

                // If has value, reset forms
                if ($index === 0) {
                    $forms = [];
                }

                // $forms[$key] = $this->buildNestedForm($this->column, $this->builder, $data, $index)
                //     ->fill($data, $index);
                $forms[] = $this->buildNestedForm($this->column, $this->builder, $key, $index)
                    ->fill($data, $index);
            }
        }

        return $forms;
    }


    protected function buildNestedForm($column, \Closure $builder, $key = null, $index = null)
    {
        $form = new NestedForm($column);

        $form->setIndex($index);

        $form->setForm($this->form)
            ->setKey($key);

        call_user_func($builder, $form);

        $form->hidden(NestedForm::REMOVE_FLAG_NAME)->default(0)->addElementClass(NestedForm::REMOVE_FLAG_CLASS);

        return $form;
    }
}
