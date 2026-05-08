<?php

namespace Exceedone\Exment\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * Facade for Exment's minimal Form builder.
 * Registered as the 'Form' alias when laravelcollective/html is not installed.
 *
 * @method static \Illuminate\Support\HtmlString hidden(string $name, mixed $value = null, array $options = [])
 * @method static \Illuminate\Support\HtmlString checkbox(string $name, mixed $value = 1, mixed $checked = null, array $options = [])
 * @method static \Illuminate\Support\HtmlString label(string $for, string|null $value = null, array $options = [], bool $escape = true)
 * @method static \Illuminate\Support\HtmlString text(string $name, mixed $value = null, array $options = [])
 * @method static \Illuminate\Support\HtmlString number(string $name, mixed $value = null, array $options = [])
 */
class ExmentFormFacade extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'form';
    }
}
