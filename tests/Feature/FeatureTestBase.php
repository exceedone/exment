<?php

namespace Exceedone\Exment\Tests\Feature;

use ExmentAdminCore\Admin\Admin;
use ExmentAdminCore\Admin\Form;
use ExmentAdminCore\Admin\Grid;
use Tests\TestCase;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * @method void beginDatabaseTransaction()
 */
abstract class FeatureTestBase extends TestCase
{
    use TestTrait;

    /**
     * Boot the testing helper traits.
     *
     * @return array<mixed>
     */
    protected function setUpTraits()
    {
        $uses = parent::setUpTraits();

        if (isset($uses[DatabaseTransactions::class])) {
            $this->beginDatabaseTransaction();
        }

        return $uses;
    }

    /**
     * admin-core keeps its callbacks, init hooks and page assets in static properties. Every test
     * boots a new application that registers Exment's admin callback once more, every admin request
     * runs all of them, and each run adds one more Grid and Form init hook, so the feature run grew
     * until it reached the 512 MB memory limit. Put them back to their defaults after each test, as
     * every request starts with them in production.
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        $statics = [
            Admin::class => ['bootingCallbacks', 'registeredCallbacks', 'bootedCallbacks', 'script', 'deferredScript', 'style', 'css', 'csslast', 'js', 'jslast', 'html', 'headerJs', 'minifyIgnoresCss', 'minifyIgnoresJs'],
            Grid::class => ['initCallbacks'],
            Form::class => ['initCallbacks'],
        ];
        foreach ($statics as $class => $names) {
            foreach ($names as $name) {
                if (property_exists($class, $name)) {
                    $property = new \ReflectionProperty($class, $name);
                    $property->setValue(null, $property->getDefaultValue());
                }
            }
        }
    }
}
