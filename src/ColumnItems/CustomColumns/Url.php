<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Enums\UrlTagType;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Form\Field;

class Url extends CustomItem
{
    /**
     * get html(for display)
     * *this function calls from non-escaping value method. So please escape if not necessary unescape.
     */
    // @phpstan-ignore-next-line
    protected function _html($v)
    {
        $value = $this->_value($v);
        $url = $this->_value($v);

        // reject dangerous URL schemes (defense-in-depth; the 'url' validation rule already blocks these on write)
        if (is_string($url) && preg_match('/^\s*(javascript|data|vbscript):/i', $url)) {
            $url = '#';
        }

        $value = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($value) : $value;

        // Only render a clickable link for safe schemes. A user-entered "javascript:",
        // "data:" or other dangerous scheme would otherwise execute on click (stored XSS),
        // because the href passes through Blade's {{ }} unchanged. For an unsafe value,
        // fall back to escaped plain text (no anchor).
        if (!$this->isValidUrlScheme($url)) {
            return esc_html($value);
        }

        return \Exceedone\Exment\Facades\ExmentFacade::getUrlTag($url, $value, UrlTagType::BLANK);
    }

    /**
     * Whether the given URL uses a scheme that is safe to put in an href.
     * Relative URLs (no scheme) and empty values are considered safe.
     *
     * @param string|null $url
     * @return bool
     */
    protected function isValidUrlScheme(?string $url): bool
    {
        if (is_nullorempty($url)) {
            return true;
        }

        // Browsers strip control chars / whitespace inside a scheme before parsing it
        // (so "java\tscript:alert(1)" still executes). Drop those chars for the scheme
        // check only - the original value is still what gets rendered.
        $normalized = preg_replace('/[\x00-\x20]+/', '', $url);

        // No scheme present (relative URL such as "/page" or "#anchor") -> safe.
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $normalized, $matches)) {
            return true;
        }

        return in_array(strtolower($matches[1]), ['http', 'https'], true);
    }

    // @phpstan-ignore-next-line
    protected function getAdminFieldClass()
    {
        return Field\Url::class;
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::LIKE;
    }

    /**
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        $form->url('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"));
    }
}
