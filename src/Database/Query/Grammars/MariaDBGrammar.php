<?php

namespace Exceedone\Exment\Database\Query\Grammars;


/**
 * https://github.com/ybr-nx/laravel-mariadb
 */
class MariaDBGrammar extends MySqlGrammar implements GrammarInterface
{
    ///// Move to MySqlGrammar
    // protected function wrapJsonSelector($value)
    // {
    //     if (Str::contains($value, '->>')) {
    //         $delimiter = '->>';
    //         $format = 'JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.%s\'))';
    //     } else {
    //         $delimiter = '->';
    //         $format = 'JSON_EXTRACT(%s, \'$.%s\')';
    //     }
    //     $path = explode($delimiter, $value);
    //     $field = collect(explode('.', array_shift($path)))->map(function ($part) {
    //         return $this->wrapValue($part);
    //     })->implode('.');
    //     return sprintf($format, $field, collect($path)->map(function ($part) {
    //         return '"'.$part.'"';
    //     })->implode('.'));
    // }

    // //make table.field->json selects work
    // public function wrap($value, $prefixAlias = false)
    // {
    //     $mysqlWrap = parent::wrap($value, $prefixAlias);
    //     if (Str::contains($mysqlWrap, '.JSON_EXTRACT')) {
    //         if (Str::contains($value, '->>')) {
    //             $delimiter = '->>';
    //             $format = 'JSON_UNQUOTE(JSON_EXTRACT(%s, \'$.%s\'))';
    //         } else {
    //             $delimiter = '->';
    //             $format = 'JSON_EXTRACT(%s, \'$.%s\')';
    //         }
    //         $path = explode($delimiter, $value);
    //         $field = collect(explode('.', array_shift($path)))->map(function ($part) {
    //             return $this->wrapValue($part);
    //         })->implode('.');
    //         return sprintf(
    //             $format,
    //             $field,
    //             collect($path)->map(function ($part) {
    //                 return '"'.$part.'"';
    //             })->implode('.')
    //         );
    //     }
    //     return $mysqlWrap;
    // }
}
