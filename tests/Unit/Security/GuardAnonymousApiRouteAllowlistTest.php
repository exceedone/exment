<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * GUARD (bug class 3/3 of commit b26a6dd): an anonymous state-changing route without an authz check.
 *
 * Original bug: route `template/delete` (DELETE, deletes a template directory) lived in the
 *   `adminapi_anonymous` middleware group (NO auth). The b26a6dd fix added a
 *   System::initialized()+hasPermission(SYSTEM) guard inside TemplateController::delete().
 *
 * The `adminapi_anonymous` group (registered in RouteServiceProvider::mapExmentAnonymousApiRotes)
 * is exactly where a sensitive endpoint can be added by accident without auth. This guard:
 *   (1) LOCKS the set of routes registered in that method to a reviewed allowlist;
 *       adding a new route -> test FAILS -> forces a security review (add a guard / don't leave write-delete anonymous).
 *   (2) LOCKS the permission guard in TemplateController::delete() so it cannot be removed.
 *
 * (Other anonymous routes such as login/logout/forget/reset/initialize/install/OAuth/SAML are
 *  legitimately anonymous by design - they live in other methods - so they are out of scope here.)
 */
class GuardAnonymousApiRouteAllowlistTest extends CodebaseGuardTestCase
{
    /** Routes allowed to exist in mapExmentAnonymousApiRotes (reviewed). Format: "VERB uri". */
    private const ALLOWED_ANON_API_ROUTES = [
        'POST template/search',   // guarded by System::initialized()+hasPermission(SYSTEM) in the controller (JVN#20312919); anonymous only during the install wizard
        'DELETE template/delete', // guarded by System::initialized()+hasPermission(SYSTEM) in the controller
    ];

    private function routeProviderSrc(): string
    {
        $path = self::srcDir() . '/Providers/RouteServiceProvider.php';
        $this->assertFileExists($path);
        return self::stripComments((string) file_get_contents($path));
    }

    /** Extract the body of mapExmentAnonymousApiRotes (up to the next method). */
    private function anonApiMethodBody(): string
    {
        $src = $this->routeProviderSrc();
        $start = strpos($src, 'function mapExmentAnonymousApiRotes');
        $this->assertNotFalse($start, 'mapExmentAnonymousApiRotes not found.');

        $rest = substr($src, $start);
        // up to the next function declaration
        if (preg_match('/\n\s*(?:protected|private|public)\s+function\s/', $rest, $m, PREG_OFFSET_CAPTURE, 20)) {
            return substr($rest, 0, $m[0][1]);
        }
        return $rest;
    }

    public function test_anonymous_api_routes_match_reviewed_allowlist(): void
    {
        $body = $this->anonApiMethodBody();

        preg_match_all('/\$router->(\w+)\(\s*[\'"]([^\'"]+)[\'"]/', $body, $matches, PREG_SET_ORDER);

        $registered = [];
        foreach ($matches as $m) {
            $registered[] = strtoupper($m[1]) . ' ' . $m[2];
        }

        $this->assertNotEmpty($registered, 'No route was extracted - check the parser.');

        $unexpected = array_values(array_diff($registered, self::ALLOWED_ANON_API_ROUTES));
        $this->assertSame(
            [],
            $unexpected,
            "New route(s) in the anonymous adminapi_anonymous group (no auth) that were not reviewed:\n - "
            . implode("\n - ", $unexpected)
            . "\nIf the endpoint changes state: add a permission guard in the controller (see TemplateController::delete),"
            . " then add it to ALLOWED_ANON_API_ROUTES."
        );
    }

    public function test_template_delete_keeps_its_authorization_guard(): void
    {
        $path = self::srcDir() . '/Controllers/TemplateController.php';
        $this->assertFileExists($path);
        $src = self::stripComments((string) file_get_contents($path));

        // Grab the body of delete()
        $start = strpos($src, 'function delete(');
        $this->assertNotFalse($start, 'TemplateController::delete() not found.');
        $body = substr($src, $start, 1200);

        $this->assertMatchesRegularExpression(
            '/System::initialized\(\).*?hasPermission\(Permission::SYSTEM\).*?abort\(403\)/s',
            $body,
            'TemplateController::delete() MUST keep the SYSTEM permission guard (do not remove the b26a6dd fix).'
        );
    }

    public function test_template_search_keeps_its_authorization_guard(): void
    {
        $path = self::srcDir() . '/Controllers/TemplateController.php';
        $this->assertFileExists($path);
        $src = self::stripComments((string) file_get_contents($path));

        // Grab the body of searchTemplate()
        $start = strpos($src, 'function searchTemplate(');
        $this->assertNotFalse($start, 'TemplateController::searchTemplate() not found.');
        $body = substr($src, $start, 1200);

        $this->assertMatchesRegularExpression(
            '/System::initialized\(\).*?hasPermission\(Permission::SYSTEM\).*?abort\(403\)/s',
            $body,
            'TemplateController::searchTemplate() MUST keep the SYSTEM permission guard (JVN#20312919: unauthenticated template enumeration).'
        );
    }
}
