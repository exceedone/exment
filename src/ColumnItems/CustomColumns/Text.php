<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Validator;

class Text extends CustomItem
{
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
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'string_length')) {
            $validates[] = 'max:'.array_get($options, 'string_length');
        }
        
        // value type
        $validates[] = new Validator\StringNumericRule();
        
        // set regex rule
        $info = $this->getAvailableCharactersInfo();
        foreach ($info['validates'] as $v) {
            $validates[] = $v;
        }
    }
    
    protected function getAppendHelpText($form_column_options) : ?string
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
        $help_regexes = [];
        $options = $this->custom_column->options;

        if (boolval(config('exment.expart_mode', false)) && array_key_value_exists('regex_validate', $options)) {
            $regex_validate = array_get($options, 'regex_validate');
            $validates[] = 'regex:/'.$regex_validate.'/u';
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
            }
        }

        return [
            'validates' => $validates,
            'help' => count($help_regexes) ? sprintf(exmtrans('common.help.input_available_characters'), implode(exmtrans('common.separate_word'), $help_regexes)) : null,
        ];
    }
}
