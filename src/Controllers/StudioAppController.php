<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

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
 */
class StudioAppController extends Controller
{
    /**
     * @return \Illuminate\Contracts\View\View
     */
    public function index(Request $request)
    {
        $user = \Exment::user();
        return view('exment::studio.app', [
            'title' => exmtrans('studio.app_title'),
            'user_name' => isset($user) ? strval($user->base_user->getValue('user_name')) : '',
            'base' => admin_url(''),
            'webapi' => admin_url('webapi'),
            'manifest' => admin_url('app/manifest.webmanifest'),
            'service_worker' => admin_url('app/sw.js'),
            'scope' => admin_url('app'),
            'pc_url' => admin_url(''),
        ]);
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
