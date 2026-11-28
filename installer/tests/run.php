<?php
// The installer for rental servers (ISS02961): its pure parts. Plain PHP, no network, no database.
//
//   php installer/tests/run.php
require __DIR__ . '/../exment-installer.php';

$fail = 0;
function ok($cond, $label)
{
    global $fail;
    echo ($cond ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$cond) {
        $fail++;
    }
}

// --- the server
ok(exi_detect_host('/home/xs123/example.com/public_html', 'sv1234.xserver.jp') === 'xserver', 'Xserver by the host name');
ok(exi_detect_host('/home/xs123/example.com/public_html/exment', 'web1') === 'xserver', 'Xserver by the folder');
ok(exi_detect_host('/home/user/www/exment', 'www123.sakura.ne.jp') === 'sakura', 'Sakura');
ok(exi_detect_host('/home/user/www', 'host') === 'sakura', 'Sakura by the folder');
ok(exi_detect_host('/var/www/html', 'myhost') === 'other', 'another server');

// --- PHP
ok(exi_php_range('v7.3.0', null) === array('8.2', '8.5'), '7.3 needs PHP 8.2 to 8.4');
ok(exi_php_range('v7.2.8', null) === array('8.1', '8.5'), '7.2 needs PHP 8.1 to 8.4');
ok(exi_php_range('v7.4.0', array('php' => array('8.3', '8.6'))) === array('8.3', '8.6'), 'the manifest may say it');
ok(exi_php_range('v7.2.8', array('php_min' => '8.2')) === array('8.2', '8.5'), 'php_min of exment.json (the libraries of the zip)');
ok(exi_php_range('v7.2.8', array('php_min' => 'x')) === array('8.1', '8.5'), 'a php_min that is not a version is not used');

ok(exi_platform_php("if (!(PHP_VERSION_ID >= 80300)) {") === '8.3.0', 'the PHP of the libraries (platform_check.php)');
ok(exi_platform_php("<?php // nothing") === null, 'no PHP in platform_check.php');

ok(exi_admin_prefix("APP_NAME=Exment\n") === 'admin', 'the pages of Exment: /admin by default');
ok(exi_admin_prefix("ADMIN_ROUTE_PREFIX=\n") === '', 'no prefix');
ok(exi_admin_prefix("ADMIN_ROUTE_PREFIX=\"exm/\"\n") === 'exm', 'another prefix');

// --- the manifest
$good = array('version' => 'v7.2.8', 'file' => 'exment_v7_2_8.zip', 'size' => 69478320, 'sha256' => str_repeat('a', 64), 'url' => 'https://exment.net/downloads/ja/exment_v7_2_8.zip');
ok(exi_manifest_problem($good, 'exment.net', false) === null, 'the manifest of exment.net');
ok(exi_manifest_problem('x', 'exment.net', false) === 'not_json', 'not JSON');
ok(exi_manifest_problem(array_merge($good, array('url' => 'https://evil.example/exment.zip')), 'exment.net', false) === 'url', 'another host');
ok(exi_manifest_problem(array_merge($good, array('url' => 'https://exment.net.evil.example/x.zip')), 'exment.net', false) === 'url', 'a host that only starts like it');
ok(exi_manifest_problem(array_merge($good, array('url' => 'http://exment.net/downloads/ja/exment.zip')), 'exment.net', false) === 'url', 'not https');
ok(exi_manifest_problem(array_merge($good, array('url' => 'http://exment.net/downloads/ja/exment.zip')), 'exment.net', true) === null, 'http only when the local settings allow it (tests)');
ok(exi_manifest_problem(array_merge($good, array('sha256' => 'abc')), 'exment.net', false) === 'sha256', 'no sha256');
ok(exi_manifest_problem(array_merge($good, array('size' => 10)), 'exment.net', false) === 'size', 'a size too small');
ok(exi_manifest_problem(array_merge($good, array('version' => '7.x')), 'exment.net', false) === 'version', 'a version that is not one');
ok(exi_manifest_problem(array_merge($good, array('url' => 'https://exment.net/downloads/ja/exment.php')), 'exment.net', false) === 'url', 'not a zip');

// --- the entries of the zip
ok(exi_entry_path('exment/app/Http/Kernel.php', 'exment/') === 'app/Http/Kernel.php', 'a file of the app');
ok(exi_entry_path('exment/', 'exment/') === '', 'the folder itself');
ok(exi_entry_path('exment/../../public_html/x.php', 'exment/') === null, '".." is refused');
ok(exi_entry_path('exment/a/./b', 'exment/') === null, '"." is refused');
ok(exi_entry_path('other/x.php', 'exment/') === null, 'outside the folder of the release');
ok(exi_entry_path('exment//etc/passwd', 'exment/') === null, 'an absolute path');
ok(exi_entry_path('exment/C:/x', 'exment/') === null, 'a drive of Windows');
ok(exi_entry_path("exment/a\0b", 'exment/') === null, 'a NUL');
ok(exi_entry_path('exment/a\\..\\b', 'exment/') === null, 'a backslash');

// --- the folder
ok(exi_in_the_way(array('.', '..', 'exment-installer.php', 'exment-installer-key.php', '.exment-installer', 'index.html', '.htaccess')) === array(), 'a new folder of a server');
ok(exi_in_the_way(array('.', '..', 'exment-installer.php', 'index.php', 'wp-config.php')) === array('index.php', 'wp-config.php'), 'a site already there');

// --- where the app goes
$yes = function ($path) {
    return true;
};
$no = function ($path) {
    return false;
};
ok(exi_app_dir('/home/u/example.com/public_html', '/home/u/example.com/public_html', $yes) === array('/home/u/example.com/exment', false), 'next to public_html');
ok(exi_app_dir('/home/u/example.com/public_html/t721', '/home/u/example.com/public_html', $yes) === array('/home/u/example.com/exment_t721', false), 'a folder of public_html');
ok(exi_app_dir('/home/u/example.com/public_html/a b', '/home/u/example.com/public_html', $yes) === array('/home/u/example.com/exment_a_b', false), 'a name of a folder made safe');
ok(exi_app_dir('/home/u/www', '/home/u/www', $no) === array('/home/u/www/exment-app', true), 'inside, closed, when outside cannot be written');

// --- index.php
$original = "<?php\n\nuse Illuminate\\Http\\Request;\n\nif (file_exists(\$maintenance = __DIR__.'/../storage/framework/maintenance.php')) {\n    require \$maintenance;\n}\n\nrequire __DIR__.'/../vendor/autoload.php';\n\n\$app = require_once __DIR__.'/../bootstrap/app.php';\n\n\$app->handleRequest(Request::capture());\n";
$index = exi_index_php($original, "/home/u/ex'ample.com/exment/");
ok(strpos($index, "\$exmentApp = '/home/u/ex\\'ample.com/exment';") !== false, 'the folder of the app, quoted');
ok(strpos($index, "__DIR__.'/../") === false, 'nothing is read from ../');
ok(strpos($index, "require \$exmentApp.'/vendor/autoload.php';") !== false, 'the autoloader of the app');
ok(strpos($index, "\$app->usePublicPath(__DIR__);") !== false, 'the public files are here');
$lint = tempnam(sys_get_temp_dir(), 'exi');
file_put_contents($lint, $index);
exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($lint) . ' 2>&1', $out, $code);
unlink($lint);
ok($code === 0, 'the index.php made is PHP');

// --- the texts: the same keys in both languages
$texts = exi_texts();
ok(array_keys($texts['ja']) === array_keys($texts['en']), 'ja and en have the same keys');
foreach (array('db_xserver', 'db_sakura', 'db_other') as $k) {
    ok(count($texts['ja'][$k]) === count($texts['en'][$k]), $k . ': as many lines');
}

echo $fail === 0 ? "\nall ok\n" : "\n$fail failed\n";
exit($fail === 0 ? 0 : 1);
