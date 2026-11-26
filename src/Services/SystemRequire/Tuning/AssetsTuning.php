<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The JS and CSS of the pages Exment draws itself (the login page, the install pages) (ISS02823):
 * - every file they load is in public (a missing one is a 404 on every visit,
 *   and the page waits for it); publish them with "php artisan exment:publish";
 * - the browser may keep them (Cache-Control or Expires on a JS file). The URLs carry the
 *   version: admin_asset() adds ?v= the time of the file, the pages of Exment add ?ver= the
 *   version of Exment, the files of a plugin ?ver= the version of the plugin. So a long time is
 *   safe. Asked once, with a short timeout; when the site cannot be reached from the server,
 *   that part is not judged.
 */
class AssetsTuning extends TuningBase
{
    /** The views whose files are checked. */
    public const VIEWS = ['auth/layout.blade.php', 'install/layout.blade.php', 'initialize/index.blade.php'];

    /** @var array<string>|null the views to read (a test sets them) */
    public $views;

    /** @var array<string, string>|null|false the headers of a JS file; false: not reachable (a test sets it) */
    public $headers;

    /** @var array<string>|null */
    protected $paths;

    protected function key(): string
    {
        return 'assets';
    }

    /**
     * The paths of public that the views load, without the commented-out ones.
     *
     * @return array<string>
     */
    public function paths(): array
    {
        if (isset($this->paths)) {
            return $this->paths;
        }
        $views = $this->views ?? array_map(function ($view) {
            return dirname(__DIR__, 4) . '/resources/views/' . $view;
        }, static::VIEWS);

        $paths = [];
        foreach ($views as $view) {
            $source = (string) @file_get_contents($view);
            $paths = array_merge($paths, static::assetPaths($source));
        }
        return $this->paths = array_values(array_unique($paths));
    }

    /**
     * The files of admin_asset("...") and asset("...") in a view, outside the HTML and Blade comments.
     *
     * @return array<string>
     */
    public static function assetPaths(string $source): array
    {
        $source = (string) preg_replace(['/<!--.*?-->/s', '/\{\{--.*?--\}\}/s'], '', $source);
        preg_match_all('/\b(?:admin_asset|asset)\s*\(\s*([\'"])([^\'"]+\.(?:js|css))\1\s*\)/', $source, $matches);
        return array_map(function ($path) {
            return '/' . ltrim($path, '/');
        }, $matches[2]);
    }

    /**
     * @return array<string>
     */
    public function missing(): array
    {
        return array_values(array_filter($this->paths(), function ($path) {
            return !is_file(public_path(ltrim($path, '/')));
        }));
    }

    /**
     * The headers of one JS file, or false when the site cannot be reached.
     *
     * @return array<string, string>|false
     */
    protected function headers()
    {
        if (isset($this->headers)) {
            return $this->headers;
        }
        $js = collect($this->paths())->first(function ($path) {
            return substr($path, -3) === '.js' && is_file(public_path(ltrim($path, '/')));
        });
        if (is_null($js)) {
            return $this->headers = false;
        }
        try {
            $response = \Illuminate\Support\Facades\Http::timeout(3)->head(admin_asset($js));
            if (!$response->successful()) {
                return $this->headers = false;
            }
            return $this->headers = array_change_key_case(array_map(function ($values) {
                return implode(', ', (array) $values);
            }, $response->headers()), CASE_LOWER);
        } catch (\Throwable $e) {
            return $this->headers = false;
        }
    }

    /**
     * Whether the browser may keep the files: true / false, or null when unknown.
     */
    public function browserCache(): ?bool
    {
        $headers = $this->headers();
        if ($headers === false) {
            return null;
        }
        $control = strtolower($headers['cache-control'] ?? '');
        if (preg_match('/max-age=(\d+)/', $control, $m)) {
            return intval($m[1]) > 0 && strpos($control, 'no-store') === false;
        }
        return isset($headers['expires']) && strpos($control, 'no-cache') === false && strpos($control, 'no-store') === false;
    }

    public function checkResult(): string
    {
        return count($this->missing()) > 0 || $this->browserCache() === false ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $missing = $this->missing();
        $text = count($missing) > 0
            ? exmtrans('tuning.item.assets.missing', ['files' => implode(', ', $missing)])
            : exmtrans('tuning.item.assets.all_found', ['count' => count($this->paths())]);
        $cache = $this->browserCache();
        $text .= ' / ' . exmtrans('tuning.item.assets.' . (is_null($cache) ? 'cache_unknown' : ($cache ? 'cache_yes' : 'cache_no')));
        return $text;
    }

    protected function getMessageWarning(): ?string
    {
        return count($this->missing()) > 0
            ? exmtrans('tuning.item.assets.warning')
            : exmtrans('tuning.item.assets.warning_cache');
    }

    public function getFixText(): ?string
    {
        if (count($this->missing()) > 0) {
            return 'php artisan exment:publish';
        }
        return implode("\n", [
            '# Apache (public/.htaccess)',
            '<IfModule mod_expires.c>',
            '    ExpiresActive On',
            '    ExpiresByType text/css "access plus 7 days"',
            '    ExpiresByType application/javascript "access plus 7 days"',
            '</IfModule>',
            '# nginx: a name that is not a file goes on to Exment (the pages of the files of',
            '# the file server, the files of the plugins, the old addresses of the pages)',
            'location ~* \.(?:css|js|woff2?|png|jpg|svg)$ { expires 7d; add_header Cache-Control "public"; try_files $uri /index.php?$query_string; }',
        ]);
    }
}
