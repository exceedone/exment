<?php

/**
 * Provision the Hybrid storage P1 test fixture (Tests\Support\HybridP1Fixture) on a TEST database.
 *
 * Run from the application root, after `php artisan exment:inittest`:
 *   php packages/exceedone/exment/tests/Support/provision-hybrid-p1-fixture.php --yes
 *
 * Refuses unless APP_ENV is "testing" and --yes is given. It creates a custom table (with index
 * DDL) and a workflow; never run it against a shared or production database.
 */

if (!in_array('--yes', $argv, true)) {
    fwrite(STDERR, "Add --yes to provision the fixture on the configured TEST database.\n");
    exit(1);
}

$root = getcwd();
if (!is_file($root . '/vendor/autoload.php') || !is_file($root . '/bootstrap/app.php')) {
    fwrite(STDERR, "Run this from the application root.\n");
    exit(1);
}
require $root . '/vendor/autoload.php';
$app = require $root . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (!app()->environment('testing')) {
    fwrite(STDERR, "APP_ENV is not testing - refusing.\n");
    exit(1);
}

\Exceedone\Exment\Middleware\Morph::defineMorphMap();
\Auth::guard('admin')->setUser(\Exceedone\Exment\Model\LoginUser::find(1));

foreach (\Exceedone\Exment\Tests\Support\HybridP1Fixture::provision() as $line) {
    echo $line, "\n";
}
