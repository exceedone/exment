<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Enums\FilterOption;

class Text extends CustomItem
{
    use TextTrait;

    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }
        return strval($this->value);
    }

    protected function getAdminFieldClass()
    {
        return Field\Text::class;
    }

    protected function setAdminOptions(&$field)
    {
        // value size
        $field->attribute(['maxlength' => $this->getMaxLength()]);

        // regex
        $regex = $this->getAvailableCharactersInfo();
        if (isset($regex['regex']) && !is_nullorempty($regex['regex'])) {
            $field->attribute(['pattern' => $regex['regex']]);
        }
    }

    protected function setValidates(&$validates)
    {
        // value size
        $validates[] = 'max:'.$this->getMaxLength();

        // value type
        $validates[] = new Validator\StringNumericRule();

        // set regex rule
        $info = $this->getAvailableCharactersInfo();
        foreach ($info['validates'] as $v) {
            $validates[] = $v;
        }
    }

    protected function getAppendHelpText(): ?string
    {
        if ($this->initonly() && isset($this->value)) {
            return null;
        }

        $info = $this->getAvailableCharactersInfo();
        return array_get($info, 'help');
    }


    protected function getAvailableCharactersInfo()
    {
        // // regex rules
        $validates = [];
        $regex = null;
        $help_regexes = [];
        $options = $this->custom_column->options;

        if (boolval(config('exment.expart_mode', false)) && array_key_value_exists('regex_validate', $options)) {
            $regex_validate = array_get($options, 'regex_validate');
            $validates[] = 'regex:/'.$regex_validate.'/u';
            $regex = $regex_validate;
        } elseif (array_key_value_exists('available_characters', $options)) {
            $difinitions = CustomColumn::getAvailableCharacters();

            $available_characters = stringToArray(array_get($options, 'available_characters') ?? []);
            $regexes = [];
            // add regexes using loop
            foreach ($available_characters as $available_character) {
                // get available_character define
                $define = collect($difinitions)->first(function ($d) use ($available_character) {
                    return array_get($d, 'key') == $available_character;
                });
                if (!isset($define)) {
                    continue;
                }

                $regexes[] = array_get($define, 'regex');
                $help_regexes[] = array_get($define, 'label');
            }
            if (count($regexes) > 0) {
                $validates[] = 'regex:/^['.implode("", $regexes).']*$/u';
                $regex = "^[" . implode("", $regexes) . "]*$";
            }
        }

        return [
            'regex' => $regex,
            'validates' => $validates,
            'help' => count($help_regexes) ? sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes)) : null,
        ];
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
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        // text
        // string length
        $form->number('string_length', exmtrans("custom_column.options.string_length"))
            ->default(256)
            ->max(config('exment.char_length_limit', 63999));

        $form->checkbox('available_characters', exmtrans("custom_column.options.available_characters"))
            ->options(CustomColumn::getAvailableCharacters()->pluck('label', 'key'))
            ->help(exmtrans("custom_column.help.available_characters"))
        ;

        $form->switchbool('suggest_input', exmtrans("custom_column.options.suggest_input"))
            ->help(exmtrans("custom_column.help.suggest_input"));

        if (boolval(config('exment.expart_mode', false))) {
            $manual_url = getManualUrl('column?id='.exmtrans('custom_column.options.regex_validate'));
            $form->text('regex_validate', exmtrans("custom_column.options.regex_validate"))
                ->rules('regularExpression')
                ->help(sprintf(exmtrans("custom_column.help.regex_validate"), $manual_url));
        }
    }
}
