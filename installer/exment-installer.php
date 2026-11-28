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
    // the lowest PHP the libraries of the zip were built for (exment.json "php_min", from platform_check.php)
    if (is_array($manifest) && isset($manifest['php_min']) && preg_match('/^\d+\.\d+(\.\d+)?$/', (string)$manifest['php_min'])) {
        return array((string)$manifest['php_min'], '8.5');
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
            'error' => 'エラー：%s', 'busy' => 'ほかの画面で進めています。少し待ってから開き直してください。', 'lang' => '日本語',
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
            'error' => 'Error: %s', 'busy' => 'Another page is working on it. Wait a little and open this page again.', 'lang' => 'English',
            'progress' => '%s %%', 'continue' => 'Continue', 'restart' => 'Start again',
            'restart_confirm' => 'The download and the unpacking start again (the Exment files placed are deleted). Continue?',
        ),
        'de' => array(
            'title' => 'Exment installieren',
            'lead' => 'Damit wird Exment auf diesem Server installiert. Gehen Sie die Schritte der Reihe nach durch.',
            'step_key' => 'Schlüssel',
            'step_check' => 'Serverprüfung',
            'step_download' => 'Download',
            'step_extract' => 'Entpacken',
            'step_place' => 'Ablegen',
            'step_db' => 'Datenbank',
            'step_finish' => 'Fertig',
            'key_lead' => 'Der Installer hat in seinem Ordner die Datei „exment-installer-key.php“ angelegt. Öffnen Sie sie per FTP oder mit dem Dateimanager des Servers und geben Sie den darin stehenden Schlüssel ein (im Browser geöffnet zeigt sie nichts).',
            'key_label' => 'Schlüssel',
            'key_button' => 'Weiter',
            'key_wrong' => 'Der Schlüssel ist falsch.',
            'key_locked' => 'Zu viele falsche Schlüssel: für etwa 10 Minuten gesperrt.',
            'key_cannot_write' => 'Der Installer kann in diesem Ordner keine Dateien anlegen. Prüfen Sie die Schreibrechte des Ordners.',
            'check_lead' => 'Der Server wurde geprüft.',
            'check_php' => 'PHP-Version',
            'check_php_need' => '%s oder neuer und älter als %s ist nötig (jetzt %s)',
            'check_php_min' => 'Diese Version braucht PHP %s oder neuer (jetzt %s).',
            'check_ext' => 'PHP-Erweiterungen',
            'check_ext_missing' => 'Fehlt: %s',
            'check_ext_recommended' => 'Empfohlene Erweiterungen',
            'check_ext_recommended_help' => 'Exment lässt sich ohne sie installieren, aber einige Funktionen (Sprachen, Zahlen, Verschlüsselung) nutzen sie. Aktivieren Sie sie in der Verwaltung des Servers, wenn möglich.',
            'check_memory' => 'Speicher (memory_limit)',
            'check_memory_need' => '256M oder mehr wird empfohlen (jetzt %s)',
            'check_disk' => 'Freier Speicherplatz',
            'check_disk_need' => '%s ist nötig (jetzt %s)',
            'check_write' => 'Schreiben',
            'check_write_need' => 'Kann nicht in %s schreiben',
            'check_net' => 'Verbindung zu exment.net',
            'check_net_need' => 'curl oder allow_url_fopen ist nötig',
            'check_manifest' => 'Version',
            'check_manifest_bad' => 'Die Version konnte nicht von exment.net gelesen werden (%s)',
            'check_way' => 'Dieser Ordner',
            'check_way_need' => 'Es gibt hier schon Dateien: %s. Führen Sie ihn in einem leeren Ordner aus (zum Aktualisieren von Exment nutzen Sie die Update-Schritte).',
            'check_exists' => 'Exment ist schon vorhanden (%s). Es wird nicht überschrieben: nutzen Sie die Update-Schritte.',
            'check_ok' => 'OK',
            'check_retry' => 'Erneut prüfen',
            'check_next' => '%s herunterladen',
            'php_fix_xserver' => 'Xserver: Serverpanel → „PHP Ver.切替“: PHP dieser Domain auf %s stellen.',
            'php_fix_sakura' => 'Sakura: Systemsteuerung → Skripteinstellungen: PHP-Version auf %s stellen.',
            'php_fix_other' => 'Stellen Sie die PHP-Version in der Verwaltung des Servers auf %s.',
            'memory_fix_xserver' => 'Xserver: Serverpanel → „php.ini設定“: memory_limit auf 256M oder mehr stellen.',
            'memory_fix_sakura' => 'Sakura: Systemsteuerung → Skripteinstellungen → php.ini: memory_limit = 256M hinzufügen.',
            'memory_fix_other' => 'Setzen Sie memory_limit = 256M in php.ini (oder .user.ini).',
            'download_lead' => 'Exment %s wird heruntergeladen.',
            'download_done' => 'Heruntergeladen und Inhalt (sha256) geprüft.',
            'download_bad_hash' => 'Die heruntergeladene Datei ist nicht die Version (sha256). Sie wurde gelöscht. Versuchen Sie es erneut.',
            'download_failed' => 'Download nicht möglich: %s',
            'extract_lead' => 'Wird entpackt (%s / %s).',
            'extract_bad_entry' => 'Das Zip enthält eine Datei für einen Ort, an den nicht geschrieben werden darf: %s',
            'place_lead' => 'Exment liegt in %s, seine öffentlichen Dateien in diesem Ordner.',
            'place_inside' => '(Der Server erlaubt nur diesen Ordner: die Anwendung liegt darin, für das Web gesperrt.)',
            'place_link' => 'Der öffentliche Ordner der Anwendung ist ein Link auf diesen Ordner.',
            'place_nolink' => 'Kein Link möglich: index.php lädt die Anwendung aus ihrem Ordner.',
            'db_lead' => 'Legen Sie die Datenbank an. Prüfen Sie dann unten die Verbindung und fahren Sie fort.',
            'db_xserver' => array('Öffnen Sie „MySQL設定“ im Serverpanel.', 'Legen Sie mit „MySQL追加“ eine Datenbank an (der Name beginnt mit Ihrer Server-ID und „_“).', 'Legen Sie mit „MySQLユーザ追加“ einen Benutzer an und wählen Sie sein Passwort.', 'Fügen Sie in „MySQL一覧“ den Benutzer der Datenbank hinzu (Zugriffsrechte).', 'Der Host ist der in „MySQL設定“ angezeigte Name (localhost oder mysqlNNNN.xserver.jp).'),
            'db_sakura' => array('Öffnen Sie „Datenbanken“ in der Systemsteuerung.', 'Legen Sie eine Datenbank an (Zeichensatz utf8mb4).', 'Der Benutzer ist Ihr Kontoname, das Passwort das beim Anlegen der Datenbank gewählte.', 'Der Host ist das in der Liste angezeigte „mysqlNNN.db.sakura.ne.jp“.'),
            'db_other' => array('Legen Sie in der Verwaltung des Servers eine MySQL- (oder MariaDB-)Datenbank und einen Benutzer an.', 'Verwenden Sie den Zeichensatz utf8mb4.', 'Notieren Sie Host, Datenbank, Benutzer und Passwort.'),
            'db_host' => 'Host',
            'db_port' => 'Port',
            'db_name' => 'Datenbank',
            'db_user' => 'Benutzer',
            'db_pass' => 'Passwort',
            'db_test' => 'Verbindung prüfen',
            'db_ok' => 'Verbunden (%s). Geben Sie diese Werte auf der nächsten Seite ein (dem Installer von Exment).',
            'db_ng' => 'Keine Verbindung: %s',
            'db_note' => 'Die hier eingegebenen Werte werden nicht gespeichert, nur geprüft.',
            'db_not_empty' => 'Verbunden, aber diese Datenbank hat %s Tabellen. Exment braucht eine leere Datenbank. Prüfen Sie, dass sie nicht zu einer anderen Website gehört, und legen Sie eine neue an oder leeren Sie sie.',
            'finish_button' => 'Weiter zum Installer von Exment',
            'finish_lead' => 'Damit werden dieser Installer, sein Schlüssel und seine Arbeitsdateien gelöscht und der Installer von Exment geöffnet.',
            'finish_left' => 'Dieser Installer konnte nicht gelöscht werden. Löschen Sie „exment-installer.php“, „exment-installer-key.php“ und „.exment-installer“ per FTP.',
            'then_continue' => 'Öffnen Sie danach diese Seite erneut und klicken Sie auf „Fortfahren“.',
            'error' => 'Fehler: %s',
            'busy' => 'Eine andere Seite arbeitet daran. Warten Sie kurz und öffnen Sie diese Seite erneut.',
            'progress' => '%s %%',
            'continue' => 'Fortfahren',
            'restart' => 'Neu beginnen',
            'restart_confirm' => 'Download und Entpacken beginnen neu (die abgelegten Exment-Dateien werden gelöscht). Fortfahren?',
            'lang' => 'Deutsch',
        ),
        'es' => array(
            'title' => 'Instalar Exment',
            'lead' => 'Esto instala Exment en este servidor. Siga los pasos en orden.',
            'step_key' => 'Clave',
            'step_check' => 'Comprobación',
            'step_download' => 'Descarga',
            'step_extract' => 'Descompresión',
            'step_place' => 'Colocación',
            'step_db' => 'Base de datos',
            'step_finish' => 'Fin',
            'key_lead' => 'El instalador creó el archivo «exment-installer-key.php» en su carpeta. Ábralo por FTP o con el administrador de archivos del servidor e introduzca la clave escrita en él (abierto en un navegador no muestra nada).',
            'key_label' => 'Clave',
            'key_button' => 'Siguiente',
            'key_wrong' => 'La clave no es correcta.',
            'key_locked' => 'Demasiadas claves incorrectas: detenido unos 10 minutos.',
            'key_cannot_write' => 'El instalador no puede crear archivos en esta carpeta. Compruebe los permisos de escritura de la carpeta.',
            'check_lead' => 'Se comprobó el servidor.',
            'check_php' => 'Versión de PHP',
            'check_php_need' => 'Se necesita %s o posterior y anterior a %s (ahora %s)',
            'check_php_min' => 'Esta versión necesita PHP %s o posterior (ahora %s).',
            'check_ext' => 'Extensiones de PHP',
            'check_ext_missing' => 'Faltan: %s',
            'check_ext_recommended' => 'Extensiones recomendadas',
            'check_ext_recommended_help' => 'Exment se instala sin ellas, pero algunas funciones (idiomas, números, cifrado) las usan. Actívelas en el panel del servidor si puede.',
            'check_memory' => 'Memoria (memory_limit)',
            'check_memory_need' => 'Se recomienda 256M o más (ahora %s)',
            'check_disk' => 'Espacio libre',
            'check_disk_need' => 'Se necesita %s (ahora %s)',
            'check_write' => 'Escritura',
            'check_write_need' => 'No se puede escribir en %s',
            'check_net' => 'Conexión con exment.net',
            'check_net_need' => 'Se necesita curl o allow_url_fopen',
            'check_manifest' => 'Versión',
            'check_manifest_bad' => 'No se pudo leer la versión de exment.net (%s)',
            'check_way' => 'Esta carpeta',
            'check_way_need' => 'Ya hay archivos: %s. Ejecútelo en una carpeta vacía (para actualizar Exment, use los pasos de actualización).',
            'check_exists' => 'Exment ya está instalado (%s). No se sobrescribe: use los pasos de actualización.',
            'check_ok' => 'OK',
            'check_retry' => 'Comprobar de nuevo',
            'check_next' => 'Descargar %s',
            'php_fix_xserver' => 'Xserver: panel del servidor → «PHP Ver.切替»: ponga el PHP de este dominio en %s.',
            'php_fix_sakura' => 'Sakura: panel de control → ajustes de scripts: ponga la versión de PHP en %s.',
            'php_fix_other' => 'Ponga la versión de PHP en %s en el panel del servidor.',
            'memory_fix_xserver' => 'Xserver: panel del servidor → «php.ini設定»: ponga memory_limit en 256M o más.',
            'memory_fix_sakura' => 'Sakura: panel de control → ajustes de scripts → php.ini: añada memory_limit = 256M.',
            'memory_fix_other' => 'Ponga memory_limit = 256M en php.ini (o .user.ini).',
            'download_lead' => 'Descargando Exment %s.',
            'download_done' => 'Descargado y su contenido (sha256) comprobado.',
            'download_bad_hash' => 'El archivo descargado no es la versión (sha256). Se eliminó. Inténtelo de nuevo.',
            'download_failed' => 'No se pudo descargar: %s',
            'extract_lead' => 'Descomprimiendo (%s / %s).',
            'extract_bad_entry' => 'El zip tiene un archivo para un lugar donde no puede escribir: %s',
            'place_lead' => 'Exment está en %s y sus archivos públicos en esta carpeta.',
            'place_inside' => '(El servidor solo permite esta carpeta: la aplicación está dentro, cerrada a la web.)',
            'place_link' => 'La carpeta pública de la aplicación es un enlace a esta carpeta.',
            'place_nolink' => 'No se pudo crear un enlace: index.php carga la aplicación desde su carpeta.',
            'db_lead' => 'Cree la base de datos. Después compruebe la conexión abajo y continúe.',
            'db_xserver' => array('Abra «MySQL設定» en el panel del servidor.', 'Añada una base de datos con «MySQL追加» (su nombre empieza por su ID de servidor y «_»).', 'Añada un usuario con «MySQLユーザ追加» y elija su contraseña.', 'En «MySQL一覧», añada el usuario a la base de datos (permisos).', 'El host es el nombre que aparece en «MySQL設定» (localhost o mysqlNNNN.xserver.jp).'),
            'db_sakura' => array('Abra «Bases de datos» en el panel de control.', 'Añada una base de datos (juego de caracteres utf8mb4).', 'El usuario es el nombre de su cuenta; la contraseña, la elegida al crear la base de datos.', 'El host es el «mysqlNNN.db.sakura.ne.jp» que aparece en la lista.'),
            'db_other' => array('Cree una base de datos MySQL (o MariaDB) y un usuario en el panel del servidor.', 'Use el juego de caracteres utf8mb4.', 'Anote el host, la base de datos, el usuario y la contraseña.'),
            'db_host' => 'Host',
            'db_port' => 'Puerto',
            'db_name' => 'Base de datos',
            'db_user' => 'Usuario',
            'db_pass' => 'Contraseña',
            'db_test' => 'Comprobar la conexión',
            'db_ok' => 'Conectado (%s). Introduzca estos valores en la página siguiente (el instalador de Exment).',
            'db_ng' => 'No se pudo conectar: %s',
            'db_note' => 'Los valores introducidos aquí no se guardan: solo se comprueban.',
            'db_not_empty' => 'Conectado, pero esta base de datos tiene %s tablas. Exment necesita una base de datos vacía. Compruebe que no es la de otro sitio y cree una nueva o vacíela.',
            'finish_button' => 'Ir al instalador de Exment',
            'finish_lead' => 'Esto elimina este instalador, su clave y sus archivos de trabajo, y abre el instalador de Exment.',
            'finish_left' => 'No se pudo eliminar este instalador. Elimine «exment-installer.php», «exment-installer-key.php» y «.exment-installer» por FTP.',
            'then_continue' => 'Después abra esta página de nuevo y pulse «Continuar».',
            'error' => 'Error: %s',
            'busy' => 'Otra página está trabajando en ello. Espere un poco y abra esta página de nuevo.',
            'progress' => '%s %%',
            'continue' => 'Continuar',
            'restart' => 'Empezar de nuevo',
            'restart_confirm' => 'La descarga y la descompresión empiezan de nuevo (se eliminan los archivos de Exment colocados). ¿Continuar?',
            'lang' => 'Español',
        ),
        'ko' => array(
            'title' => 'Exment 설치',
            'lead' => '이 서버에 Exment를 설치합니다. 순서대로 진행하십시오.',
            'step_key' => '키',
            'step_check' => '서버 확인',
            'step_download' => '다운로드',
            'step_extract' => '압축 해제',
            'step_place' => '배치',
            'step_db' => '데이터베이스',
            'step_finish' => '완료',
            'key_lead' => '설치 프로그램이 같은 폴더에 "exment-installer-key.php" 파일을 만들었습니다. FTP나 서버의 파일 관리자로 열어서, 안에 적힌 키를 입력하십시오(브라우저로 열면 아무것도 표시되지 않습니다).',
            'key_label' => '키',
            'key_button' => '다음',
            'key_wrong' => '키가 다릅니다.',
            'key_locked' => '잘못된 키가 여러 번 입력되어 약 10분 동안 멈췄습니다.',
            'key_cannot_write' => '이 폴더에 파일을 만들 수 없습니다. 폴더의 쓰기 권한을 확인하십시오.',
            'check_lead' => '서버를 확인했습니다.',
            'check_php' => 'PHP 버전',
            'check_php_need' => '%s 이상 %s 미만이 필요합니다(현재 %s)',
            'check_php_min' => '이 릴리스에는 PHP %s 이상이 필요합니다(현재 %s).',
            'check_ext' => 'PHP 확장',
            'check_ext_missing' => '부족한 확장: %s',
            'check_ext_recommended' => '있으면 좋은 확장',
            'check_ext_recommended_help' => '없어도 설치할 수 있지만, 일부 기능(다국어·계산·암호화 등)에 사용합니다. 서버 관리 화면에서 켤 수 있으면 켜십시오.',
            'check_memory' => '메모리(memory_limit)',
            'check_memory_need' => '256M 이상을 권장합니다(현재 %s)',
            'check_disk' => '여유 공간',
            'check_disk_need' => '%s 이상 필요합니다(현재 %s)',
            'check_write' => '쓰기',
            'check_write_need' => '%s에 쓸 수 없습니다',
            'check_net' => 'exment.net 연결',
            'check_net_need' => 'curl 또는 allow_url_fopen이 필요합니다',
            'check_manifest' => '릴리스 정보',
            'check_manifest_bad' => 'exment.net에서 릴리스 정보를 읽을 수 없었습니다(%s)',
            'check_way' => '이 폴더의 내용',
            'check_way_need' => '이미 파일이 있습니다: %s. 빈 폴더에서 실행하십시오(Exment가 이미 있을 때는 업데이트 절차를 사용하십시오).',
            'check_exists' => 'Exment가 이미 있습니다(%s). 덮어쓰지 않습니다. 업데이트 절차를 사용하십시오.',
            'check_ok' => 'OK',
            'check_retry' => '다시 확인',
            'check_next' => '%s 다운로드',
            'php_fix_xserver' => 'Xserver: 서버 패널 → "PHP Ver.切替"에서 이 도메인의 PHP를 %s로 하십시오.',
            'php_fix_sakura' => 'Sakura: 컨트롤 패널 → 스크립트 설정에서 PHP 버전을 %s로 하십시오.',
            'php_fix_other' => '서버 관리 화면에서 PHP 버전을 %s로 하십시오.',
            'memory_fix_xserver' => 'Xserver: 서버 패널 → "php.ini設定"에서 memory_limit를 256M 이상으로 하십시오.',
            'memory_fix_sakura' => 'Sakura: 컨트롤 패널 → 스크립트 설정 → php.ini에 memory_limit = 256M을 추가하십시오.',
            'memory_fix_other' => 'php.ini(또는 .user.ini)에서 memory_limit = 256M으로 하십시오.',
            'download_lead' => 'Exment %s을(를) 다운로드하고 있습니다.',
            'download_done' => '다운로드하고 내용(sha256)을 확인했습니다.',
            'download_bad_hash' => '다운로드한 파일의 내용이 다릅니다(sha256). 삭제했습니다. 다시 시도하십시오.',
            'download_failed' => '다운로드할 수 없었습니다: %s',
            'extract_lead' => '압축을 풀고 있습니다(%s / %s).',
            'extract_bad_entry' => 'zip 안에 쓸 수 없는 위치의 파일이 있었습니다: %s',
            'place_lead' => 'Exment를 %s에 두고, 공개 파일을 이 폴더에 두었습니다.',
            'place_inside' => '(서버 사정으로 이 폴더 안에 두고 웹에서 보이지 않게 했습니다)',
            'place_link' => '앱의 public 폴더는 이 폴더로의 링크로 했습니다.',
            'place_nolink' => '링크를 만들 수 없어서 index.php에서 앱을 읽도록 했습니다.',
            'db_lead' => '데이터베이스를 만드십시오. 만든 후 아래에서 연결을 확인하고 다음으로 진행합니다.',
            'db_xserver' => array('서버 패널의 "MySQL設定"을 엽니다.', '"MySQL追加"로 데이터베이스를 만듭니다(이름은 "서버ID_"로 시작합니다).', '"MySQLユーザ追加"로 사용자를 만들고 비밀번호를 정합니다.', '"MySQL一覧"에서 그 데이터베이스에 사용자를 "추가"합니다(접근 권한).', '호스트 이름은 "MySQL設定"에 표시된 이름(localhost 또는 mysqlNNNN.xserver.jp)입니다.'),
            'db_sakura' => array('컨트롤 패널의 "데이터베이스"를 엽니다.', '"신규 추가"로 데이터베이스를 만듭니다(문자 코드는 utf8mb4).', '사용자 이름은 계정 이름, 비밀번호는 데이터베이스를 만들 때 정한 것입니다.', '호스트 이름은 목록에 표시된 "mysqlNNN.db.sakura.ne.jp"입니다.'),
            'db_other' => array('서버 관리 화면에서 MySQL(또는 MariaDB) 데이터베이스와 사용자를 만듭니다.', '문자 코드는 utf8mb4로 합니다.', '호스트 이름·데이터베이스 이름·사용자 이름·비밀번호를 적어 둡니다.'),
            'db_host' => '호스트 이름',
            'db_port' => '포트',
            'db_name' => '데이터베이스 이름',
            'db_user' => '사용자 이름',
            'db_pass' => '비밀번호',
            'db_test' => '연결 확인',
            'db_ok' => '연결되었습니다(%s). 이 값을 다음 화면(Exment 설치 화면)에서 입력하십시오.',
            'db_ng' => '연결할 수 없었습니다: %s',
            'db_note' => '여기서 입력한 값은 저장하지 않습니다. 확인만 합니다.',
            'db_not_empty' => '연결되었지만 이 데이터베이스에는 테이블이 %s개 있습니다. Exment는 빈 데이터베이스에 설치합니다. 다른 사이트의 데이터베이스가 아닌지 확인하고, 새 데이터베이스를 만들거나 비우십시오.',
            'finish_button' => 'Exment 설치 화면으로 이동',
            'finish_lead' => '이 설치 프로그램·키·작업 파일을 삭제하고 Exment 설치 화면으로 이동합니다.',
            'finish_left' => '이 설치 프로그램을 삭제할 수 없었습니다. FTP 등으로 "exment-installer.php", "exment-installer-key.php", ".exment-installer"를 삭제하십시오.',
            'then_continue' => '변경한 후 이 페이지를 다시 열고 "계속"을 누르십시오.',
            'error' => '오류: %s',
            'busy' => '다른 화면에서 진행 중입니다. 잠시 후 다시 여십시오.',
            'progress' => '%s %%',
            'continue' => '계속',
            'restart' => '처음부터 다시',
            'restart_confirm' => '다운로드와 압축 해제를 처음부터 다시 합니다(배치한 Exment 파일도 삭제합니다). 계속하시겠습니까?',
            'lang' => '한국어',
        ),
        'vi' => array(
            'title' => 'Cài đặt Exment',
            'lead' => 'Công cụ này cài Exment lên máy chủ này. Hãy làm theo các bước theo thứ tự.',
            'step_key' => 'Khóa',
            'step_check' => 'Kiểm tra máy chủ',
            'step_download' => 'Tải xuống',
            'step_extract' => 'Giải nén',
            'step_place' => 'Đặt tệp',
            'step_db' => 'Cơ sở dữ liệu',
            'step_finish' => 'Hoàn tất',
            'key_lead' => 'Trình cài đặt đã tạo tệp "exment-installer-key.php" trong thư mục của nó. Hãy mở tệp bằng FTP hoặc trình quản lý tệp của máy chủ và nhập khóa ghi trong đó (mở bằng trình duyệt sẽ không hiện gì).',
            'key_label' => 'Khóa',
            'key_button' => 'Tiếp',
            'key_wrong' => 'Khóa không đúng.',
            'key_locked' => 'Nhập sai khóa quá nhiều lần: tạm dừng khoảng 10 phút.',
            'key_cannot_write' => 'Trình cài đặt không tạo được tệp trong thư mục này. Hãy kiểm tra quyền ghi của thư mục.',
            'check_lead' => 'Đã kiểm tra máy chủ.',
            'check_php' => 'Phiên bản PHP',
            'check_php_need' => 'Cần từ %s trở lên và dưới %s (hiện tại %s)',
            'check_php_min' => 'Bản phát hành này cần PHP %s trở lên (hiện tại %s).',
            'check_ext' => 'Phần mở rộng PHP',
            'check_ext_missing' => 'Còn thiếu: %s',
            'check_ext_recommended' => 'Phần mở rộng nên có',
            'check_ext_recommended_help' => 'Vẫn cài được khi thiếu, nhưng một số tính năng (đa ngôn ngữ, tính toán, mã hóa) dùng đến. Hãy bật trong trang quản lý máy chủ nếu có thể.',
            'check_memory' => 'Bộ nhớ (memory_limit)',
            'check_memory_need' => 'Nên từ 256M trở lên (hiện tại %s)',
            'check_disk' => 'Dung lượng trống',
            'check_disk_need' => 'Cần %s (hiện tại %s)',
            'check_write' => 'Ghi',
            'check_write_need' => 'Không ghi được vào %s',
            'check_net' => 'Kết nối đến exment.net',
            'check_net_need' => 'Cần curl hoặc allow_url_fopen',
            'check_manifest' => 'Bản phát hành',
            'check_manifest_bad' => 'Không đọc được thông tin bản phát hành từ exment.net (%s)',
            'check_way' => 'Thư mục này',
            'check_way_need' => 'Đã có tệp: %s. Hãy chạy trong thư mục trống (để cập nhật Exment, hãy dùng các bước cập nhật).',
            'check_exists' => 'Exment đã có sẵn (%s). Sẽ không ghi đè: hãy dùng các bước cập nhật.',
            'check_ok' => 'OK',
            'check_retry' => 'Kiểm tra lại',
            'check_next' => 'Tải xuống %s',
            'php_fix_xserver' => 'Xserver: bảng điều khiển → "PHP Ver.切替": đặt PHP của tên miền này thành %s.',
            'php_fix_sakura' => 'Sakura: bảng điều khiển → cài đặt script: đặt phiên bản PHP thành %s.',
            'php_fix_other' => 'Hãy đặt phiên bản PHP thành %s trong trang quản lý máy chủ.',
            'memory_fix_xserver' => 'Xserver: bảng điều khiển → "php.ini設定": đặt memory_limit từ 256M trở lên.',
            'memory_fix_sakura' => 'Sakura: bảng điều khiển → cài đặt script → php.ini: thêm memory_limit = 256M.',
            'memory_fix_other' => 'Đặt memory_limit = 256M trong php.ini (hoặc .user.ini).',
            'download_lead' => 'Đang tải Exment %s.',
            'download_done' => 'Đã tải xuống và kiểm tra nội dung (sha256).',
            'download_bad_hash' => 'Tệp tải xuống không đúng bản phát hành (sha256). Đã xóa. Hãy thử lại.',
            'download_failed' => 'Không tải xuống được: %s',
            'extract_lead' => 'Đang giải nén (%s / %s).',
            'extract_bad_entry' => 'Tệp zip có tệp ghi vào chỗ không được phép: %s',
            'place_lead' => 'Exment nằm ở %s, các tệp công khai nằm trong thư mục này.',
            'place_inside' => '(Máy chủ chỉ cho phép thư mục này: ứng dụng nằm bên trong, bị chặn truy cập từ web.)',
            'place_link' => 'Thư mục public của ứng dụng là liên kết đến thư mục này.',
            'place_nolink' => 'Không tạo được liên kết: index.php tải ứng dụng từ thư mục của nó.',
            'db_lead' => 'Hãy tạo cơ sở dữ liệu. Sau đó kiểm tra kết nối ở dưới rồi tiếp tục.',
            'db_xserver' => array('Mở "MySQL設定" trong bảng điều khiển máy chủ.', 'Thêm cơ sở dữ liệu bằng "MySQL追加" (tên bắt đầu bằng ID máy chủ và "_").', 'Thêm người dùng bằng "MySQLユーザ追加" và đặt mật khẩu.', 'Trong "MySQL一覧", thêm người dùng vào cơ sở dữ liệu (quyền truy cập).', 'Host là tên hiển thị trong "MySQL設定" (localhost hoặc mysqlNNNN.xserver.jp).'),
            'db_sakura' => array('Mở "Cơ sở dữ liệu" trong bảng điều khiển.', 'Thêm cơ sở dữ liệu (bộ ký tự utf8mb4).', 'Người dùng là tên tài khoản; mật khẩu là mật khẩu đặt khi tạo cơ sở dữ liệu.', 'Host là "mysqlNNN.db.sakura.ne.jp" hiển thị trong danh sách.'),
            'db_other' => array('Tạo cơ sở dữ liệu MySQL (hoặc MariaDB) và người dùng trong trang quản lý máy chủ.', 'Dùng bộ ký tự utf8mb4.', 'Ghi lại host, tên cơ sở dữ liệu, người dùng và mật khẩu.'),
            'db_host' => 'Host',
            'db_port' => 'Cổng',
            'db_name' => 'Cơ sở dữ liệu',
            'db_user' => 'Người dùng',
            'db_pass' => 'Mật khẩu',
            'db_test' => 'Kiểm tra kết nối',
            'db_ok' => 'Đã kết nối (%s). Hãy nhập các giá trị này ở trang tiếp theo (trình cài đặt của Exment).',
            'db_ng' => 'Không kết nối được: %s',
            'db_note' => 'Các giá trị nhập ở đây không được lưu, chỉ dùng để kiểm tra.',
            'db_not_empty' => 'Đã kết nối, nhưng cơ sở dữ liệu này có %s bảng. Exment cần cơ sở dữ liệu trống. Hãy chắc rằng đây không phải cơ sở dữ liệu của trang khác, rồi tạo mới hoặc làm trống.',
            'finish_button' => 'Đến trình cài đặt của Exment',
            'finish_lead' => 'Thao tác này xóa trình cài đặt, khóa và các tệp làm việc của nó, rồi mở trình cài đặt của Exment.',
            'finish_left' => 'Không xóa được trình cài đặt này. Hãy xóa "exment-installer.php", "exment-installer-key.php" và ".exment-installer" bằng FTP.',
            'then_continue' => 'Sau đó mở lại trang này và nhấn "Tiếp tục".',
            'error' => 'Lỗi: %s',
            'busy' => 'Một trang khác đang xử lý. Hãy đợi một chút rồi mở lại trang này.',
            'progress' => '%s %%',
            'continue' => 'Tiếp tục',
            'restart' => 'Làm lại từ đầu',
            'restart_confirm' => 'Tải xuống và giải nén sẽ làm lại từ đầu (các tệp Exment đã đặt sẽ bị xóa). Tiếp tục?',
            'lang' => 'Tiếng Việt',
        ),
        'zh_CN' => array(
            'title' => '安装 Exment',
            'lead' => '将 Exment 安装到此服务器。请按顺序进行。',
            'step_key' => '密钥',
            'step_check' => '服务器检查',
            'step_download' => '下载',
            'step_extract' => '解压',
            'step_place' => '放置',
            'step_db' => '数据库',
            'step_finish' => '完成',
            'key_lead' => '安装程序在同一文件夹中创建了“exment-installer-key.php”文件。请用 FTP 或服务器的文件管理器打开，输入其中写的密钥（用浏览器打开不会显示任何内容）。',
            'key_label' => '密钥',
            'key_button' => '下一步',
            'key_wrong' => '密钥不正确。',
            'key_locked' => '输入错误密钥次数过多，已暂停约 10 分钟。',
            'key_cannot_write' => '无法在此文件夹中创建文件。请确认文件夹的写入权限。',
            'check_lead' => '已检查服务器。',
            'check_php' => 'PHP 版本',
            'check_php_need' => '需要 %s 以上且低于 %s（当前 %s）',
            'check_php_min' => '此版本需要 PHP %s 以上（当前 %s）。',
            'check_ext' => 'PHP 扩展',
            'check_ext_missing' => '缺少的扩展：%s',
            'check_ext_recommended' => '建议的扩展',
            'check_ext_recommended_help' => '没有也可以安装，但部分功能（多语言、计算、加密等）会用到。如能在服务器管理界面中启用，请启用。',
            'check_memory' => '内存（memory_limit）',
            'check_memory_need' => '建议 256M 以上（当前 %s）',
            'check_disk' => '可用空间',
            'check_disk_need' => '需要 %s 以上（当前 %s）',
            'check_write' => '写入',
            'check_write_need' => '无法写入 %s',
            'check_net' => '连接 exment.net',
            'check_net_need' => '需要 curl 或 allow_url_fopen',
            'check_manifest' => '版本信息',
            'check_manifest_bad' => '无法从 exment.net 读取版本信息（%s）',
            'check_way' => '此文件夹的内容',
            'check_way_need' => '已有文件：%s。请在空文件夹中运行（已有 Exment 时，请使用更新步骤）。',
            'check_exists' => '已有 Exment（%s）。不会覆盖。请使用更新步骤。',
            'check_ok' => 'OK',
            'check_retry' => '重新检查',
            'check_next' => '下载 %s',
            'php_fix_xserver' => 'Xserver：服务器面板 →“PHP Ver.切替”，将此域名的 PHP 设为 %s。',
            'php_fix_sakura' => 'Sakura：控制面板 → 脚本设置，将 PHP 版本设为 %s。',
            'php_fix_other' => '请在服务器管理界面中将 PHP 版本设为 %s。',
            'memory_fix_xserver' => 'Xserver：服务器面板 →“php.ini設定”，将 memory_limit 设为 256M 以上。',
            'memory_fix_sakura' => 'Sakura：控制面板 → 脚本设置 → php.ini，添加 memory_limit = 256M。',
            'memory_fix_other' => '请在 php.ini（或 .user.ini）中设置 memory_limit = 256M。',
            'download_lead' => '正在下载 Exment %s。',
            'download_done' => '已下载并检查了内容（sha256）。',
            'download_bad_hash' => '下载的文件内容不正确（sha256），已删除。请重试。',
            'download_failed' => '无法下载：%s',
            'extract_lead' => '正在解压（%s / %s）。',
            'extract_bad_entry' => 'zip 中有写入不允许位置的文件：%s',
            'place_lead' => 'Exment 已放在 %s，公开文件放在此文件夹中。',
            'place_inside' => '（由于服务器限制，已放在此文件夹内，并禁止从 Web 访问）',
            'place_link' => '应用的 public 文件夹已设为指向此文件夹的链接。',
            'place_nolink' => '无法创建链接，已改为由 index.php 从应用文件夹读取。',
            'db_lead' => '请创建数据库。创建后，在下方确认连接再继续。',
            'db_xserver' => array('打开服务器面板的“MySQL設定”。', '用“MySQL追加”创建数据库（名称以“服务器ID_”开头）。', '用“MySQLユーザ追加”创建用户并设置密码。', '在“MySQL一覧”中将该用户“添加”到数据库（访问权限）。', '主机名是“MySQL設定”中显示的名称（localhost 或 mysqlNNNN.xserver.jp）。'),
            'db_sakura' => array('打开控制面板的“数据库”。', '用“新建”创建数据库（字符集 utf8mb4）。', '用户名是账户名，密码是创建数据库时设置的密码。', '主机名是列表中显示的“mysqlNNN.db.sakura.ne.jp”。'),
            'db_other' => array('在服务器管理界面中创建 MySQL（或 MariaDB）数据库和用户。', '字符集使用 utf8mb4。', '记下主机名、数据库名、用户名和密码。'),
            'db_host' => '主机名',
            'db_port' => '端口',
            'db_name' => '数据库名',
            'db_user' => '用户名',
            'db_pass' => '密码',
            'db_test' => '确认连接',
            'db_ok' => '已连接（%s）。请在下一个界面（Exment 的安装界面）中输入这些值。',
            'db_ng' => '无法连接：%s',
            'db_note' => '在此输入的值不会保存，仅用于确认。',
            'db_not_empty' => '已连接，但此数据库中有 %s 个表。Exment 需要空数据库。请确认这不是其他站点的数据库，然后新建一个数据库或将其清空。',
            'finish_button' => '前往 Exment 的安装界面',
            'finish_lead' => '将删除此安装程序、密钥和工作文件，并转到 Exment 的安装界面。',
            'finish_left' => '无法删除此安装程序。请用 FTP 等删除“exment-installer.php”“exment-installer-key.php”和“.exment-installer”。',
            'then_continue' => '修改后，请重新打开此页面并点击“继续”。',
            'error' => '错误：%s',
            'busy' => '其他页面正在处理。请稍等后重新打开此页面。',
            'progress' => '%s %%',
            'continue' => '继续',
            'restart' => '从头开始',
            'restart_confirm' => '将从头重新下载和解压（已放置的 Exment 文件也会删除）。确定吗？',
            'lang' => '简体中文',
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

function exi_langs()
{
    return array_keys(exi_texts());
}

/**
 * The language of the page: chosen (a cookie), else the first of the browser the installer has.
 */
function exi_lang()
{
    if (isset($_GET['lang']) && in_array($_GET['lang'], exi_langs(), true)) {
        setcookie('exi_lang', $_GET['lang'], 0, '', '', exi_https(), true);
        return $_GET['lang'];
    }
    if (isset($_COOKIE['exi_lang']) && in_array($_COOKIE['exi_lang'], exi_langs(), true)) {
        return $_COOKIE['exi_lang'];
    }
    return exi_accept_lang(isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '', exi_langs());
}

/**
 * The first language of an Accept-Language header the installer has ("zh-CN", "zh" → zh_CN), else en.
 */
function exi_accept_lang($header, array $langs)
{
    foreach (explode(',', strtolower((string)$header)) as $part) {
        $code = trim(explode(';', $part)[0]);
        $base = explode('-', $code)[0];
        if ($base === 'zh' && in_array('zh_CN', $langs, true)) {
            return 'zh_CN';
        }
        if ($base !== '' && in_array($base, $langs, true)) {
            return $base;
        }
    }
    return 'en';
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
    $texts = exi_texts();
    echo '<!doctype html><html lang="' . $lang . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex"><title>' . exi_h(exi_t('title')) . '</title><style>' . exi_css() . '</style></head><body><main>';
    echo '<header><h1>' . exi_h(exi_t('title')) . '</h1><nav class="langs">';
    foreach ($texts as $code => $words) {
        echo $code === $lang ? '<strong>' . exi_h($words['lang']) . '</strong>' : '<a href="' . $self . '?lang=' . exi_h($code) . '">' . exi_h($words['lang']) . '</a>';
    }
    echo '</nav></header>';
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
        . 'main{max-width:760px;margin:24px auto;padding:0 16px}header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px}.langs{display:flex;gap:8px;flex-wrap:wrap;font-size:13px}h1{font-size:22px}h2{font-size:17px;margin:0 0 8px}'
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
