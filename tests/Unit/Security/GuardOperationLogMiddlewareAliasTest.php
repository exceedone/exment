<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Tests\TestCase;
use Exceedone\Exment\ExmentServiceProvider;
use Exceedone\Exment\Middleware\LogOperation;

/**
 * GUARD: the operation-log middleware must stay wired to Exment's class.
 *
 * Original bug: ExmentServiceProvider::register() aliases "admin.log" to
 * Exceedone\Exment\Middleware\LogOperation, which masks passwords, tokens and
 * sensitive url segments. The auto-discovered
 * ExmentAdminCore\Admin\AdminServiceProvider runs its own register() afterwards
 * and re-aliased "admin.log" back to the core class, whose only filtering is a
 * flat, top-level config lookup. Every request then wrote current_password,
 * _token, access_token, refresh_token, reset tokens and nested secrets to
 * admin_operation_log in plain text - and both the CSV export and the REST API
 * return that column verbatim.
 *
 * Laravel <= 10 hid the conflict: Application::getProvider() matched providers
 * by instanceof, so the core provider (a parent class of ExmentServiceProvider)
 * counted as already registered and never ran on its own. Laravel 11+ dedupes by
 * exact class name, so it does run - and wins, because it registers last.
 *
 * The fix re-aliases in ExmentServiceProvider::boot(), which runs after every
 * register(). This guard locks it in. It is needed because the masking rules are
 * unit-tested through LogOperation's static methods, which keep passing even
 * when no request is masked at all - the alias is the only thing that decides
 * whether masking runs, so only an assertion on the resolved alias catches a
 * repeat of this regression.
 */
class GuardOperationLogMiddlewareAliasTest extends TestCase
{
    /** Middleware groups that must keep logging (and therefore masking) enabled. */
    private const GROUPS_THAT_MUST_LOG = [
        'admin',
        'admin_anonymous',
        'adminapi',
        'adminapi_anonymous',
        'adminwebapi',
        'adminapioauth',
        'publicformapi',
    ];

    /**
     * @return array<string, mixed>
     */
    private function registeredAliases(): array
    {
        return app('router')->getMiddleware();
    }

    public function testAdminLogAliasResolvesToExmentLogOperation()
    {
        $this->assertSame(
            LogOperation::class,
            $this->registeredAliases()['admin.log'] ?? null,
            'The "admin.log" alias no longer points at Exment\'s LogOperation, so nothing in the '
            . 'operation log is masked. A provider registered after ExmentServiceProvider has '
            . 'overwritten the alias - re-apply it in ExmentServiceProvider::boot().'
        );
    }

    public function testResolvedLogMiddlewareStillMasks()
    {
        // guards against the alias being pointed at some other class that merely
        // extends the core one: masking must be reachable from what is wired in
        $resolved = $this->registeredAliases()['admin.log'] ?? null;
        $this->assertIsString($resolved);
        $this->assertTrue(
            method_exists($resolved, 'maskInputArray'),
            "Middleware {$resolved} is registered for \"admin.log\" but has no maskInputArray()."
        );
    }

    public function testAdminAuthAliasResolvesToExmentAuthenticate()
    {
        // the same override also reverted "admin.auth" to the core class, which
        // skips Exment's guard setup and the not-initialized -> /initialize redirect
        $this->assertSame(
            \Exceedone\Exment\Middleware\Authenticate::class,
            $this->registeredAliases()['admin.auth'] ?? null
        );
    }

    public function testEveryAliasDeclaredByExmentIsTheOneActuallyRegistered()
    {
        /** @var array<string, string> $declared */
        $declared = (new \ReflectionClass(ExmentServiceProvider::class))
            ->getDefaultProperties()['routeMiddleware'];
        $registered = $this->registeredAliases();

        foreach ($declared as $alias => $class) {
            $this->assertArrayHasKey($alias, $registered, "Alias \"{$alias}\" is not registered at all.");
            $this->assertSame(
                $class,
                $registered[$alias],
                "Alias \"{$alias}\" resolves to {$registered[$alias]} instead of the declared {$class}."
            );
        }
    }

    public function testNothingWiresTheCoreLogOperationDirectly()
    {
        // The assertions above pin the "admin.log" ALIAS. A group may also name a
        // middleware by class, which bypasses the alias entirely - and the core
        // class no longer masks anything at all since its filterInput() was
        // removed (see GuardCoreFilterInputRemovedTest), so any route wired that
        // way would write every credential to admin_operation_log verbatim.
        $core = ltrim(\ExmentAdminCore\Admin\Middleware\LogOperation::class, '\\');

        foreach ($this->registeredAliases() as $alias => $class) {
            $this->assertNotSame(
                $core,
                ltrim((string) $class, '\\'),
                "Alias \"{$alias}\" resolves to the core LogOperation, which does not mask."
            );
        }

        foreach (app('router')->getMiddlewareGroups() as $group => $middleware) {
            foreach ((array) $middleware as $entry) {
                if (!is_string($entry)) {
                    continue;
                }
                $this->assertNotSame(
                    $core,
                    ltrim($entry, '\\'),
                    "Middleware group \"{$group}\" wires the core LogOperation by class name, "
                    . 'bypassing the "admin.log" alias. Use the alias so Exment\'s masking runs.'
                );
            }
        }
    }

    public function testLoggingMiddlewareStaysInEveryGroupThatNeedsIt()
    {
        // dropping "admin.log" from a group disables the log - and its masking -
        // for every route in that group, without any test on the rules noticing
        $groups = app('router')->getMiddlewareGroups();

        foreach (self::GROUPS_THAT_MUST_LOG as $group) {
            $this->assertArrayHasKey($group, $groups, "Middleware group \"{$group}\" is missing.");
            $this->assertContains(
                'admin.log',
                $groups[$group],
                "Middleware group \"{$group}\" no longer includes \"admin.log\"."
            );
        }
    }
}
