<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Checkbox;

class CheckboxTable extends Checkbox
{
    protected $view = 'exment::form.field.checkboxtable';
    
    protected $checkWidth = 100;
    protected $items = [];
    protected $headerHelps = [];

    public function checkWidth($checkWidth)
    {
        $this->checkWidth = $checkWidth;

        return $this;
    }

    /**
     * table items.
     * [
     *     'label' => label name,
     *     'values' => selected values,
     *     'name' => checkbox name,
     *     'disables' => disable items. set value name.
     * ]
     *
     * @param array $items
     * @return $this
     */
    public function items($items)
    {
        $this->items = $items;
        
        return $this;
    }

    /**
     * Get items. Append error.
     *
     * @return array
     */
    protected function getItems()
    {
        $result = [];
        $errors = request()->session()->get('errors') ?: new \Illuminate\Support\ViewErrorBag;

        foreach ($this->items as $item) {
            if ($errors->has(array_get($item, 'key'))) {
                $item['error'] = implode(',', $errors->get(array_get($item, 'key')));
            } else {
                $item['error'] = null;
            }

            $result[] = $item;
        }
        return $result;
    }


    /**
     * Whether has error in items.
     *
     * @return bool
     */
    protected function hasError() : bool
    {
        return collect($this->getItems())->contains(function ($item) {
            return !is_nullorempty(array_get($item, 'error'));
        });
    }

    /**
     * header help
     *
     * @param string $headerHelps
     * @return $this
     */
    public function headerHelp($headerHelps)
    {
        $this->headerHelps = $headerHelps;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        // get items error message
        return parent::render()->with([
            'checkWidth' => $this->checkWidth,
            'items' => $this->getItems(),
            'headerHelps' => collect($this->headerHelps)->toArray(),
            'hasError' => $this->hasError(),
        ]);
    }
}
