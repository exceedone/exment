<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Checkbox;

class CheckboxTable extends Checkbox
{
    protected $view = 'exment::form.field.checkboxtable';

    // @phpstan-ignore-next-line
    protected $checkWidth = 100;
    // @phpstan-ignore-next-line
    protected $scrollx = false;
    // @phpstan-ignore-next-line
    protected $items = [];
    // @phpstan-ignore-next-line
    protected $headerHelps = [];
    // @phpstan-ignore-next-line
    protected $headerEsacape = true;

    // @phpstan-ignore-next-line
    public function checkWidth($checkWidth)
    {
        $this->checkWidth = $checkWidth;

        return $this;
    }

    // @phpstan-ignore-next-line
    public function scrollx($scrollx)
    {
        $this->scrollx = $scrollx;

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
    // @phpstan-ignore-next-line
    public function items($items)
    {
        $this->items = $items;

        return $this;
    }


    // @phpstan-ignore-next-line
    public function headerEsacape(bool $escape)
    {
        $this->headerEsacape = $escape;
        return $this;
    }

    /**
     * Get items. Append error.
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    protected function getItems()
    {
        $result = [];
        $errors = request()->session()->get('errors') ?: new \Illuminate\Support\ViewErrorBag();

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
    protected function hasError(): bool
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
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'checkWidth' => $this->checkWidth,
            'scrollx' => $this->scrollx,
            'items' => $this->getItems(),
            'headerHelps' => collect($this->headerHelps)->toArray(),
            'hasError' => $this->hasError(),
            'headerEsacape' => $this->headerEsacape,
        ]);
    }
}
