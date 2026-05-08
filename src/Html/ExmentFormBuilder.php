<?php

namespace Exceedone\Exment\Html;

use Illuminate\Support\HtmlString;

/**
 * Minimal Form builder — drop-in replacement for laravelcollective/html (abandoned, no L11 support).
 * Implements only the subset of methods used in Exment's own Blade templates.
 * Returns HtmlString so Blade's {{ }} directive renders raw HTML without double-escaping.
 */
class ExmentFormBuilder
{
    /**
     * Generate a hidden input field.
     */
    public function hidden(string $name, $value = null, array $options = []): HtmlString
    {
        $attrs = array_merge(['type' => 'hidden', 'name' => $name, 'value' => $value], $options);
        return new HtmlString('<input' . $this->attributes($attrs) . '>');
    }

    /**
     * Generate a checkbox input field.
     *
     * @param mixed $value   The value attribute of the checkbox.
     * @param mixed $checked Truthy = checked. Falsy / null = unchecked.
     */
    public function checkbox(string $name, $value = 1, $checked = null, array $options = []): HtmlString
    {
        $isChecked = !is_null($checked) && $checked !== false && $checked !== '' && $checked !== 0 && $checked !== '0';

        $attrs = array_merge(['type' => 'checkbox', 'name' => $name, 'value' => $value], $options);
        if ($isChecked) {
            $attrs['checked'] = true;
        }
        return new HtmlString('<input' . $this->attributes($attrs) . '>');
    }

    /**
     * Generate a label element.
     *
     * @param string      $for     The `for` attribute value (id of the associated input).
     * @param string|null $value   The text content of the label.
     * @param bool        $escape  Whether to HTML-escape the label text (default true).
     */
    public function label(string $for, ?string $value = null, array $options = [], bool $escape = true): HtmlString
    {
        $attrs = array_merge(['for' => $for], $options);
        $text = $escape
            ? htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            : ($value ?? '');
        return new HtmlString('<label' . $this->attributes($attrs) . '>' . $text . '</label>');
    }

    /**
     * Generate a text input field.
     */
    public function text(string $name, $value = null, array $options = []): HtmlString
    {
        $attrs = array_merge(['type' => 'text', 'name' => $name, 'value' => $value], $options);
        return new HtmlString('<input' . $this->attributes($attrs) . '>');
    }

    /**
     * Generate a number input field.
     */
    public function number(string $name, $value = null, array $options = []): HtmlString
    {
        $attrs = array_merge(['type' => 'number', 'name' => $name, 'value' => $value], $options);
        return new HtmlString('<input' . $this->attributes($attrs) . '>');
    }

    /**
     * Build an HTML attribute string from an array.
     *
     * - null / false values are omitted entirely.
     * - true values render as standalone boolean attributes (e.g. `checked`, `disabled`).
     * - All keys and values are HTML-escaped.
     */
    protected function attributes(array $attrs): string
    {
        $html = '';
        foreach ($attrs as $key => $value) {
            if ($value === null || $value === false) {
                continue;
            }
            $safeKey = htmlspecialchars((string) $key, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            if ($value === true) {
                $html .= ' ' . $safeKey;
            } else {
                $safeVal = htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $html .= ' ' . $safeKey . '="' . $safeVal . '"';
            }
        }
        return $html;
    }
}
