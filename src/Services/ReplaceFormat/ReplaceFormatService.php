<?php

namespace Exceedone\Exment\Services\ReplaceFormat;

use Exceedone\Exment\Model\Define;

/**
 * replace format
 */
class ReplaceFormatService
{
    /**
     * Replace value from format. ex. ${value:user_name} to user_name's value
     */
    public static function replaceTextFromFormat($format, $custom_value = null, $options = [])
    {
        if (is_null($format)) {
            return null;
        }

        $options = array_merge(
            [
                'matchBeforeCallback' => null,
                'matchBeforeCallbackForce' => null,
                'afterCallBack' => null,
                'escapeValue' => false, // escape html value
                'getReplaceValue' => false, // get replace value
            ],
            $options
        );

        try {
            // check string
            preg_match_all('/'.Define::RULES_REGEX_VALUE_FORMAT.'/', $format, $matches);
            // @phpstan-ignore-next-line
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $str = null;
                    $matchString = null;
                    $matchOptions = [];

                    try {
                        $match = $matches[1][$i];
                        $matchString = $matches[0][$i];

                        //split slach
                        $length_array = explode("/", $match);
                        $matchOptions = [];
                        if (count($length_array) > 1) {
                            $targetFormat = $length_array[0];
                            // $item is splited slach, key=value string
                            $optionString = implode('/', array_slice($length_array, 1));
                            foreach (explode(',', $optionString) as $item) {
                                $kv = explode('=', $item);
                                if (count($kv) <= 1) {
                                    continue;
                                }
                                $matchOptions[$kv[0]] = str_replace_ex('"', "", $kv[1]);
                            }
                        } else {
                            $targetFormat = $length_array[0];
                        }

                        //$targetFormat = strtolower($targetFormat);
                        // get length
                        $length_array = explode(":", $targetFormat);

                        $callbacked = false;
                        if (array_key_value_exists('matchBeforeCallback', $options)) {
                            // execute callback
                            $callbackFunc = $options['matchBeforeCallback'];
                            $result = $callbackFunc($length_array, $match, $format, $custom_value, $options);
                            if ($result) {
                                $str = $result;
                                $callbacked = true;
                            }
                        }
                        if (array_key_value_exists('matchBeforeCallbackForce', $options)) {
                            // execute callback
                            $callbackFunc = $options['matchBeforeCallbackForce'];
                            $result = $callbackFunc($length_array, $custom_value, $options, $matchOptions);
                            // if get value, return this function.
                            if (!is_null($result)) {
                                return $result;
                            }
                        }

                        if (!$callbacked) {
                            $item = Items\ItemBase::getItem($custom_value, $length_array, $matchOptions);
                            if (isset($item)) {
                                $str = $item->replace($format, $options);
                            }
                        }
                    } catch (\Exception $e) {
                        $str = '';
                    }

                    if (array_key_value_exists('link', $matchOptions) && isset($item)) {
                        $str = $item->getLink($str);
                    }

                    if ($options['getReplaceValue']) {
                        return $str;
                    }

                    // replace
                    $format = str_replace_ex($matchString, $str, $format);
                }
            }
        } catch (\Exception $e) {
        }

        if ($options['escapeValue'] ?? false) {
            $format = esc_html($format);
        }

        if (array_key_value_exists('afterCallback', $options)) {
            // execute callback
            $callbackFunc = $options['afterCallback'];
            $format = $callbackFunc($format, $custom_value, $options);
        }
        return $format;
    }
}
