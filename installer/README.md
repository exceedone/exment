# The installer for rental servers

`exment-installer.php` puts Exment on a rental server (Xserver, Sakura and others) from the browser (ISS02961).

1. Upload `exment-installer.php` into the folder the site should be in (public_html, or a folder of it), and open it in the browser.
2. Enter the key: the installer writes it into `exment-installer-key.php` next to itself. Read that file by FTP or with the file manager of the server (opened in a browser, it shows nothing).
3. The installer checks PHP and the server, downloads the release of exment.net in pieces (its sha256 is checked), unpacks it in pieces, and places it:
   - the app outside the public folder when the folder above it can be written (`exment`, or `exment_<folder>`), else inside, closed to the web;
   - the public files in this folder, with `index.php` loading the app;
   - the `public` folder of the app as a link to this folder, when the server allows links.
4. It shows how to make the database on this server, and tests the connection (an empty database is needed).
5. It deletes itself, its key and its work files, and opens the installer of Exment (`/admin/install`).

It never writes over an Exment or a site already there. The release comes from `https://exment.net/downloads/{ja,en}/exment.json` (version, size, sha256, URL of the versioned zip).

Tests: `php installer/tests/run.php` (the pure parts: the server, the manifest, the entries of the zip, the folders, index.php, the texts).

For a local test, `exment-installer.local.php` next to the installer may return `['manifest' => 'http://127.0.0.1:8931/exment.json', 'allow_http' => true, 'release_host' => '127.0.0.1']`. It is deleted with the installer.
