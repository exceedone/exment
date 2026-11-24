<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * Studio, phase 6: the phone app, as a web app (PWA) in Exment itself (ISS00116).
 *
 * One page that draws the screens of the Studio from their JSON
 * (studio/screens, "exment.screen" version 1) and reads and writes their data
 * through the data API of the web session (webapi/data). It can be put on the
 * home screen of a phone: the manifest and the service worker below.
 *
 * GET app                       the app
 * GET app/manifest.webmanifest  the manifest (name, icons, start page)
 * GET app/sw.js                 the service worker: keeps the page itself for a weak connection
 * GET app/code                  the data a QR / JAN code read by the camera points to
 */
class StudioAppController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request)
    {
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        return view('exment::studio.app', [
            'title' => exmtrans('studio.app_title'),
            'user_name' => isset($user) ? strval($user->base_user->getValue('user_name')) : '',
            // the drafts and the screens kept on the phone are kept per user
            'user_key' => isset($user) ? \Exceedone\Exment\Form\Navbar\NotifyNav::userKey() : '',
            'base' => admin_url(''),
            'webapi' => admin_url('webapi'),
            'manifest' => admin_url('app/manifest.webmanifest'),
            'service_worker' => admin_url('app/sw.js'),
            'scope' => admin_url('app'),
            'pc_url' => admin_url(''),
            'code_url' => admin_url('app/code'),
            // the reader of QR / bar codes Exment already has (loaded only when the camera is used)
            'zxing_url' => admin_asset('vendor/exment/js/zxing.js'),
        ]);
    }

    /**
     * The data a code read by the camera points to (QR or JAN / bar code).
     *
     * GET app/code?value=...&table=...
     *  - a link of this site: the QR code of Exment (qr-code/{table}/{id}) or the page of a data (data/{table}/{id})
     *  - with table (the page of a table): the data of that table that has the code as the value of a text,
     *    number or auto number column (a product code, a slip number...)
     *  - otherwise a JAN code given to a data (jan_codes)
     * Only the data the user may see. {value, found: [{table, id, label}]}
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function code(Request $request)
    {
        $value = trim(strval($request->get('value')));
        if ($value === '' || mb_strlen($value) > 2000) {
            return response()->json(['value' => $value, 'found' => []]);
        }

        // a link points to one data; on the page of a table, its own data come before a JAN code given elsewhere
        $hit = $this->fromLink($value);
        $found = isset($hit) ? [$hit] : $this->searchTable($request->get('table'), $value);
        if (empty($found)) {
            $jan = $this->fromJanCode($value);
            $found = isset($jan) ? [$jan] : [];
        }

        $list = [];
        foreach ($found as $custom_value) {
            $list[] = [
                'table' => $custom_value->custom_table->table_name,
                'id' => $custom_value->id,
                'label' => strval($custom_value->getLabel()),
            ];
        }
        return response()->json(['value' => $value, 'found' => $list]);
    }

    /**
     * the most rows a search by code gives back
     */
    public const CODE_MAX_FOUND = 20;

    /**
     * The column types a code is looked for in.
     */
    protected const CODE_COLUMN_TYPES = [ColumnType::TEXT, ColumnType::INTEGER, ColumnType::URL, ColumnType::EMAIL, ColumnType::AUTO_NUMBER];

    /**
     * A link of this site to one data: qr-code/{table}/{id} or data/{table}/{id}(/edit).
     *
     * @return CustomValue|null
     */
    protected function fromLink(string $value)
    {
        foreach (['qr-code', 'data'] as $prefix) {
            $base = admin_url($prefix) . '/';
            if (strpos($value, $base) !== 0) {
                continue;
            }
            if (!preg_match('#^([^/?\#]+)/(\d+)(?:/edit)?/?(?:[?\#].*)?$#u', substr($value, strlen($base)), $m)) {
                return null;
            }
            return $this->visibleValue(CustomTable::getEloquent(rawurldecode($m[1])), $m[2]);
        }
        return null;
    }

    /**
     * A JAN code given to a data.
     *
     * @return CustomValue|null
     */
    protected function fromJanCode(string $value)
    {
        if (!preg_match('/^\d{8,14}$/', $value) || !\Illuminate\Support\Facades\Schema::hasTable('jan_codes')) {
            return null;
        }
        $jan = DB::table('jan_codes')->where('jan_code', $value)->whereNotNull('table_id')->first();
        if (!$jan) {
            return null;
        }
        return $this->visibleValue(CustomTable::getEloquent($jan->table_id), $jan->target_id);
    }

    /**
     * The data of a table with the code as the value of one of its columns.
     *
     * @param mixed $table_name the table of the page, if any
     * @return CustomValue[]
     */
    protected function searchTable($table_name, string $value): array
    {
        $custom_table = is_string($table_name) && $table_name !== '' ? CustomTable::getEloquent($table_name) : null;
        if (!$custom_table || $custom_table->enableAccess() !== true) {
            return [];
        }
        $columns = $custom_table->custom_columns_cache->filter(function ($column) use ($value) {
            if (!in_array($column->column_type, static::CODE_COLUMN_TYPES, true)) {
                return false;
            }
            // a number column only for a number
            return $column->column_type !== ColumnType::INTEGER || preg_match('/^-?\d+$/', $value);
        });
        if ($columns->isEmpty()) {
            return [];
        }

        // the query of the data model keeps to the data the user may see
        /** @var CustomValue[] $found */
        $found = $custom_table->getValueQuery()
            ->where(function ($query) use ($columns, $value) {
                foreach ($columns as $column) {
                    $query->orWhere($column->getQueryKey(), $value);
                }
            })
            ->orderBy('id', 'desc')
            ->take(static::CODE_MAX_FOUND)
            ->get()
            ->all();
        return $found;
    }

    /**
     * @param CustomTable|null $custom_table
     * @param mixed $id
     * @return CustomValue|null
     */
    protected function visibleValue($custom_table, $id)
    {
        if (!$custom_table || $custom_table->enableAccess() !== true || $custom_table->hasPermissionData($id) !== true) {
            return null;
        }
        $custom_value = $custom_table->getValueModel($id);
        return $custom_value instanceof CustomValue ? $custom_value : null;
    }

    /**
     * @return \Illuminate\Http\JsonResponse
     */
    public function manifest()
    {
        return response()->json([
            'name' => exmtrans('studio.app_title'),
            'short_name' => 'Exment',
            'start_url' => admin_url('app'),
            'scope' => admin_url('app'),
            'display' => 'standalone',
            'background_color' => '#ffffff',
            'theme_color' => '#00704a',
            // the favicon of the site (system setting), or the one of Exment
            'icons' => [
                ['src' => \ExmentAdminCore\Admin\Facades\Admin::favicon() ?? asset('images/exment_favicon.png'), 'sizes' => 'any'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json']);
    }

    /**
     * The service worker. It keeps the page of the app, so the app opens on a
     * weak connection; the data is always read from the server.
     *
     * @return \Illuminate\Http\Response
     */
    public function serviceWorker()
    {
        $page = json_encode(admin_url('app'));
        $script = <<<EOT
var CACHE = 'exment-app-v1';
self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(CACHE).then(function (c) { return c.add($page); }).then(function () { return self.skipWaiting(); }));
});
self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});
self.addEventListener('fetch', function (e) {
    if (e.request.mode !== 'navigate') { return; }
    // the page: from the network, the kept one when there is none
    e.respondWith(fetch(e.request).then(function (r) {
        var copy = r.clone();
        caches.open(CACHE).then(function (c) { c.put($page, copy); });
        return r;
    }).catch(function () { return caches.match($page); }));
});
EOT;
        return response($script, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Service-Worker-Allowed' => parse_url(admin_url('app'), PHP_URL_PATH) ?: '/',
            'Cache-Control' => 'no-cache',
        ]);
    }
}
