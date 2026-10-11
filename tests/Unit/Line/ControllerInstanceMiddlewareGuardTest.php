<?php

namespace Exceedone\Exment\Tests\Unit\Line;

use PHPUnit\Framework\TestCase;

/**
 * GUARD test — born from the Laravel 12 port review of the LINE feature.
 *
 * WHY THIS TEST EXISTS
 * --------------------
 * Exment controllers extend App\Http\Controllers\Controller of the HOST app. On the
 * Laravel 11/12 skeleton (`composer create-project "laravel/laravel=12.*"`, as the
 * ReadMe instructs) that class is an EMPTY abstract class: it no longer extends
 * Illuminate\Routing\Controller, so `$this->middleware(...)` does not exist. Laravel 12
 * still instantiates the controller on dispatch, so a constructor that calls
 * `$this->middleware()` throws
 *   Error: Call to undefined method ...::middleware()
 * and every route of that controller answers HTTP 500. The test app in this repo kept
 * the Laravel 10 base controller, which is why the feature suites did not catch it.
 *
 * Rule: Exment controllers must not use instance middleware(). Gate inside the action
 * (see SafetyCheckController::authorizeSystemAdmin()) or with a named route middleware.
 * Do NOT switch to Illuminate\Routing\Controllers\HasMiddleware either: its static
 * middleware() collides with the instance method on Laravel 10-style base controllers.
 */
class ControllerInstanceMiddlewareGuardTest extends TestCase
{
    public function testNoExmentControllerCallsInstanceMiddleware(): void
    {
        $dir = dirname(__DIR__, 3) . '/src/Controllers';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        $offenders = [];

        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            // php_strip_whitespace() drops comments, so a mention in a docblock does not count.
            $code = php_strip_whitespace($file->getPathname());
            if (preg_match('/\$this->middleware\s*\(/', $code)) {
                $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($dir) + 1));
            }
        }

        sort($offenders);
        $this->assertSame([], $offenders, "Controllers calling \$this->middleware() (fatal on the Laravel 11/12 skeleton base controller):\n - " . implode("\n - ", $offenders));
    }
}
