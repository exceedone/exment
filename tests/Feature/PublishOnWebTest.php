<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\ExmentServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * The files to publish (vendor:publish --tag=...) are known also when Exment runs in a web
 * request: the web installer and the update screens call exment:publish there. Without them it
 * copied nothing and the site had no CSS / JS.
 */
class PublishOnWebTest extends FeatureTestBase
{
    protected const TAGS = ['public', 'views_vendor', 'open-admin-assets-exment', 'open-admin-lang-exment', 'lang_vendor'];

    protected function tearDown(): void
    {
        static::setConsole(null);
        parent::tearDown();
    }

    protected static function setConsole(?string $value): void
    {
        if (is_null($value)) {
            putenv('APP_RUNNING_IN_CONSOLE');
            unset($_ENV['APP_RUNNING_IN_CONSOLE'], $_SERVER['APP_RUNNING_IN_CONSOLE']);
            return;
        }
        putenv('APP_RUNNING_IN_CONSOLE=' . $value);
        $_ENV['APP_RUNNING_IN_CONSOLE'] = $_SERVER['APP_RUNNING_IN_CONSOLE'] = $value;
    }

    /** Forget what the providers registered before (the lists are static). */
    protected static function forgetPublishes(): void
    {
        foreach (['publishes', 'publishGroups'] as $name) {
            $property = new \ReflectionProperty(ServiceProvider::class, $name);
            $property->setAccessible(true);
            $value = $property->getValue();
            if ($name === 'publishes') {
                unset($value[ExmentServiceProvider::class]);
            } else {
                foreach (static::TAGS as $tag) {
                    unset($value[$tag]);
                }
            }
            $property->setValue(null, $value);
        }
    }

    public function testTheFilesToPublishAreKnownInAWebRequest(): void
    {
        static::setConsole('false');
        static::forgetPublishes();
        $this->refreshApplication();

        $this->assertFalse($this->app->runningInConsole(), 'a web request');
        foreach (static::TAGS as $tag) {
            $paths = ServiceProvider::pathsToPublish(ExmentServiceProvider::class, $tag);
            $this->assertNotEmpty($paths, $tag);
        }
        $this->assertContains(public_path(''), ServiceProvider::pathsToPublish(ExmentServiceProvider::class, 'public'));
        $this->assertContains(public_path('vendor/open-admin'), ServiceProvider::pathsToPublish(ExmentServiceProvider::class, 'open-admin-assets-exment'));
    }
}
