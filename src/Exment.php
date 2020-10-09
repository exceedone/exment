<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Illuminate\Support\Facades\Auth;
use Encore\Admin\Admin;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    public function menu()
    {
        return (new Menu())->toTree();
    }

    public static function error($request, $exception, $callback)
    {
        if (isApiEndpoint()) {
            return $callback($request, $exception);
        }
        if (!$request->pjax() && $request->ajax()) {
            // if memory error, throw ajax response
            if (strpos($exception->getMessage(), 'Allowed memory size of') === 0) {
                $manualUrl = getManualUrl('quickstart_more');
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['import_error_message' => ['type' => 'input', 'message' => exmtrans('error.memory_leak', ['url' => $manualUrl]) ]],
                ]);
            }

            return $callback($request, $exception);
        }
        
        try {
            // whether has User
            $user = \Exment::user();
            if (!isset($user)) {
                return $callback($request, $exception);
            }

            $errorController = app(\Exceedone\Exment\Controllers\ErrorController::class);
            return $errorController->error($request, $exception);
        } catch (\Exception $ex) {
            return $callback($request, $exception);
        } catch (\Throwable $e) {
            return $callback($request, $exception);
        }
    }

    /**
     * get user. multi supported admin and adminapi
     */
    public function user($guards = null)
    {
        if (is_null($guards)) {
            $guards = ['adminapi', 'admin'];
        }
        if (is_string($guards)) {
            $guards = [$guards];
        }
        
        foreach ($guards as $guard) {
            # code...
            $user = Auth::guard($guard)->user();
            if (isset($user)) {
                return $user;
            }
        }
        return null;
    }


    /**
     * Get User Model's ID
     * "This function name defines Custom value's user and login user. But this function always return Custom value's user
     *
     * @return string|int
     */
    public function getUserId()
    {
        // whether has User
        $user = \Exment::user();
        if (empty($user)) {
            return null;
        }

        return $user->getUserId();
    }


    /**
     * get exment version
     */
    public function version($getFromComposer = true)
    {
        list($latest, $current) = getExmentVersion($getFromComposer);
        return $current;
    }







    // Helper logic ----------------------------------------------------

    public function getUrlTag(?string $url, ?string $label, $urlTagType, array $attributes = [], array $options = [])
    {
        $options = array_merge(
            [
                'tooltipTitle' => null,
                'notEscape' => false,
            ],
            $options
        );

        if (!boolval($options['notEscape'])) {
            $label = esc_html($label);
        }

        // if disable url tag in request, return only url. (for use modal search)
        if (boolval(System::requestSession(Define::SYSTEM_KEY_SESSION_DISABLE_DATA_URL_TAG))) {
            return view('exment::widgets.url-nottag', [
                'label' => $label,
            ])->render();
        }

        $href = $url;
        if ($urlTagType == UrlTagType::MODAL) {
            $url .= '?modal=1';
            $href = 'javascript:void(0);';
            $options['tooltipTitle'] = exmtrans('custom_value.data_detail');

            $attributes['data-widgetmodal_url'] = $url;
        } elseif ($urlTagType == UrlTagType::BLANK) {
            $attributes['target'] = '_blank';
        } elseif ($urlTagType == UrlTagType::TOP) {
            $attributes['target'] = '_top';
        }

        if (isset($options['tooltipTitle'])) {
            $attributes['data-toggle'] = 'tooltip';
            $attributes['title'] = esc_html($options['tooltipTitle']);
        }

        return view('exment::widgets.url-tag', [
            'href' => $href,
            'label' => $label,
            'attributes' => formatAttributes($attributes),
        ])->render();
    }

    
    /**
     * get_password_rule(for validation)
     * @return string
     */
    public function get_password_rule($required = true, ?LoginUser $login_user = null, array $options = [])
    {
        $options = array_merge([
            'confirmed' => true,
        ], $options);

        $validates = [];
        if ($required) {
            $validates[] = 'required';
        } else {
            $validates[] = 'nullable';
        }

        if (boolval($options['confirmed'])) {
            $validates[] = 'confirmed';
        }

        $validates[] = 'max:'.(!is_null(config('exment.password_rule.max')) ? config('exment.password_rule.max') : '32');
        
        // check password policy
        $complex = false;
        $validates[] = new ExmentValidator\PasswordHistoryRule($login_user);

        if (!is_null($is_complex = System::complex_password()) && boolval($is_complex)) {
            $validates[] = new ExmentValidator\ComplexPasswordRule;
            $complex = true;
        }

        if (!$complex) {
            $validates[] = 'min:'.(!is_null(config('exment.password_rule.min')) ? config('exment.password_rule.min') : '8');
        }

        // set regex
        if (!$complex && !is_null(config('exment.password_rule.rule'))) {
            $validates[] = 'regex:/'.config('exment.password_rule.rule').'/';
        }
        
        return $validates;
    }

    /**
     * get_password_help
     * @return string
     */
    public function get_password_help()
    {
        $is_complex = System::complex_password() ?? false;
        if (boolval($is_complex)) {
            return exmtrans('validation.complex_password');
        }
        return exmtrans('user.help.password');
    }

    /**
     * get Data from excel sheet
     */
    public function getDataFromSheet($sheet, $skip_excel_row_no = 0, $keyvalue = false, $isGetMerge = false)
    {
        $data = [];
        foreach ($sheet->getRowIterator() as $row_no => $row) {
            // if index < $skip_excel_row_no, conitnue
            if ($row_no <= $skip_excel_row_no) {
                continue;
            }

            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false); // This loops through all cells,
            $cells = [];
            foreach ($cellIterator as $column_no => $cell) {
                $value = getCellValue($cell, $sheet, $isGetMerge);

                // if keyvalue, set array as key value
                if ($keyvalue) {
                    $key = getCellValue($column_no."1", $sheet, $isGetMerge);
                    $cells[$key] = mbTrim($value);
                }
                // if false, set as array
                else {
                    $cells[] = mbTrim($value);
                }
            }
            if (collect($cells)->filter(function ($v) {
                return !is_nullorempty($v);
            })->count() == 0) {
                break;
            }
            $data[] = $cells;
        }

        return $data;
    }

    
    /**
     * Get mark and value for search
     *
     * @param bool $isLike
     * @param string $q search string
     * @return array
     */
    public function getQueryMarkAndValue($isLike, $q)
    {
        // if all search
        $mark = ($isLike ? 'LIKE' : '=');
        if (System::filter_search_type() == FilterSearchType::ALL) {
            $value = ($isLike ? '%' : '') . $q . ($isLike ? '%' : '');
        } else {
            $value = $q . ($isLike ? '%' : '');
        }

        return [$mark, $value];
    }

    
    /**
     * search document
     */
    public function getSearchDocumentQuery(CustomTable $target_custom_table, ?string $q, $query = null)
    {
        if (empty($query)) {
            $query = $target_custom_table->getValueModel()->query();
        }
        return $query->whereExists(function ($query) use ($target_custom_table, $q) {
            $custom_table = CustomTable::getEloquent(SystemTableName::DOCUMENT);
            $column_document_name = CustomColumn::getEloquent('document_name', $custom_table);
            $documentDbName = getDBTableName($custom_table);
            $targetDbName = getDBTableName($target_custom_table);

            // search document name
            list($mark, $q) = \Exment::getQueryMarkAndValue(true, $q);
            $query
                ->select(\DB::raw(1))
                ->from($documentDbName)
                ->where($documentDbName . '.' . $column_document_name->getQueryKey(), $mark, $q)
                ->where("$documentDbName.parent_type", $target_custom_table->table_name)
                ->whereRaw("$documentDbName.parent_id = $targetDbName.id");
            ;
        });
    }

    /**
     * Push collection. if $item is \Illuminate\Support\Collection, loop
     *
     * @param [type] $item
     * @return void
     */
    public function pushCollection(\Illuminate\Support\Collection $collect, $item) : \Illuminate\Support\Collection
    {
        if ($item instanceof \Illuminate\Support\Collection) {
            foreach ($item as $i) {
                $collect->push($i);
            }
        } else {
            $collect->push($item);
        }

        return $collect;
    }


    
    /**
     * Get manual url
     *
     * @param string|null $uri
     * @return string
     */
    public function getManualUrl(?string $uri = null) : string
    {
        $manual_url_base = config('exment.manual_url');
        // if ja, set
        if (config('app.locale') == 'ja') {
            $manual_url_base = url_join($manual_url_base, 'ja') . '/';
        }
        $manual_url_base = url_join($manual_url_base, $uri);
        return $manual_url_base;
    }


    public function getMoreTag(?string $uri = null, ?string $id_transkey = null)
    {
        $url = $this->getManualUrl($uri);

        if ($id_transkey) {
            $url .= '#' . exmtrans($id_transkey);
        }

        return exmtrans('common.help.more_help_here', $url);
    }


    /**
     * get true mark. If $val is true, output mark
     */
    public function getTrueMark($val)
    {
        if (!boolval($val)) {
            return null;
        }

        return config('exment.true_mark', '<i class="fa fa-check"></i>');
    }
    

    /**
     * Get Yes No All array for option.
     *
     * @return array
     */
    public function getYesNoAllOption() : array
    {
        return [
            '' => 'All',
            '0' => 'NO',
            '1' => 'YES',
        ];
    }

    /**
     * Output log database
     *
     * @return void
     */
    public static function logDatabase()
    {
        \DB::listen(function ($query) {
            $sql = $query->sql;
            foreach($query->bindings as $binding){
                if ($binding instanceof \DateTime) {
                    $binding = $binding->format('Y-m-d H:i:s');
                } elseif ($binding instanceof EnumBase) {
                    $binding = $binding->toString();
                }
                $sql = preg_replace("/\?/", "'{$binding}'", $sql, 1);
            }

            $log_string = "TIME:{$query->time}ms;    SQL: $sql";
            if (boolval(config('exment.debugmode_sqlfunction', false))) {
                $function = static::getFunctionName();
                $log_string .= ";    function: $function";
            } elseif (boolval(config('exment.debugmode_sqlfunction1', false))) {
                $function = static::getFunctionName(true);
                $log_string .= ";    function: $function";
            }

            exmDebugLog($log_string);
        });
    }

    protected static function getFunctionName($oneFunction = false)
    {
        $bt = debug_backtrace();
        $functions = [];
        $i = 0;
        foreach ($bt as $b) {
            if ($i > 1 && strpos(array_get($b, 'class'), 'Exceedone') !== false) {
                $functions[] = $b['class'] . '->' . $b['function'] . '.' . array_get($b, 'line');
            }

            if ($oneFunction && count($functions) >= 1) {
                break;
            }

            $i++;
        }
        return implode(" < ", $functions);
    }
}
