<?php
/**
 * Exment installer for rental servers (ISS02961).
 *
 * Upload this one file into the folder the site should be in (public_html, or a folder of it),
 * and open it in the browser. It:
 *   1. asks for the key it wrote next to itself (only who can see the files of the server can read it)
 *   2. checks PHP and the server
 *   3. downloads the release of exment.net in pieces and checks its sha256
 *   4. unpacks it in pieces, outside the public folder when it can
 *   5. puts the public files in this folder
 *   6. shows how to make the database on this server, and tests the connection
 *   7. hands over to the web installer of Exment, and deletes itself
 *
 * It never writes over an Exment that is already there. Written for old PHP too (5.6 and later
 * read it), so that an old PHP shows what to change instead of an error.
 */

// ============================================================== settings

define('EXI_VERSION', '1.0.0');
define('EXI_MANIFEST', 'https://exment.net/downloads/%s/exment.json');
// the host the release comes from: the manifest and the zip
define('EXI_RELEASE_HOST', 'exment.net');
// a request works about this long, then answers (the progress goes on with the next one)
define('EXI_STEP_SECONDS', 15);
// bytes a download request asks for at most
define('EXI_CHUNK_BYTES', 8 * 1024 * 1024);
define('EXI_LOGIN_TRIES', 10);
define('EXI_LOGIN_LOCK_SECONDS', 600);

// ============================================================== the pure part (tested by installer/tests)

/**
 * The key file (a PHP file: opened in a browser it shows nothing; read by FTP it shows the key).
 */
function exi_key_path($dir)
{
    return $dir . '/exment-installer-key.php';
}

function exi_work_dir($dir)
{
    return $dir . '/.exment-installer';
}

/**
 * The server, from the folder of the site and the name of the host: xserver, sakura or other.
 */
function exi_detect_host($dir, $hostname)
{
    $hostname = strtolower((string)$hostname);
    if (strpos($hostname, 'xserver.jp') !== false || preg_match('#^/home/[^/]+/[^/]+/public_html(/|$)#', $dir)) {
        return 'xserver';
    }
    if (strpos($hostname, 'sakura.ne.jp') !== false || preg_match('#^/home/[^/]+/www(/|$)#', $dir)) {
        return 'sakura';
    }
    return 'other';
}

/**
 * The PHP versions a release of Exment needs: [min, max) as "8.2", "8.5".
 */
function exi_php_range($version, $manifest)
{
    if (is_array($manifest) && isset($manifest['php']) && is_array($manifest['php']) && isset($manifest['php'][0], $manifest['php'][1])) {
        return array((string)$manifest['php'][0], (string)$manifest['php'][1]);
    }
    if (preg_match('/^v?(\d+)\.(\d+)/', (string)$version, $m)) {
        $minor = intval($m[1]) * 100 + intval($m[2]);
        if ($minor >= 703) {
            return array('8.2', '8.5');
        }
        if ($minor >= 700) {
            return array('8.1', '8.5');
        }
    }
    return array('8.2', '8.5');
}

/**
 * The lowest PHP the libraries of the release need, read from vendor/composer/platform_check.php
 * ("PHP_VERSION_ID >= 80300" → "8.3.0"), null when it says nothing.
 */
function exi_platform_php($code)
{
    if (!preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', (string)$code, $m)) {
        return null;
    }
    $id = intval($m[1]);
    return intval($id / 10000) . '.' . intval(($id % 10000) / 100) . '.' . ($id % 100);
}

/**
 * The prefix of the pages of Exment in a .env: ADMIN_ROUTE_PREFIX, "admin" when it is not there.
 */
function exi_admin_prefix($env)
{
    if (preg_match('/^ADMIN_ROUTE_PREFIX=(.*)$/m', (string)$env, $m)) {
        return trim(trim($m[1]), "\"'/");
    }
    return 'admin';
}

/**
 * Whether a manifest can be used: the version, the size, the sha256 and an https URL of the release host.
 *
 * @return string|null the reason it cannot be used, null when it can
 */
function exi_manifest_problem($manifest, $allowedHost, $allowHttp)
{
    if (!is_array($manifest)) {
        return 'not_json';
    }
    if (!isset($manifest['version']) || !preg_match('/^v?\d+\.\d+\.\d+$/', (string)$manifest['version'])) {
        return 'version';
    }
    if (!isset($manifest['size']) || !is_numeric($manifest['size']) || intval($manifest['size']) < 1024 * 1024 || intval($manifest['size']) > 1024 * 1024 * 1024) {
        return 'size';
    }
    if (!isset($manifest['sha256']) || !preg_match('/^[0-9a-f]{64}$/', (string)$manifest['sha256'])) {
        return 'sha256';
    }
    $url = isset($manifest['url']) ? (string)$manifest['url'] : '';
    $parts = parse_url($url);
    if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
        return 'url';
    }
    if ($parts['scheme'] !== 'https' && !($allowHttp && $parts['scheme'] === 'http')) {
        return 'url';
    }
    if (strtolower($parts['host']) !== strtolower($allowedHost)) {
        return 'url';
    }
    if (!preg_match('#\.zip$#', isset($parts['path']) ? $parts['path'] : '')) {
        return 'url';
    }
    return null;
}

/**
 * The path in the app of an entry of the zip ("exment/..."), or null when it may not be written
 * (outside the folder, an absolute path, "..", a NUL, a backslash).
 */
function exi_entry_path($name, $prefix)
{
    $name = (string)$name;
    if ($name === '' || strpos($name, "\0") !== false || strpos($name, '\\') !== false) {
        return null;
    }
    if (strpos($name, $prefix) !== 0) {
        return null;
    }
    $rel = substr($name, strlen($prefix));
    if ($rel === '' || $rel === false) {
        return '';
    }
    if ($rel[0] === '/' || preg_match('#^[A-Za-z]:#', $rel)) {
        return null;
    }
    foreach (explode('/', $rel) as $segment) {
        if ($segment === '..' || $segment === '.') {
            return null;
        }
    }
    return $rel;
}

/**
 * The files of a folder that would be in the way of the site (all but the installer, its key, its
 * work folder, and the page a server puts in a new folder).
 *
 * @return array the names in the way
 */
function exi_in_the_way(array $names)
{
    $allowed = array('.', '..', 'exment-installer.php', 'exment-installer-key.php', '.exment-installer', 'exment-installer.local.php',
        'index.html', 'default_page.png', '.htaccess', '.user.ini', 'php.ini', 'favicon.ico', 'cgi-bin', '.well-known');
    $out = array();
    foreach ($names as $name) {
        if (!in_array($name, $allowed, true)) {
            $out[] = $name;
        }
    }
    return $out;
}

/**
 * Where the app goes: next to the public folder (outside it) when that folder can be written,
 * else a folder inside, closed to the web.
 *
 * @return array [path, inside]
 */
function exi_app_dir($dir, $docroot, $writable)
{
    $docroot = rtrim((string)$docroot, '/');
    $dir = rtrim((string)$dir, '/');
    $sub = '';
    if ($docroot !== '' && strpos($dir . '/', $docroot . '/') === 0) {
        $sub = trim(substr($dir, strlen($docroot)), '/');
    }
    $outside = $docroot !== '' ? dirname($docroot) : '';
    if ($outside !== '' && $outside !== '/' && $outside !== '.' && call_user_func($writable, $outside)) {
        $name = 'exment' . ($sub === '' ? '' : '_' . preg_replace('#[^A-Za-z0-9_-]+#', '_', $sub));
        return array($outside . '/' . $name, false);
    }
    return array($dir . '/exment-app', true);
}

/**
 * The index.php of the public folder, made to load the app from its folder.
 */
function exi_index_php($original, $appDir)
{
    $app = var_export(rtrim($appDir, '/'), true);
    $code = str_replace("__DIR__.'/../", '$exmentApp.\'/', $original);
    $code = preg_replace('/<\?php\s*/', "<?php\n\n// placed by the Exment installer: the app is outside this public folder\n\$exmentApp = " . $app . ";\n\n", $code, 1);
    // the public files are here, not in the app's public folder
    $code = str_replace('$app->handleRequest(', "\$app->usePublicPath(__DIR__);\n\n\$app->handleRequest(", $code);
    return $code;
}

// ============================================================== texts

function exi_texts()
{
    return array(
        'ja' => array(
            'title' => 'Exment のインストール',
            'lead' => 'このサーバーに Exment を入れます。順に進めてください。',
            'step_key' => 'キー', 'step_check' => 'サーバーの確認', 'step_download' => 'ダウンロード', 'step_extract' => '展開',
            'step_place' => '配置', 'step_db' => 'データベース', 'step_finish' => '完了',
            'key_lead' => 'このインストーラーと同じフォルダーに「exment-installer-key.php」を作りました。FTP かサーバーのファイルマネージャーで開いて、中に書いてあるキーを入れてください（ブラウザーで開いても表示されません）。',
            'key_label' => 'キー', 'key_button' => '次へ', 'key_wrong' => 'キーが違います。', 'key_locked' => '何度も違うキーが入力されたので、10 分ほど止めています。',
            'key_cannot_write' => 'このフォルダーにファイルを作れません。フォルダーの書き込みの権限を確かめてください。',
            'check_lead' => 'サーバーを確かめました。',
            'check_php' => 'PHP の版', 'check_php_need' => '%s 以上 %s 未満が要ります（いまは %s）', 'check_php_min' => 'このリリースには PHP %s 以上が要ります（いまは %s）。',
            'check_ext' => 'PHP の拡張', 'check_ext_missing' => '足りない拡張：%s',
            'check_ext_recommended' => 'あるとよい拡張', 'check_ext_recommended_help' => 'なくても入れられますが、一部の機能（多言語・計算・暗号化など）に使います。サーバーの管理画面で有効にできるときは有効にしてください。',
            'check_memory' => 'メモリー（memory_limit）', 'check_memory_need' => '256M 以上をおすすめします（いまは %s）',
            'check_disk' => '空き容量', 'check_disk_need' => '%s 以上要ります（いまは %s）',
            'check_write' => '書き込み', 'check_write_need' => '%s に書き込めません',
            'check_net' => 'exment.net への接続', 'check_net_need' => 'curl か allow_url_fopen が要ります',
            'check_manifest' => 'リリースの情報', 'check_manifest_bad' => 'exment.net からリリースの情報を読めませんでした（%s）',
            'check_way' => 'このフォルダーの中身', 'check_way_need' => 'すでにファイルがあります：%s。空のフォルダーで実行してください（Exment がすでにあるときは、アップデートの手順を使ってください）。',
            'check_exists' => 'Exment がすでにあります（%s）。上書きしません。アップデートの手順を使ってください。',
            'check_ok' => 'OK', 'check_retry' => 'もう一度確かめる', 'check_next' => '%s をダウンロードする',
            'php_fix_xserver' => 'Xserver：サーバーパネル →「PHP Ver.切替」で、このドメインの PHP を %s にしてください。',
            'php_fix_sakura' => 'さくら：コントロールパネル →「スクリプト設定」で、PHP の版を %s にしてください。',
            'php_fix_other' => 'サーバーの管理画面で、PHP の版を %s にしてください。',
            'memory_fix_xserver' => 'Xserver：サーバーパネル →「php.ini設定」で memory_limit を 256M 以上にしてください。',
            'memory_fix_sakura' => 'さくら：コントロールパネル →「スクリプト設定」→「php.ini の設定」で memory_limit = 256M を足してください。',
            'memory_fix_other' => 'php.ini（または .user.ini）で memory_limit = 256M にしてください。',
            'download_lead' => 'Exment %s をダウンロードしています。', 'download_done' => 'ダウンロードして、内容（sha256）を確かめました。',
            'download_bad_hash' => 'ダウンロードしたファイルの内容が違います（sha256）。消しました。もう一度試してください。',
            'download_failed' => 'ダウンロードできませんでした：%s',
            'extract_lead' => '展開しています（%s / %s）。', 'extract_bad_entry' => 'zip の中に、書いてはいけない場所のファイルがありました：%s',
            'place_lead' => 'Exment を %s に置き、公開するファイルをこのフォルダーに置きました。', 'place_inside' => '（サーバーの都合で、このフォルダーの中に置き、Web からは見えないようにしました）',
            'place_link' => 'アプリの public フォルダーは、このフォルダーへのリンクにしました。', 'place_nolink' => 'リンクを作れなかったので、index.php からアプリを読むようにしました。',
            'db_lead' => 'データベースを作ってください。作ったら、下で接続を確かめてから次へ進みます。',
            'db_xserver' => array('サーバーパネルの「MySQL設定」を開きます。', '「MySQL追加」でデータベースを作ります（名前は「サーバーID_」で始まります）。', '「MySQLユーザ追加」でユーザーを作り、パスワードを決めます。', '「MySQL一覧」で、そのデータベースにユーザーを「追加」します（アクセス権）。', 'ホスト名は「MySQL設定」に出ている名前（localhost か mysqlNNNN.xserver.jp）です。'),
            'db_sakura' => array('コントロールパネルの「データベース」を開きます。', '「新規追加」でデータベースを作ります（文字コードは utf8mb4）。', 'ユーザー名はアカウント名、パスワードはデータベースを作ったときに決めたものです。', 'ホスト名は一覧に出ている「mysqlNNN.db.sakura.ne.jp」です。'),
            'db_other' => array('サーバーの管理画面で、MySQL（または MariaDB）のデータベースとユーザーを作ります。', '文字コードは utf8mb4 にします。', 'ホスト名・データベース名・ユーザー名・パスワードを控えます。'),
            'db_host' => 'ホスト名', 'db_port' => 'ポート', 'db_name' => 'データベース名', 'db_user' => 'ユーザー名', 'db_pass' => 'パスワード',
            'db_test' => '接続を確かめる', 'db_ok' => 'つながりました（%s）。この値を、次の画面（Exment のインストール画面）で入れてください。', 'db_ng' => 'つながりませんでした：%s',
            'db_note' => 'ここで入れた値は保存しません。確かめるだけです。',
            'db_not_empty' => 'つながりましたが、このデータベースにはテーブルが %s 個あります。Exment は空のデータベースに入れます。ほかのサイトのデータベースでないか確かめて、新しいデータベースを作るか、中を空にしてください。',
            'finish_button' => 'Exment のインストール画面へ進む', 'finish_lead' => 'このインストーラー・キー・作業用のファイルを消して、Exment のインストール画面に移ります。',
            'finish_left' => 'このインストーラーを消せませんでした。FTP などで「exment-installer.php」「exment-installer-key.php」「.exment-installer」を消してください。',
            'then_continue' => '変えたら、このページを開き直して「続ける」を押してください。',
            'error' => 'エラー：%s', 'busy' => 'ほかの画面で進めています。少し待ってから開き直してください。', 'lang' => 'English',
            'progress' => '%s %%', 'continue' => '続ける', 'restart' => 'はじめからやり直す',
            'restart_confirm' => 'ダウンロードと展開をはじめからやり直します（入れた Exment のファイルも消します）。よろしいですか？',
        ),
        'en' => array(
            'title' => 'Install Exment',
            'lead' => 'This puts Exment on this server. Go through the steps in order.',
            'step_key' => 'Key', 'step_check' => 'Server check', 'step_download' => 'Download', 'step_extract' => 'Unpack',
            'step_place' => 'Place', 'step_db' => 'Database', 'step_finish' => 'Finish',
            'key_lead' => 'The installer made the file "exment-installer-key.php" in its folder. Open it by FTP or with the file manager of the server and enter the key written in it (opened in a browser, it shows nothing).',
            'key_label' => 'Key', 'key_button' => 'Next', 'key_wrong' => 'The key is wrong.', 'key_locked' => 'Too many wrong keys: stopped for about 10 minutes.',
            'key_cannot_write' => 'The installer cannot make files in this folder. Check the write permission of the folder.',
            'check_lead' => 'The server was checked.',
            'check_php' => 'PHP version', 'check_php_need' => '%s or later and before %s is needed (now %s)', 'check_php_min' => 'This release needs PHP %s or later (now %s).',
            'check_ext' => 'PHP extensions', 'check_ext_missing' => 'Missing: %s',
            'check_ext_recommended' => 'Recommended extensions', 'check_ext_recommended_help' => 'Exment installs without them, but some features (languages, numbers, encryption) use them. Turn them on in the control panel of the server when you can.',
            'check_memory' => 'Memory (memory_limit)', 'check_memory_need' => '256M or more is recommended (now %s)',
            'check_disk' => 'Free space', 'check_disk_need' => '%s is needed (now %s)',
            'check_write' => 'Write', 'check_write_need' => 'Cannot write to %s',
            'check_net' => 'Connection to exment.net', 'check_net_need' => 'curl or allow_url_fopen is needed',
            'check_manifest' => 'Release', 'check_manifest_bad' => 'Could not read the release from exment.net (%s)',
            'check_way' => 'This folder', 'check_way_need' => 'There are files already: %s. Run it in an empty folder (to update an Exment, use the update steps).',
            'check_exists' => 'Exment is already there (%s). It is not overwritten: use the update steps.',
            'check_ok' => 'OK', 'check_retry' => 'Check again', 'check_next' => 'Download %s',
            'php_fix_xserver' => 'Xserver: server panel → "PHP Ver.切替": set PHP of this domain to %s.',
            'php_fix_sakura' => 'Sakura: control panel → script settings: set the PHP version to %s.',
            'php_fix_other' => 'Set the PHP version to %s in the control panel of the server.',
            'memory_fix_xserver' => 'Xserver: server panel → "php.ini設定": set memory_limit to 256M or more.',
            'memory_fix_sakura' => 'Sakura: control panel → script settings → php.ini: add memory_limit = 256M.',
            'memory_fix_other' => 'Set memory_limit = 256M in php.ini (or .user.ini).',
            'download_lead' => 'Downloading Exment %s.', 'download_done' => 'Downloaded, and its content (sha256) checked.',
            'download_bad_hash' => 'The downloaded file is not the release (sha256). It was deleted. Try again.',
            'download_failed' => 'Could not download: %s',
            'extract_lead' => 'Unpacking (%s / %s).', 'extract_bad_entry' => 'The zip has a file for a place it may not write: %s',
            'place_lead' => 'Exment is in %s, and its public files in this folder.', 'place_inside' => '(The server allowed only this folder: the app is inside it, closed to the web.)',
            'place_link' => 'The public folder of the app is a link to this folder.', 'place_nolink' => 'No link could be made: index.php loads the app from its folder.',
            'db_lead' => 'Make the database. Then test the connection below and go on.',
            'db_xserver' => array('Open "MySQL設定" in the server panel.', 'Add a database with "MySQL追加" (its name starts with your server ID and "_").', 'Add a user with "MySQLユーザ追加" and choose its password.', 'In "MySQL一覧", add the user to the database (access rights).', 'The host is the name shown in "MySQL設定" (localhost or mysqlNNNN.xserver.jp).'),
            'db_sakura' => array('Open "Databases" in the control panel.', 'Add a database (character set utf8mb4).', 'The user is your account name; the password is the one chosen when the database was made.', 'The host is the "mysqlNNN.db.sakura.ne.jp" shown in the list.'),
            'db_other' => array('Make a MySQL (or MariaDB) database and a user in the control panel of the server.', 'Use the character set utf8mb4.', 'Note the host, the database, the user and the password.'),
            'db_host' => 'Host', 'db_port' => 'Port', 'db_name' => 'Database', 'db_user' => 'User', 'db_pass' => 'Password',
            'db_test' => 'Test the connection', 'db_ok' => 'Connected (%s). Enter these values on the next page (the installer of Exment).', 'db_ng' => 'Could not connect: %s',
            'db_note' => 'The values entered here are not kept: only tested.',
            'db_not_empty' => 'Connected, but this database has %s tables. Exment goes into an empty database. Check it is not the database of another site, then make a new one or empty it.',
            'finish_button' => 'Go to the installer of Exment', 'finish_lead' => 'This deletes this installer, its key and its work files, and opens the installer of Exment.',
            'finish_left' => 'This installer could not be deleted. Delete "exment-installer.php", "exment-installer-key.php" and ".exment-installer" by FTP.',
            'then_continue' => 'Then open this page again and press "Continue".',
            'error' => 'Error: %s', 'busy' => 'Another page is working on it. Wait a little and open this page again.', 'lang' => '日本語',
            'progress' => '%s %%', 'continue' => 'Continue', 'restart' => 'Start again',
            'restart_confirm' => 'The download and the unpacking start again (the Exment files placed are deleted). Continue?',
        ),
    );
}

// ============================================================== the requests (not in the tests)

if (basename(__FILE__) === basename(isset($_SERVER['SCRIPT_FILENAME']) ? $_SERVER['SCRIPT_FILENAME'] : '') && PHP_SAPI !== 'cli') {
    exi_main();
}

function exi_main()
{
    @ini_set('display_errors', '0');
    @set_time_limit(EXI_STEP_SECONDS + 30);
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');

    $dir = __DIR__;
    $local = is_file($dir . '/exment-installer.local.php') ? include $dir . '/exment-installer.local.php' : array();
    $GLOBALS['exi_local'] = is_array($local) ? $local : array();

    $lang = exi_lang();
    $GLOBALS['exi_lang'] = $lang;
    $work = exi_work_dir($dir);
    if (!is_dir($work)) {
        @mkdir($work, 0700);
        @file_put_contents($work . '/.htaccess', "Require all denied\nDeny from all\n");
        @file_put_contents($work . '/index.html', '');
    }
    $key = exi_key($dir);
    if ($key === null) {
        exi_page('key', array('error' => exi_t('key_cannot_write')));
        return;
    }

    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    if ($action === 'login') {
        exi_login($dir, $key);
        return;
    }
    if (!exi_authed($key)) {
        exi_page('key', array());
        return;
    }
    if ($action !== '' && !hash_equals(exi_csrf($key), isset($_POST['csrf']) ? (string)$_POST['csrf'] : '')) {
        exi_json(array('error' => 'csrf'), 403);
        return;
    }

    $lock = @fopen($work . '/lock', 'c');
    if ($action !== '' && $lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        exi_json(array('error' => exi_t('busy'), 'busy' => true), 409);
        return;
    }
    try {
        switch ($action) {
            case 'download':
                exi_json(exi_download($dir));
                return;
            case 'extract':
                exi_json(exi_extract($dir));
                return;
            case 'place':
                exi_json(exi_place($dir));
                return;
            case 'dbtest':
                exi_json(exi_db_test());
                return;
            case 'finish':
                exi_finish($dir);
                return;
            case 'restart':
                exi_restart($dir);
                header('Location: ' . exi_self());
                return;
        }
    } catch (Exception $e) {
        exi_json(array('error' => $e->getMessage()), 500);
        return;
    }
    $state = exi_state($dir);
    exi_page('main', array('state' => $state, 'checks' => exi_checks($dir, $state)));
}

function exi_lang()
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], array('ja', 'en'), true)) {
        setcookie('exi_lang', $_GET['lang'], 0, '', '', exi_https(), true);
        return $_GET['lang'];
    }
    if (isset($_COOKIE['exi_lang']) && in_array($_COOKIE['exi_lang'], array('ja', 'en'), true)) {
        return $_COOKIE['exi_lang'];
    }
    $accept = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
    return strpos($accept, 'ja') === 0 ? 'ja' : 'en';
}

function exi_t($key)
{
    $texts = exi_texts();
    $lang = isset($GLOBALS['exi_lang']) ? $GLOBALS['exi_lang'] : 'en';
    $text = isset($texts[$lang][$key]) ? $texts[$lang][$key] : (isset($texts['en'][$key]) ? $texts['en'][$key] : $key);
    $args = array_slice(func_get_args(), 1);
    return count($args) > 0 && is_string($text) ? vsprintf($text, $args) : $text;
}

function exi_h($text)
{
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function exi_https()
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

function exi_self()
{
    return strtok(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : 'exment-installer.php', '?');
}

function exi_random($bytes)
{
    if (function_exists('random_bytes')) {
        return bin2hex(random_bytes($bytes));
    }
    return bin2hex(openssl_random_pseudo_bytes($bytes));
}

/**
 * The key: made once, kept in a PHP file that shows nothing to a browser.
 */
function exi_key($dir)
{
    $path = exi_key_path($dir);
    if (is_file($path)) {
        if (preg_match('/KEY: ([0-9a-f]{24})/', (string)@file_get_contents($path), $m)) {
            return $m[1];
        }
        return null;
    }
    $key = exi_random(12);
    $body = "<?php exit; ?>\nExment installer\nKEY: " . $key . "\n";
    if (@file_put_contents($path, $body, LOCK_EX) === false) {
        return null;
    }
    @chmod($path, 0600);
    return $key;
}

function exi_auth_value($key)
{
    return hash_hmac('sha256', 'auth|' . __FILE__, $key);
}

function exi_csrf($key)
{
    return hash_hmac('sha256', 'csrf|' . __FILE__, $key);
}

function exi_authed($key)
{
    return isset($_COOKIE['exi_auth']) && hash_equals(exi_auth_value($key), (string)$_COOKIE['exi_auth']);
}

function exi_login($dir, $key)
{
    $file = exi_work_dir($dir) . '/tries.php';
    $tries = array('count' => 0, 'since' => time());
    if (is_file($file) && preg_match('/^<\?php exit; \?>(.*)$/s', (string)@file_get_contents($file), $m)) {
        $read = json_decode($m[1], true);
        if (is_array($read)) {
            $tries = $read;
        }
    }
    if ($tries['count'] >= EXI_LOGIN_TRIES && time() - $tries['since'] < EXI_LOGIN_LOCK_SECONDS) {
        exi_page('key', array('error' => exi_t('key_locked')));
        return;
    }
    if (time() - $tries['since'] >= EXI_LOGIN_LOCK_SECONDS) {
        $tries = array('count' => 0, 'since' => time());
    }
    $given = isset($_POST['key']) ? strtolower(trim((string)$_POST['key'])) : '';
    if (!hash_equals($key, $given)) {
        $tries['count']++;
        @file_put_contents($file, '<?php exit; ?>' . json_encode($tries), LOCK_EX);
        exi_page('key', array('error' => exi_t('key_wrong')));
        return;
    }
    @unlink($file);
    if (PHP_VERSION_ID >= 70300) {
        setcookie('exi_auth', exi_auth_value($key), array('expires' => 0, 'path' => '', 'secure' => exi_https(), 'httponly' => true, 'samesite' => 'Strict'));
    } else {
        setcookie('exi_auth', exi_auth_value($key), 0, '', '', exi_https(), true);
    }
    header('Location: ' . exi_self());
}

// ------------------------------------------------------------- the state

function exi_state($dir)
{
    $file = exi_work_dir($dir) . '/state.php';
    $state = array();
    if (is_file($file) && preg_match('/^<\?php exit; \?>(.*)$/s', (string)@file_get_contents($file), $m)) {
        $read = json_decode($m[1], true);
        if (is_array($read)) {
            $state = $read;
        }
    }
    return $state + array('step' => 'check', 'manifest' => null, 'downloaded' => 0, 'verified' => false, 'extracted' => 0, 'entries' => 0, 'app_dir' => null, 'inside' => false, 'linked' => null);
}

function exi_save($dir, array $state)
{
    $file = exi_work_dir($dir) . '/state.php';
    $tmp = $file . '.' . exi_random(4);
    file_put_contents($tmp, '<?php exit; ?>' . json_encode($state), LOCK_EX);
    rename($tmp, $file);
}

function exi_local($key, $default)
{
    return isset($GLOBALS['exi_local'][$key]) ? $GLOBALS['exi_local'][$key] : $default;
}

// ------------------------------------------------------------- HTTP

/**
 * GET a URL (all or a range): [status, body]. Redirects are not followed (the release is on its host).
 */
function exi_http_get($url, $range = null)
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $headers = array('User-Agent: exment-installer/' . EXI_VERSION);
        if ($range !== null) {
            $headers[] = 'Range: bytes=' . $range;
        }
        curl_setopt_array($ch, array(CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => EXI_STEP_SECONDS + 10,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_HTTPHEADER => $headers, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2));
        $body = curl_exec($ch);
        $status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new Exception($error);
        }
        return array($status, $body);
    }
    if (!ini_get('allow_url_fopen')) {
        throw new Exception(exi_t('check_net_need'));
    }
    $header = "User-Agent: exment-installer/" . EXI_VERSION . "\r\n" . ($range !== null ? "Range: bytes=" . $range . "\r\n" : '');
    $context = stream_context_create(array('http' => array('header' => $header, 'follow_location' => 0, 'timeout' => EXI_STEP_SECONDS + 10, 'ignore_errors' => true)));
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new Exception('HTTP');
    }
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0], $m)) {
        $status = intval($m[1]);
    }
    return array($status, $body);
}

function exi_manifest_url()
{
    return exi_local('manifest', sprintf(EXI_MANIFEST, $GLOBALS['exi_lang'] === 'ja' ? 'ja' : 'en'));
}

function exi_read_manifest()
{
    list($status, $body) = exi_http_get(exi_manifest_url());
    if ($status !== 200) {
        throw new Exception('HTTP ' . $status);
    }
    $manifest = json_decode($body, true);
    $problem = exi_manifest_problem($manifest, exi_local('release_host', EXI_RELEASE_HOST), (bool)exi_local('allow_http', false));
    if ($problem !== null) {
        throw new Exception($problem);
    }
    return $manifest;
}

// ------------------------------------------------------------- the steps

/**
 * The checks of the server. Each: [label, ok, detail, fix].
 */
function exi_checks($dir, &$state)
{
    $host = exi_detect_host($dir, function_exists('gethostname') ? gethostname() : '');
    $checks = array();
    $manifest = null;
    try {
        $manifest = exi_read_manifest();
        if (!isset($state['manifest']) || $state['manifest']['sha256'] !== $manifest['sha256']) {
            if ($state['step'] === 'check' || $state['step'] === 'download') {
                $state['manifest'] = $manifest;
                $state['downloaded'] = 0;
                $state['verified'] = false;
                @unlink(exi_work_dir($dir) . '/release.zip');
                exi_save($dir, $state);
            }
        }
        $checks[] = array(exi_t('check_manifest'), true, 'Exment ' . $manifest['version'], '');
    } catch (Exception $e) {
        $checks[] = array(exi_t('check_manifest'), false, exi_t('check_manifest_bad', $e->getMessage()), '');
    }
    list($min, $max) = exi_php_range(isset($manifest['version']) ? $manifest['version'] : '', $manifest);
    $phpOk = version_compare(PHP_VERSION, $min, '>=') && version_compare(PHP_VERSION, $max, '<');
    $checks[] = array(exi_t('check_php'), $phpOk, $phpOk ? PHP_VERSION : exi_t('check_php_need', $min, $max, PHP_VERSION), $phpOk ? '' : exi_t('php_fix_' . $host, $min));
    // needed: Exment does not run without them; recommended: some features need them
    $missing = array_values(array_filter(array('pdo_mysql', 'mbstring', 'openssl', 'zip', 'gd', 'fileinfo', 'dom', 'xml', 'tokenizer', 'ctype', 'json'), function ($ext) {
        return !extension_loaded($ext);
    }));
    $checks[] = array(exi_t('check_ext'), count($missing) === 0, count($missing) === 0 ? 'OK' : exi_t('check_ext_missing', implode(', ', $missing)), '');
    $wanted = array_values(array_filter(array('sodium', 'intl', 'bcmath', 'curl'), function ($ext) {
        return !extension_loaded($ext);
    }));
    if (count($wanted) > 0) {
        $checks[] = array(exi_t('check_ext_recommended'), false, exi_t('check_ext_missing', implode(', ', $wanted)), exi_t('check_ext_recommended_help'), 'warn');
    }
    $memory = exi_bytes(ini_get('memory_limit'));
    $memOk = $memory < 0 || $memory >= 256 * 1024 * 1024;
    $checks[] = array(exi_t('check_memory'), $memOk, $memOk ? (string)ini_get('memory_limit') : exi_t('check_memory_need', ini_get('memory_limit')), $memOk ? '' : exi_t('memory_fix_' . $host), 'warn');
    $netOk = function_exists('curl_init') || (bool)ini_get('allow_url_fopen');
    $checks[] = array(exi_t('check_net'), $netOk, $netOk ? 'OK' : exi_t('check_net_need'), '');

    list($appDir) = exi_app_dir($dir, isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', 'is_writable');
    $writeOk = is_writable($dir) && is_writable(dirname($appDir));
    $checks[] = array(exi_t('check_write'), $writeOk, $writeOk ? 'OK' : exi_t('check_write_need', is_writable($dir) ? dirname($appDir) : $dir), '');
    $free = @disk_free_space($dir);
    $need = 400 * 1024 * 1024;
    $diskOk = $free === false || $free >= $need;
    $checks[] = array(exi_t('check_disk'), $diskOk, $diskOk ? ($free === false ? '?' : exi_size($free)) : exi_t('check_disk_need', exi_size($need), exi_size($free)), '');
    if ($state['step'] === 'check' || $state['step'] === 'download') {
        $way = exi_in_the_way(scandir($dir) ?: array());
        $exists = is_dir($appDir) && count(array_diff(scandir($appDir) ?: array(), array('.', '..'))) > 0;
        $checks[] = array(exi_t('check_way'), count($way) === 0 && !$exists,
            $exists ? exi_t('check_exists', $appDir) : (count($way) === 0 ? 'OK' : exi_t('check_way_need', implode(', ', array_slice($way, 0, 8)))), '');
    }
    // a warning does not stop the install
    return array('host' => $host, 'items' => $checks, 'ok' => count(array_filter($checks, function ($c) {
        return !$c[1] && !isset($c[4]);
    })) === 0);
}

function exi_bytes($value)
{
    $value = trim((string)$value);
    if ($value === '' || $value === '-1') {
        return -1;
    }
    $unit = strtolower(substr($value, -1));
    $number = floatval($value);
    $map = array('g' => 1073741824, 'm' => 1048576, 'k' => 1024);
    return intval(isset($map[$unit]) ? $number * $map[$unit] : $number);
}

function exi_size($bytes)
{
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    $bytes = floatval($bytes);
    while ($bytes >= 1024 && $i < count($units) - 1) {
        $bytes /= 1024;
        $i++;
    }
    return ($i === 0 ? $bytes : number_format($bytes, 1)) . ' ' . $units[$i];
}

/**
 * Download a part of the release (HTTP Range) until the time of the request is up; check the
 * sha256 at the end.
 */
function exi_download($dir)
{
    $state = exi_state($dir);
    if (!isset($state['manifest']['url'])) {
        throw new Exception(exi_t('check_manifest_bad', 'manifest'));
    }
    $manifest = $state['manifest'];
    $problem = exi_manifest_problem($manifest, exi_local('release_host', EXI_RELEASE_HOST), (bool)exi_local('allow_http', false));
    if ($problem !== null) {
        throw new Exception($problem);
    }
    $file = exi_work_dir($dir) . '/release.zip';
    $size = intval($manifest['size']);
    $start = microtime(true);
    clearstatcache();
    $have = is_file($file) ? filesize($file) : 0;
    while ($have < $size && microtime(true) - $start < EXI_STEP_SECONDS) {
        $end = min($size, $have + EXI_CHUNK_BYTES) - 1;
        list($status, $body) = exi_http_get($manifest['url'], $have . '-' . $end);
        if ($status === 200 && $have === 0 && strlen($body) === $size) {
            // a server without ranges: the whole file at once
        } elseif ($status !== 206) {
            throw new Exception(exi_t('download_failed', 'HTTP ' . $status));
        }
        if (strlen($body) === 0 || $have + strlen($body) > $size) {
            throw new Exception(exi_t('download_failed', 'size'));
        }
        file_put_contents($file, $body, FILE_APPEND | LOCK_EX);
        clearstatcache();
        $have = filesize($file);
    }
    $state['downloaded'] = $have;
    $state['step'] = 'download';
    if ($have >= $size) {
        if (!hash_equals($manifest['sha256'], hash_file('sha256', $file))) {
            @unlink($file);
            $state['downloaded'] = 0;
            exi_save($dir, $state);
            throw new Exception(exi_t('download_bad_hash'));
        }
        $state['verified'] = true;
        $state['step'] = 'extract';
    }
    exi_save($dir, $state);
    return array('done' => $state['verified'], 'have' => $have, 'size' => $size, 'percent' => intval($have * 100 / max(1, $size)));
}

/**
 * Unpack the release into the folder of the app, a part a request.
 */
function exi_extract($dir)
{
    $state = exi_state($dir);
    if (!$state['verified']) {
        throw new Exception('download');
    }
    if (!class_exists('ZipArchive')) {
        throw new Exception(exi_t('check_ext_missing', 'zip'));
    }
    if ($state['app_dir'] === null) {
        list($appDir, $inside) = exi_app_dir($dir, isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : '', 'is_writable');
        if (is_dir($appDir) && count(array_diff(scandir($appDir) ?: array(), array('.', '..'))) > 0) {
            throw new Exception(exi_t('check_exists', $appDir));
        }
        $state['app_dir'] = $appDir;
        $state['inside'] = $inside;
    }
    $appDir = $state['app_dir'];
    if (!is_dir($appDir) && !@mkdir($appDir, 0755, true)) {
        throw new Exception(exi_t('check_write_need', $appDir));
    }
    if ($state['inside']) {
        @file_put_contents($appDir . '/.htaccess', "Require all denied\nDeny from all\n");
    }
    $zip = new ZipArchive();
    if ($zip->open(exi_work_dir($dir) . '/release.zip') !== true) {
        throw new Exception('zip');
    }
    $state['entries'] = $zip->numFiles;
    $start = microtime(true);
    $i = intval($state['extracted']);
    for (; $i < $zip->numFiles && microtime(true) - $start < EXI_STEP_SECONDS; $i++) {
        $name = $zip->getNameIndex($i);
        $rel = exi_entry_path($name, 'exment/');
        if ($rel === null) {
            $zip->close();
            throw new Exception(exi_t('extract_bad_entry', $name));
        }
        if ($rel === '') {
            continue;
        }
        $target = $appDir . '/' . $rel;
        if (substr($name, -1) === '/') {
            if (!is_dir($target) && !@mkdir($target, 0755, true)) {
                throw new Exception(exi_t('check_write_need', $target));
            }
            continue;
        }
        if (!is_dir(dirname($target))) {
            @mkdir(dirname($target), 0755, true);
        }
        $in = $zip->getStream($name);
        $out = @fopen($target, 'wb');
        if (!$in || !$out) {
            throw new Exception(exi_t('check_write_need', $target));
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }
    $zip->close();
    $state['extracted'] = $i;
    if ($i >= $state['entries']) {
        // the PHP the libraries were built for (it can be newer than the release says)
        $need = exi_platform_php(@file_get_contents($appDir . '/vendor/composer/platform_check.php'));
        if ($need !== null && version_compare(PHP_VERSION, $need, '<')) {
            exi_save($dir, $state);
            $host = exi_detect_host($dir, function_exists('gethostname') ? gethostname() : '');
            throw new Exception(exi_t('check_php_min', $need, PHP_VERSION) . ' ' . exi_t('php_fix_' . $host, $need) . ' ' . exi_t('then_continue'));
        }
        $state['step'] = 'place';
    }
    exi_save($dir, $state);
    return array('done' => $i >= $state['entries'], 'have' => $i, 'size' => $state['entries'], 'percent' => intval($i * 100 / max(1, $state['entries'])));
}

/**
 * Put the public files of the app in this folder: index.php loads the app from its folder; the
 * public folder of the app becomes a link to this folder when the server allows it.
 */
function exi_place($dir)
{
    $state = exi_state($dir);
    if ($state['step'] !== 'place' && $state['step'] !== 'db') {
        throw new Exception('extract');
    }
    $appDir = $state['app_dir'];
    $public = $appDir . '/public';
    if (is_dir($public) && !is_link($public)) {
        foreach (scandir($public) as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            if ($name === 'index.php') {
                file_put_contents($dir . '/index.php', exi_index_php((string)file_get_contents($public . '/index.php'), $appDir));
                continue;
            }
            if ($name === '.htaccess' && is_file($dir . '/.htaccess')) {
                // what the server wrote there (as the version of PHP) stays, after the rules of Exment
                $before = (string)file_get_contents($dir . '/.htaccess');
                @copy($dir . '/.htaccess', $dir . '/.htaccess.before-exment');
                file_put_contents($dir . '/.htaccess', file_get_contents($public . '/.htaccess') . "\n# kept from before the installer of Exment\n" . $before);
                continue;
            }
            exi_copy($public . '/' . $name, $dir . '/' . $name);
        }
        // the page a server puts in a new folder would come before index.php
        if (is_file($dir . '/index.html')) {
            @rename($dir . '/index.html', $dir . '/index.html.bak');
        }
        // the public folder of the app: a link to this folder (the commands publish here too)
        exi_remove($public);
        $state['linked'] = function_exists('symlink') && @symlink($dir, $public);
        if (!$state['linked']) {
            @mkdir($public, 0755);
        }
    }
    $state['step'] = 'db';
    exi_save($dir, $state);
    return array('done' => true, 'app_dir' => $appDir, 'inside' => $state['inside'], 'linked' => $state['linked']);
}

function exi_copy($from, $to)
{
    if (is_dir($from)) {
        if (!is_dir($to)) {
            mkdir($to, 0755, true);
        }
        foreach (scandir($from) as $name) {
            if ($name !== '.' && $name !== '..') {
                exi_copy($from . '/' . $name, $to . '/' . $name);
            }
        }
        return;
    }
    copy($from, $to);
}

function exi_remove($path)
{
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) as $name) {
        if ($name !== '.' && $name !== '..') {
            exi_remove($path . '/' . $name);
        }
    }
    @rmdir($path);
}

/**
 * Only connect, with what was entered (not kept).
 */
function exi_db_test()
{
    $host = isset($_POST['host']) ? trim((string)$_POST['host']) : '';
    $port = isset($_POST['port']) && trim((string)$_POST['port']) !== '' ? intval($_POST['port']) : 3306;
    $name = isset($_POST['database']) ? trim((string)$_POST['database']) : '';
    $user = isset($_POST['user']) ? (string)$_POST['user'] : '';
    $pass = isset($_POST['password']) ? (string)$_POST['password'] : '';
    if ($host === '' || $name === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $host) || !preg_match('/^[A-Za-z0-9_$-]+$/', $name)) {
        return array('ok' => false, 'message' => exi_t('db_ng', 'host / database'));
    }
    if (!class_exists('PDO') || !in_array('mysql', PDO::getAvailableDrivers(), true)) {
        return array('ok' => false, 'message' => exi_t('db_ng', 'pdo_mysql'));
    }
    try {
        $pdo = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, array(PDO::ATTR_TIMEOUT => 5, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
        $version = (string)$pdo->query('select version()')->fetchColumn();
        // Exment needs an empty database: one with tables is another site's
        $tables = $pdo->query('show tables')->fetchAll(PDO::FETCH_COLUMN);
        if (count($tables) > 0) {
            return array('ok' => false, 'message' => exi_t('db_not_empty', count($tables)));
        }
        return array('ok' => true, 'message' => exi_t('db_ok', $version));
    } catch (Exception $e) {
        // the reason, without the password
        return array('ok' => false, 'message' => exi_t('db_ng', str_replace($pass === '' ? "\0" : $pass, '***', $e->getMessage())));
    }
}

/**
 * Delete the installer, its key and its work files, then open the installer of Exment.
 */
function exi_finish($dir)
{
    $state = exi_state($dir);
    if ($state['step'] !== 'db') {
        exi_json(array('error' => 'place'), 400);
        return;
    }
    exi_remove(exi_work_dir($dir));
    @unlink(exi_key_path($dir));
    @unlink($dir . '/exment-installer.local.php');
    $left = !@unlink(__FILE__);
    setcookie('exi_auth', '', time() - 3600);
    // the pages of Exment are under its prefix ("admin" unless the .env says another)
    $next = rtrim(dirname(exi_self()), '/') . '/' . exi_admin_prefix((string)@file_get_contents($state['app_dir'] . '/.env'));
    if ($left) {
        exi_page('left', array('next' => $next));
        return;
    }
    header('Location: ' . $next);
}

function exi_restart($dir)
{
    $state = exi_state($dir);
    if ($state['app_dir'] !== null && $state['step'] !== 'db' && is_dir($state['app_dir'])) {
        exi_remove($state['app_dir']);
    }
    @unlink(exi_work_dir($dir) . '/release.zip');
    @unlink(exi_work_dir($dir) . '/state.php');
}

function exi_json(array $data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
}

// ------------------------------------------------------------- the page

function exi_page($view, array $data)
{
    header('Content-Type: text/html; charset=utf-8');
    $lang = $GLOBALS['exi_lang'];
    $key = $view === 'main' ? exi_key(__DIR__) : null;
    $csrf = $key !== null ? exi_csrf($key) : '';
    $self = exi_h(exi_self());
    $other = $lang === 'ja' ? 'en' : 'ja';
    echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex"><title>' . exi_h(exi_t('title')) . '</title><style>' . exi_css() . '</style></head><body><main>';
    echo '<header><h1>' . exi_h(exi_t('title')) . '</h1><a class="lang" href="' . $self . '?lang=' . $other . '">' . exi_h(exi_t('lang')) . '</a></header>';
    if ($view === 'key') {
        echo '<p>' . exi_h(exi_t('key_lead')) . '</p>';
        if (!empty($data['error'])) {
            echo '<p class="ng">' . exi_h($data['error']) . '</p>';
        }
        echo '<form method="post" action="' . $self . '"><input type="hidden" name="action" value="login"><label>' . exi_h(exi_t('key_label'))
            . ' <input name="key" autocomplete="off" required size="30" autofocus></label> <button>' . exi_h(exi_t('key_button')) . '</button></form>';
    } elseif ($view === 'left') {
        echo '<p class="ng">' . exi_h(exi_t('finish_left')) . '</p><p><a class="button" href="' . exi_h($data['next']) . '">' . exi_h(exi_t('finish_button')) . '</a></p>';
    } else {
        exi_main_view($data['state'], $data['checks'], $csrf, $self);
    }
    echo '<footer>Exment installer ' . EXI_VERSION . '</footer></main></body></html>';
}

function exi_main_view(array $state, array $checks, $csrf, $self)
{
    $steps = array('check', 'download', 'extract', 'place', 'db');
    $at = array_search($state['step'], $steps, true);
    echo '<ol class="steps">';
    foreach ($steps as $i => $step) {
        echo '<li class="' . ($i < $at ? 'done' : ($i === $at ? 'now' : '')) . '">' . exi_h(exi_t('step_' . $step)) . '</li>';
    }
    echo '</ol><p>' . exi_h(exi_t('lead')) . '</p>';

    // the checks
    echo '<section><h2>' . exi_h(exi_t('step_check')) . '</h2><table>';
    foreach ($checks['items'] as $c) {
        $class = $c[1] ? 'ok' : (isset($c[4]) ? 'warn' : 'ng');
        echo '<tr><th>' . exi_h($c[0]) . '</th><td class="' . $class . '">' . ($c[1] ? '✓ ' : (isset($c[4]) ? '⚠ ' : '✗ ')) . exi_h($c[2])
            . ($c[3] !== '' ? '<div class="fix">' . exi_h($c[3]) . '</div>' : '') . '</td></tr>';
    }
    echo '</table>';
    $version = isset($state['manifest']['version']) ? $state['manifest']['version'] : '';
    if (in_array($state['step'], array('check', 'download', 'extract'), true)) {
        if ($checks['ok']) {
            echo '<p><button id="go" data-step="' . exi_h($state['step'] === 'extract' ? 'extract' : 'download') . '">'
                . exi_h($state['step'] === 'check' ? exi_t('check_next', $version) : exi_t('continue')) . '</button></p>';
        } else {
            echo '<p><a class="button" href="' . $self . '">' . exi_h(exi_t('check_retry')) . '</a></p>';
        }
    }
    echo '</section>';

    // the download and the unpacking
    echo '<section id="work" ' . (in_array($state['step'], array('download', 'extract'), true) ? '' : 'hidden') . '><h2>' . exi_h(exi_t('step_download')) . ' / ' . exi_h(exi_t('step_extract')) . '</h2>'
        . '<p id="work-text"></p><div class="bar"><div id="bar"></div></div><p id="work-error" class="ng"></p></section>';

    // the database
    if ($state['step'] === 'db') {
        $place = exi_t('place_lead', $state['app_dir']) . ($state['inside'] ? ' ' . exi_t('place_inside') : '') . ' ' . ($state['linked'] ? exi_t('place_link') : exi_t('place_nolink'));
        echo '<section><h2>' . exi_h(exi_t('step_place')) . '</h2><p class="ok">✓ ' . exi_h($place) . '</p></section>';
        echo '<section><h2>' . exi_h(exi_t('step_db')) . '</h2><p>' . exi_h(exi_t('db_lead')) . '</p><ol>';
        foreach (exi_t('db_' . $checks['host']) as $line) {
            echo '<li>' . exi_h($line) . '</li>';
        }
        echo '</ol><form id="db" class="db">';
        foreach (array('host' => array('db_host', 'localhost'), 'port' => array('db_port', '3306'), 'database' => array('db_name', ''), 'user' => array('db_user', ''), 'password' => array('db_pass', '')) as $field => $def) {
            echo '<label>' . exi_h(exi_t($def[0])) . ' <input name="' . $field . '" value="' . exi_h($def[1]) . '"' . ($field === 'password' ? ' type="password" autocomplete="new-password"' : ' autocomplete="off"') . '></label>';
        }
        echo '<button type="submit">' . exi_h(exi_t('db_test')) . '</button><p id="db-result"></p><p class="note">' . exi_h(exi_t('db_note')) . '</p></form>';
        echo '<form method="post" action="' . $self . '"><input type="hidden" name="action" value="finish"><input type="hidden" name="csrf" value="' . exi_h($csrf) . '">'
            . '<p>' . exi_h(exi_t('finish_lead')) . '</p><button class="primary">' . exi_h(exi_t('finish_button')) . '</button></form></section>';
    }
    if ($state['step'] !== 'check') {
        echo '<form method="post" action="' . $self . '" class="restart" onsubmit="return confirm(' . exi_h(json_encode(exi_t('restart_confirm'))) . ')">'
            . '<input type="hidden" name="action" value="restart"><input type="hidden" name="csrf" value="' . exi_h($csrf) . '"><button class="link">' . exi_h(exi_t('restart')) . '</button></form>';
    }
    echo '<script>' . exi_js($csrf, $version) . '</script>';
}

function exi_css()
{
    return 'body{font-family:system-ui,-apple-system,"Segoe UI","Hiragino Sans","Noto Sans JP",sans-serif;background:#f5f7fa;color:#222;margin:0}'
        . 'main{max-width:760px;margin:24px auto;padding:0 16px}header{display:flex;align-items:center;justify-content:space-between}h1{font-size:22px}h2{font-size:17px;margin:0 0 8px}'
        . 'section{background:#fff;border:1px solid #dde3ea;border-radius:8px;padding:16px;margin:12px 0}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:6px 8px;border-bottom:1px solid #eef1f4;vertical-align:top}th{width:34%;font-weight:600}'
        . '.ok{color:#15803d}.ng{color:#b91c1c}.warn{color:#b45309}.fix{color:#555;font-size:13px;margin-top:2px}.note{color:#666;font-size:13px}'
        . 'button,.button{background:#2563eb;color:#fff;border:0;border-radius:6px;padding:8px 16px;font-size:15px;cursor:pointer;text-decoration:none;display:inline-block}button.link{background:none;color:#666;text-decoration:underline;padding:0;font-size:13px}'
        . '.steps{display:flex;gap:6px;list-style:none;padding:0;flex-wrap:wrap}.steps li{padding:4px 10px;border-radius:12px;background:#e5e7eb;font-size:13px}.steps li.done{background:#dcfce7;color:#15803d}.steps li.now{background:#2563eb;color:#fff}'
        . '.bar{height:12px;background:#e5e7eb;border-radius:6px;overflow:hidden}#bar{height:100%;width:0;background:#2563eb;transition:width .3s}'
        . 'form.db{display:grid;gap:8px;max-width:420px}form.db input{width:100%;padding:6px;box-sizing:border-box}input{font-size:15px;padding:6px}.restart{margin-top:8px}footer{color:#999;font-size:12px;margin:16px 0}';
}

function exi_js($csrf, $version)
{
    $t = array('download' => exi_t('download_lead', $version), 'extract' => exi_t('extract_lead', '%a', '%b'), 'error' => exi_t('error', '%s'));
    return '(function(){var csrf=' . json_encode($csrf) . ',t=' . json_encode($t) . ',self=location.pathname;'
        . 'function post(action,extra){var fd=new FormData();fd.append("action",action);fd.append("csrf",csrf);if(extra){for(var k in extra){fd.append(k,extra[k]);}}'
        . 'return fetch(self,{method:"POST",body:fd,credentials:"same-origin"}).then(function(r){return r.json().then(function(j){if(!r.ok){throw new Error(j.error||r.status);}return j;});});}'
        . 'function show(text,p){document.getElementById("work").hidden=false;document.getElementById("work-text").textContent=text;document.getElementById("bar").style.width=p+"%";}'
        . 'function fail(e){document.getElementById("work-error").textContent=t.error.replace("%s",e.message);var g=document.getElementById("go");if(g){g.disabled=false;}}'
        . 'function download(){post("download").then(function(j){show(t.download+" "+j.percent+"%",j.percent);if(j.done){extract();}else{download();}}).catch(fail);}'
        . 'function extract(){post("extract").then(function(j){show(t.extract.replace("%a",j.have).replace("%b",j.size),j.percent);if(j.done){post("place").then(function(){location.reload();}).catch(fail);}else{extract();}}).catch(fail);}'
        . 'var go=document.getElementById("go");if(go){go.addEventListener("click",function(){go.disabled=true;document.getElementById("work-error").textContent="";if(go.getAttribute("data-step")==="extract"){extract();}else{download();}});}'
        . 'var db=document.getElementById("db");if(db){db.addEventListener("submit",function(e){e.preventDefault();var out=document.getElementById("db-result"),v={};'
        . '["host","port","database","user","password"].forEach(function(n){v[n]=db.elements[n].value;});out.textContent="…";'
        . 'post("dbtest",v).then(function(j){out.textContent=j.message;out.className=j.ok?"ok":"ng";}).catch(function(e){out.textContent=e.message;out.className="ng";});});}'
        . '})();';
}
