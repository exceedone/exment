<?php

namespace Exceedone\Exment\Tests\Unit\Dashboard\Support;

/**
 * The two laravel-admin Form contracts ChartItem::saving() touches: `$form->options`
 * get/set (magic) and `$form->model()`.
 */
class FakeBoxForm
{
    /** @var array<string, mixed> */
    public $inputs = [];
    protected $modelInstance;

    public function __construct($model, array $inputs)
    {
        $this->modelInstance = $model;
        $this->inputs = $inputs;
    }

    public function model()
    {
        return $this->modelInstance;
    }

    public function __get($name)
    {
        return $this->inputs[$name] ?? null;
    }

    public function __set($name, $value)
    {
        $this->inputs[$name] = $value;
    }
}
