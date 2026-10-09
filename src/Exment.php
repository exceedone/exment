<?php

namespace Exceedone\Exment;

use Exceedone\Exment\Services\DataImportExport\Formats\PhpSpreadSheet\PhpSpreadSheet;
use Exceedone\Exment\Validator as ExmentValidator;
use Exceedone\Exment\Enums\UrlTagType;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\ExportImportLibrary;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Services\DataImportExport\Formats\FormatBase;
use Exceedone\Exment\Services\ClassLoader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Collection;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Contracts\Support\Htmlable;
use Encore\Admin\Admin;
use Encore\Admin\Form\Field\UploadField;
use Carbon\Carbon;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Finder\Finder;
use League\Flysystem\PathPrefixer;

/**
 * Class Admin.
 */
class Exment
{
    /**
     * Class loader for plugin class.Dynamic require_once
     *
     * @var ClassLoader
     */
    protected $classLoader;

    /**
     * guard name.
     *
     * @var string
     */
    protected $guard;


    public function __construct()
    {
        $this->classLoader = new ClassLoader();
        $this->classLoader->register();
    }


    /**
     * Get class loader.
     *
     * @return ClassLoader
     */
    public function classLoader()
    {
        return $this->classLoader;
    }

    /**
     * Left sider-bar menu.
     *
     * @return array
     */
    // @phpstan-ignore-next-line
    public function menu()
    {
        return (new Menu())->toTree();
    }


    /**
     * Error handling.
     * Now we created \Exceedone\Exment\Exceptions\Handler,
     * so we want to write logic on that class,
     * But we wrote manual calling this function..
     *
     * @param \Illuminate\Http\Request $request
     * @param \Exception $exception
     * @param \Illuminate\Http\Response $callback
     * @return mixed
     */
    public function error($request, $exception, $callback)
    {
        try {
            // Api is default callback
            if ($this->isApiEndpoint()) {
                // @phpstan-ignore-next-line
                return $callback($request, $exception);
            }

            /** @phpstan-ignore-next-line not implement MaintenanceModeException in laravel 10 */
            if ($exception instanceof \Illuminate\Foundation\Http\Exceptions\MaintenanceModeException) {
                $errorController = app(\Exceedone\Exment\Controllers\ErrorController::class);
                return $errorController->maintenance();
            }

            if ($exception instanceof \Illuminate\Session\TokenMismatchException) {
                admin_error(exmtrans('common.error'), exmtrans('error.expired_error_reinput'));
                if ($this->isPublicFormEndpoint()) {
                    $public_form = PublicForm::getPublicFormByRequest();
                    return $public_form ? redirect($public_form->getUrl()) : back();
                } else {
                    back();
                }
            }

            if ($this->isPublicFormEndpoint()) {
                // @phpstan-ignore-next-line
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

                // @phpstan-ignore-next-line
                return $callback($request, $exception);
            }

            // whether has User
            $user = \Exment::user();
            if (!$user) {
                // @phpstan-ignore-next-line
                return $callback($request, $exception);
            }

            $errorController = app(\Exceedone\Exment\Controllers\ErrorController::class);
            return $errorController->error($request, $exception);
        } catch (\Exception $ex) {
            // @phpstan-ignore-next-line
            return $callback($request, $exception);
        } catch (\Throwable $e) {
            // @phpstan-ignore-next-line
            return $callback($request, $exception);
        }
    }

    /**
     // Laravel facade Auth not recognized
     * get user. Use "Auth::shouldUse", so get only logined user.
     */
    // @phpstan-ignore-next-line
    public function user($guards = null)
    {
        return \Auth::guard($this->guard)->user();
    }


    /**
     * set gurad info.
     */
    // @phpstan-ignore-next-line
    public function setGuard(string $guard)
    {
        $this->guard = $guard;
        \Auth::shouldUse($guard);
    }

    /**
     * Get User Model's ID
     * This function name defines Custom value's user and login user. But this function always return Custom value's user
     * @return string|int|null
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


    // @phpstan-ignore-next-line
    public function getRender($grid): ?string
    {
        if ($grid instanceof Renderable) {
            return $grid->render();
        }

        if ($grid instanceof Htmlable) {
            return $grid->toHtml();
        }

        return $grid;
    }


    /**
     * get exment version
     */
    // @phpstan-ignore-next-line
    public function version($getFromComposer = true)
    {
        list($latest, $current) = $this->getExmentVersion($getFromComposer);
        return $current;
    }


    /**
     * getExmentVersion using session and composer
     *
     * @return array $latest: new version in package, $current: this version in server
     */
    // @phpstan-ignore-next-line
    public function getExmentVersion($getFromComposer = true)
    {
        try {
            try {
                $version_json = Cache::get(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION);
            } catch (\Exception $e) {
            }

            $latest = null;
            $current = null;
            if (isset($version_json)) {
                $version = json_decode_ex($version_json, true);
                // @phpstan-ignore-next-line
                $latest = array_get($version, 'latest');
                // @phpstan-ignore-next-line
                $current = array_get($version, 'current');
            }

            if ((empty($latest) || empty($current))) {
                // get current version from composer.lock
                $composer_lock = base_path('composer.lock');
                if (!\File::exists($composer_lock)) {
                    return [null, null];
                }

                $contents = \File::get($composer_lock);
                $json = json_decode_ex($contents, true);
                if (!$json) {
                    return [null, null];
                }

                // get exment info
                // @phpstan-ignore-next-line
                $packages = array_get($json, 'packages');
                $exment = collect($packages)->filter(function ($package) {
                    return array_get($package, 'name') == Define::COMPOSER_PACKAGE_NAME;
                })->first();
                if (!isset($exment)) {
                    return [null, null];
                }
                $current = array_get($exment, 'version');

                // if outside api is not permitted, return only current
                if (!System::outside_api() || !$getFromComposer) {
                    return [null, $current];
                }

                // if already executed
                if (Cache::has(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE)) {
                    return [null, $current];
                }

                //// get latest version
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', Define::COMPOSER_VERSION_CHECK_URL, [
                    'http_errors' => false,
                    'timeout' => 3, // Response timeout
                    'connect_timeout' => 3, // Connection timeout
                ]);

                Cache::put(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE, true, Define::CACHE_CLEAR_MINUTE);

                $contents = $response->getBody()->getContents();
                if ($response->getStatusCode() != 200) {
                    return [null, null];
                }

                $json = json_decode_ex($contents, true);
                if (!$json) {
                    return [null, null];
                }
                // @phpstan-ignore-next-line
                $packages = array_get($json, 'package.versions');
                if (!$packages) {
                    return [null, null];
                }

                // pick the latest STABLE version (semantic compare; skip dev / branch-alias / pre-release)
                $latest = $this->pickLatestStableVersion($packages);

                try {
                    Cache::put(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION, json_encode([
                        'latest' => $latest, 'current' => $current
                    ]), Define::CACHE_CLEAR_MINUTE);
                } catch (\Exception $e) {
                }
            }
        } catch (\Exception $e) {
            Cache::put(Define::SYSTEM_KEY_SESSION_SYSTEM_VERSION_EXECUTE, true, Define::CACHE_CLEAR_MINUTE);
        }

        return [$latest ?? null, $current ?? null];
    }


    /**
     * getExmentCurrentVersion
     *
     * @return string|null this version in server
     */
    public function getExmentCurrentVersion()
    {
        return $this->getExmentVersion(false)[1];
    }


    /**
     * check exment's next version
     *
     * @return int $latest: new version in package, $current: this version in server
     */
    public function checkLatestVersion()
    {
        list($latest, $current) = $this->getExmentVersion();

        return $this->classifyVersion($latest, $current);
    }

    /**
     * Classify the installed version against the latest available version.
     * Pure logic (no I/O), extracted so it can be unit tested.
     *
     * @param string|null $latest  latest available version (tag), may be null
     * @param string|null $current installed version (tag), may be null
     * @return int SystemVersion::*
     */
    public function classifyVersion($latest, $current): int
    {
        // cast before trim, and strip only the LEADING "v" prefix.
        // ($latest / $current can be null when outside_api is off / offline / api error;
        //  trim(..., 'v') would also strip a trailing "v", corrupting "*-dev" branch versions.)
        $latest = ltrim((string)($latest ?? ''), 'vV');
        $current = ltrim((string)($current ?? ''), 'vV');

        if ($latest === '' || $current === '') {
            return SystemVersion::ERROR;
        }
        // development / branch-alias installs are not compared against released versions
        if ($this->isDevVersion($current)) {
            return SystemVersion::DEV;
        }
        // LATEST when the installed version is at least as new as the latest released one
        if (version_compare($latest, $current, '<=')) {
            return SystemVersion::LATEST;
        }
        return SystemVersion::HAS_NEXT;
    }

    /**
     * Whether the given version string represents a development / branch-alias build.
     * Covers: dev-main, dev-xxx, 6.x-dev, 6.2.x-dev, 9999999-dev, *-dev
     *
     * @param string|null $version
     * @return bool
     */
    public function isDevVersion($version): bool
    {
        // strip only the leading "v" prefix (a "*-dev" version ends in "v")
        $version = ltrim((string)($version ?? ''), 'vV');

        return strpos($version, 'dev-') === 0
            || strpos($version, '9999999') === 0
            || preg_match('/-dev$/i', $version) === 1;
    }

    /**
     * Pick the latest STABLE version key from a packagist "package.versions" map.
     * Compares semantically (so "6.2.10" ranks above "6.2.9") and skips
     * dev / branch-alias / pre-release (alpha/beta/RC) entries.
     *
     * @param array<string,mixed>|null $packages
     * @return string|null
     */
    public function pickLatestStableVersion($packages)
    {
        if (empty($packages)) {
            return null;
        }

        $sorted = collect($packages)->sort(function ($a, $b) {
            return version_compare(
                (string) array_get($b, 'version_normalized'),
                (string) array_get($a, 'version_normalized')
            );
        });

        foreach ($sorted as $key => $package) {
            $key = (string) $key;
            $normalized = (string) array_get($package, 'version_normalized', $key);
            // skip dev branches and pre-releases (dev / alpha / beta / RC).
            // NB: "-patch" / "-pl" are STABLE in composer, so only known unstable flags are excluded.
            if ($normalized === ''
                || strpos($key, 'dev-') === 0
                || preg_match('/-(dev|alpha|beta|rc)/i', $normalized) === 1) {
                continue;
            }
            return $key;
        }

        return null;
    }






    // Helper logic ----------------------------------------------------

    // @phpstan-ignore-next-line
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
            'attributes' => \Exment::formatAttributes($attributes),
        ])->render();
    }

    /**
     * get_password_rule(for validation)
     *
     * @param $required
     * @param LoginUser|null $login_user
     * @param array $options
     * @return array
     */
    // @phpstan-ignore-next-line
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
            $validates[] = new ExmentValidator\ComplexPasswordRule();
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
    // @phpstan-ignore-next-line
    public function getDataFromSheet($sheet, $keyvalue = false, $isGetMerge = false)
    {
        /** @var PhpSpreadSheet $format */
        $format = FormatBase::getFormatClass('xlsx', ExportImportLibrary::PHP_SPREAD_SHEET, false);
        return $format->getDataFromSheet($sheet, $keyvalue, $isGetMerge);
    }


    /**
     * get cell value
     */
    // @phpstan-ignore-next-line
    public function getCellValue($cell, $sheet, $isGetMerge = false)
    {
        /** @var PhpSpreadSheet $format */
        $format = FormatBase::getFormatClass('xlsx', ExportImportLibrary::PHP_SPREAD_SHEET, false);
        return $format->getCellValue($cell, $sheet, $isGetMerge);
    }


    /**
     * Get mark and value for search
     *
     * @param bool $isLike
     * @param string $q search string
     * @return array
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function getSearchDocumentQuery(CustomTable $target_custom_table, ?string $q, $query = null)
    {
        if (empty($query)) {
            $query = $target_custom_table->getValueQuery();
        }
        return $query->whereExists(function ($query) use ($target_custom_table, $q) {
            $custom_table = CustomTable::getEloquent(SystemTableName::DOCUMENT);
            $column_document_name = CustomColumn::getEloquent('document_name', $custom_table);
            $documentDbName = getDBTableName($custom_table);
            $documentDbNameWrap = \Exment::wrapTable($documentDbName);
            $targetDbNameWrap = \Exment::wrapTable(getDBTableName($target_custom_table));

            // search document name
            list($mark, $q) = \Exment::getQueryMarkAndValue(true, $q);
            $query
                ->select(\DB::raw(1))
                ->from($documentDbName)
                ->where($documentDbName . '.' . $column_document_name->getQueryKey(), $mark, $q)
                ->where("$documentDbName.parent_type", $target_custom_table->table_name)
                ->whereRaw("$documentDbNameWrap.parent_id = $targetDbNameWrap.id");
            ;
        });
    }


    /**
     * Get select options html for select.
     *
     * @param string $font_awesome
     * @param string $text
     * @return string
     */
    public static function getSelectOptionHtml(?string $font_awesome, ?string $text)
    {
        // decode escapeMarkup, so call esc_html twice.
        return '<i class="text-center fa ' . esc_html(esc_html($font_awesome)) . '" aria-hidden="true" style="width:16px;"></i>&nbsp;&nbsp;<span>' . esc_html(esc_html($text)) . '</span>';
    }

    /**
     * Push collection. if $item is Collection, loop
     *
     * @param Collection $collect
     * @param $item
     * @return Collection
     */
    // @phpstan-ignore-next-line
    public function pushCollection(Collection $collect, $item): Collection
    {
        if ($item instanceof Collection) {
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
    public function getManualUrl(?string $uri = null): string
    {
        $manual_url_base = config('exment.manual_url');
        // if ja, set
        if (config('app.locale') == 'ja') {
            $manual_url_base = url_join($manual_url_base, 'ja') . '/';
        }
        $manual_url_base = url_join($manual_url_base, $uri);
        return $manual_url_base;
    }


    /**
     * Get "More" tag. contains link.
     *
     * @param string|null $uri
     * @param string|null $id_transkey
     * @return string
     */
    public function getMoreTag(?string $uri = null, ?string $id_transkey = null): string
    {
        $url = $this->getManualUrl($uri);

        if ($id_transkey) {
            $url .= '?id=' . exmtrans($id_transkey);
        }

        return exmtrans('common.help.more_help_here', $url);
    }


    /**
     * get true mark. If $val is true, output mark
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function getYesNoAllOption(): array
    {
        return [
            '' => 'All',
            '0' => 'NO',
            '1' => 'YES',
        ];
    }

    // @phpstan-ignore-next-line
    public function wrapValue($string)
    {
        return app('db')->getPdo()->quote($string);
    }

    // @phpstan-ignore-next-line
    public function wrapColumn($string)
    {
        return \DB::getQueryGrammar()->wrap($string);
    }

    // @phpstan-ignore-next-line
    public function wrapTable($string)
    {
        return \DB::getQueryGrammar()->wrapTable($string);
    }

    /**
     * Format the field attributes.
     *
     * @return string
     */
    // @phpstan-ignore-next-line
    public function formatAttributes($attributes)
    {
        $html = [];

        foreach ($attributes as $name => $value) {
            $html[] = $name.'="'.esc_html($value).'"';
        }

        return implode(' ', $html);
    }


    /**
     * this url is ApiEndpoint
     */
    // @phpstan-ignore-next-line
    public function isApiEndpoint()
    {
        $basePath = ltrim(admin_base_path(), '/');
        $route = config('exment.publicformapi_route_prefix', 'publicformapi');
        return request()->is($basePath . '/api/*') || request()->is($basePath . '/webapi/*') || request()->is("{$route}/*");
    }

    /**
     * this url is Public form endpoint
     */
    // @phpstan-ignore-next-line
    public function isPublicFormEndpoint()
    {
        $route = public_form_base_path();
        return request()->is("{$route}/*");
    }


    /**
     * get tmp folder path. Uses for
     * @param string $type "plugin", "template", "backup", "data".
     */
    // @phpstan-ignore-next-line
    public function getTmpFolderPath($type, $fullpath = true)
    {
        $path = path_join('tmp', $type, short_uuid());
        if (!$fullpath) {
            return $path;
        }
        $tmppath = getFullpath($path, Define::DISKNAME_ADMIN_TMP);
        \Exment::makeDirectory($tmppath);

        return $tmppath;
    }


    /**
     * Replace \ to /
     *
     * @param string $path
     * @return string
     */
    public function replaceBackToSlash($path)
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Replace \ or /, to system os slash
     * Windows : "\"
     * Others : "/"
     *
     * @param string $path
     * @return string
     */
    public function replaceOsSeparator($path): string
    {
        if ($this->isWindows()) {
            return str_replace('/', '\\', $path);
        } else {
            return str_replace('\\', '/', $path);
        }
    }


    /**
     * Set time limit long
     */
    // @phpstan-ignore-next-line
    public function setTimeLimitLong($time = 6000)
    {
        $max_execution_time = ini_get('max_execution_time');
        if ($max_execution_time == 0 || $max_execution_time > $time) {
            return;
        }
        set_time_limit($time);
    }


    /**
     * get Upload Max File Size. get php.ini config
     *
     * @return int byte size.
     */
    public function getUploadMaxFileSize()
    {
        // get mega size
        $post_max_size = $this->getFileMegaSizeValue(ini_get('post_max_size'));
        $upload_max_filesize = $this->getFileMegaSizeValue(ini_get('upload_max_filesize'));

        // return min size post_max_size or upload_max_filesize
        $minsize = collect([$post_max_size, $upload_max_filesize])->min();

        // return byte size
        return $minsize * 1024 * 1024;
    }

    /**
     * Get file size
     *
     * @param $val
     * @return int
     */
    // @phpstan-ignore-next-line
    public function getFileMegaSizeValue($val)
    {
        $val = strtolower(strval($val));
        $val = str_replace('m', '', $val);

        if (strpos($val, 'g') !== false) {
            // @phpstan-ignore-next-line
            $val = str_replace('g', '', $val) * 1024;
        }
        return intval($val);
    }

    /**
     * make directory(0775)
     *
     * @param string|null $path
     * @return void
     */
    public function makeDirectory(?string $path, int $mode = 0775)
    {
        if (\File::exists($path)) {
            return;
        }
        \File::makeDirectory($path, $mode, true);
    }

    /**
     * make directory for disk(0775)
     *
     * @param string|null $path
     * @return void
     */
    // @phpstan-ignore-next-line
    public function makeDirectoryDisk($disk, ?string $path, int $mode = 0775)
    {
        if (!$disk) {
            return;
        }
        if ($disk->exists($path)) {
            return;
        }
        $disk->makeDirectory($path, $mode, true);
    }


    /**
     * Get all of the directories within a given directory.
     *
     * @param  string  $directory
     * @return array
     */
    // @phpstan-ignore-next-line
    public function allDirectories($directory)
    {
        $directories = [];

        foreach (Finder::create()->in($directory)->directories()->sortByName() as $dir) {
            $directories[] = $dir->getPathname();
        }

        return $directories;
    }

    /**
     * Whether db is sqlserver.
     *
     * @return boolean
     */
    public function isSqlServer(): bool
    {
        return \DB::getSchemaBuilder() instanceof \Illuminate\Database\Schema\SqlServerBuilder;
    }


    /**
     * Whether server os is Windows
     *
     * @return boolean
     */
    public function isWindows(): bool
    {
        return 0 === strpos(PHP_OS, 'WIN');
    }


    /**
     * Get composer path. If env EXMENT_COMPOSER_PATH set, return this env value.
     *
     * @return string
     */
    public function getComposerPath(): string
    {
        $path = config('exment.composer_path');
        if (!\is_nullorempty($path)) {
            return $path;
        }

        return 'composer';
    }


    /**
     * Convert to array for Carbon
     *
     * @param \Carbon\Carbon $carbon
     * @return array
     */
    // @phpstan-ignore-next-line
    public function carbonToArray(\Carbon\Carbon $carbon): array
    {
        return [
            'date' => $carbon->format("Y-m-d H:i:s.u"),
            'timezone_type' => 3,  // Directly set timezone type, because cannot get.
            'timezone' => $carbon->getTimezone()->getName(),
        ];
    }

    /**
     * Convert to only day
     *
     * @param Carbon|string|null $value
     * @return Carbon|null
     */
    public function getCarbonOnlyDay($value): ?Carbon
    {
        if (is_nullorempty($value)) {
            return null;
        }
        $carbon = Carbon::parse($value);
        // @phpstan-ignore-next-line
        return Carbon::create($carbon->year, $carbon->month, $carbon->day);
    }

    /**
     * Contains 2 array.
     * *This function only check testArr item contains targetArr. Whether targetArr's item not contains testArr, maybe return true.*
     *
     * @param array|Collection $testArr
     * @param array|Collection $targetArr
     * @return boolean
     */
    // @phpstan-ignore-next-line
    public function isContains2Array($testArr, $targetArr): bool
    {
        foreach ($testArr as $arrKey => $arrValue) {
            if (!collect($targetArr)->contains(function ($v, $k) use ($arrKey, $arrValue) {
                return isMatchString($arrKey, $k) && isMatchString($arrValue, $v);
            })) {
                return false;
            };
        }
        return true;
    }


    /**
     * Whether Available Google recaptcha (whether has class)
     *
     * @return boolean
     */
    public function isAvailableGoogleRecaptcha()
    {
        return class_exists(\Arcanedev\NoCaptcha\NoCaptchaManager::class);
    }


    /**
     * save file info to database
     *
     * @param mixed $field
     * @param string|UploadedFile $file
     * @param string $file_type
     * @param CustomTable $custom_table
     * @param boolean $replace
     * @return string
     */
    public function setFileInfo($field, $file, $file_type, $custom_table, bool $replace = true)
    {
        $dirname = $field->getDirectory();

        if ($file instanceof UploadedFile) {
            $filename = $file->getClientOriginalName();
        } else {
            $filename = $file;
        }

        // save file info
        $exmentfile = ExmentFile::saveFileInfo($file_type, $dirname, [
            'filename' => $filename,
        ]);

        $this->setFileRequestSession($exmentfile, $field->column(), $custom_table, $replace);

        // return filename
        return $exmentfile->local_filename;
    }

    /**
     * save file request session. for after saved custom value, set custom value's id.
     *
     * @param ExmentFile $exmentfile
     * @param string $column_name
     * @param CustomTable $custom_table
     * @param bool $replace
     * @return mixed
     */
    public function setFileRequestSession(ExmentFile $exmentfile, string $column_name, CustomTable $custom_table, bool $replace = true)
    {
        // set request session to save this custom_value's id and type into files table.
        $file_uuids = System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID) ?? [];
        $file_uuids[] = [
            'uuid' => $exmentfile->uuid,
            'column_name' => $column_name,
            'custom_table' => $custom_table,
            'path' => $exmentfile->path,
            'replace' => $replace,
        ];
        System::requestSession(Define::SYSTEM_KEY_SESSION_FILE_UPLOADED_UUID, $file_uuids);

        // return filename
        return $exmentfile->local_filename;
    }


    /**
     * Get select option key (as query string)
     *
     * @param string $column_key
     * @param boolean $append_table if true, append table info to qeury
     * @param string $table_id target table id
     * @param array $options
     * @return string
     */
    // @phpstan-ignore-next-line
    public function getOptionKey($column_key, $append_table = true, $table_id = null, $options = [])
    {
        $options = array_merge(
            [
                'view_pivot_column' => null,
                'view_pivot_table' => null,
                'codition_type' => null,
            ],
            $options
        );

        $view_pivot_column = $options['view_pivot_column'];
        $view_pivot_table = $options['view_pivot_table'];
        $codition_type = $options['codition_type'];

        $query = [];

        if ($append_table && isset($table_id)) {
            $query['table_id'] = $table_id;
        }

        // set as select_table key
        if (isset($view_pivot_column)) {
            if ($view_pivot_column == SystemColumn::PARENT_ID) {
                $query['view_pivot_column_id'] = SystemColumn::PARENT_ID;
            } else {
                $query['view_pivot_column_id'] = CustomColumn::getEloquent($view_pivot_column)->id ?? null;
            }

            $query['view_pivot_table_id'] = CustomTable::getEloquent($view_pivot_table)->id ?? null;
        }

        if (isset($codition_type)) {
            if ($view_pivot_column == SystemColumn::PARENT_ID) {
                $query['view_pivot_column_id'] = SystemColumn::PARENT_ID;
            } else {
                $query['view_pivot_column_id'] = CustomColumn::getEloquent($view_pivot_column)->id ?? null;
            }

            $query['view_pivot_table_id'] = CustomTable::getEloquent($view_pivot_table)->id ?? null;
        }

        if (count($query) == 0) {
            return $column_key;
        }

        return $column_key . '?' . implode('&', collect($query)->map(function ($val, $key) {
            return $key . '=' . $val;
        })->toArray());
    }


    /**
     * Unique and merge custom value. Check same id and table.
     *
     * @return Collection
     */
    // @phpstan-ignore-next-line
    public static function uniqueCustomValues(...$collections): Collection
    {
        $result = collect();
        if (is_nullorempty($collections)) {
            return $result;
        }

        // Add collection if unique.
        $fulterFunc = function ($col) use (&$result) {
            foreach ($col as $custom_value) {
                // Check contains same table and id
                if ($result->contains(function ($r) use ($custom_value) {
                    return isMatchString($custom_value->custom_table_name, $r->custom_table_name)
                        && isMatchString($custom_value->id, $r->id);
                })) {
                    continue;
                }

                // Add target value
                $result->push($custom_value);
            }
        };

        foreach ($collections as $collection) {
            if (!is_nullorempty($collection)) {
                $fulterFunc($collection);
            }
        }

        return $result;
    }


    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    // @phpstan-ignore-next-line
    public function getPathPrefix($adapeer, string $path): string
    {
        return $this->getPrefixer($adapeer)->prefixPath($path);
    }

    /**
     * Get PathPrefixer.
     * From filesystem 3.0, $prefixer is private, so we have to get this property forcibly.
     *
     * @return PathPrefixer
     */
    // @phpstan-ignore-next-line
    protected function getPrefixer($adapter): PathPrefixer
    {
        $reflectionClass = new \ReflectionClass($adapter);
        try {
            $property = $reflectionClass->getProperty('prefixer');
        } catch (\ReflectionException $ex) {
            // @phpstan-ignore-next-line
            $property = $reflectionClass->getParentClass()->getProperty('prefixer');
        }
        $property->setAccessible(true);
        return $property->getValue($adapter);
    }
}
