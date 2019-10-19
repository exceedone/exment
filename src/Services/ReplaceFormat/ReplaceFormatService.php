<?php
namespace Exceedone\Exment\Services\ReplaceFormat;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemColumn;
use Webpatser\Uuid\Uuid;
use Carbon\Carbon;

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
                'afterCallBack' => null,
            ],
            $options
        );

        try {
            // check string
            preg_match_all('/'.Define::RULES_REGEX_VALUE_FORMAT.'/', $format, $matches);
            if (isset($matches)) {
                // loop for matches. because we want to get inner {}, loop $matches[1].
                for ($i = 0; $i < count($matches[1]); $i++) {
                    $str = null;
                    $matchString = null;
                    try {
                        $match = $matches[1][$i];
                        $matchString = $matches[0][$i];
                        
                        //split semi-coron
                        $length_array = explode("/", $match);
                        $matchOptions = [];
                        if (count($length_array) > 1) {
                            $targetFormat = $length_array[0];
                            // $item is splited comma, key=value string
                            foreach (explode(',', $length_array[1]) as $item) {
                                $kv = explode('=', $item);
                                if (count($kv) <= 1) {
                                    continue;
                                }
                                $matchOptions[$kv[0]] = $kv[1];
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

                        if (!$callbacked) {
                            $item = Items\ItemBase::getItem($custom_value, $length_array, $matchOptions);
                            if (isset($item)) {
                                $str = $item->replace($format, $options);
                            }
                        }
                    } catch (\Exception $e) {
                        $str = '';
                    }

                    if (array_key_value_exists('link', $matchOptions)) {
                        $str = "<a href='$str'>$str</a>";
                    }

                    // replace
                    $format = str_replace($matchString, $str, $format);
                }
            }
        } catch (\Exception $e) {
        }

        if (array_key_value_exists('afterCallback', $options)) {
            // execute callback
            $callbackFunc = $options['afterCallback'];
            $format = $callbackFunc($format, $custom_value, $options);
        }
        return $format;
    }
}
