<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Middleware\KeepAdminMessageOnRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Session\Store;
use Illuminate\Support\MessageBag;

/**
 * KeepAdminMessageOnRedirect: admin_info(), admin_success(), admin_warning() and admin_error()
 * keep their message for the current request only (session()->now()). When the answer is a
 * redirect, the middleware keeps the message for the page the browser opens next, and only for
 * that page, as the flash of v6 did.
 *
 * Each request runs the middleware and then saves the session, as StartSession does. The next
 * request reads the session back from the same session handler. These tests do not touch the database.
 */
class KeepAdminMessageOnRedirectTest extends UnitTestBase
{
    public function testAMessageBeforeARedirectIsShownOnTheNextPageOnly(): void
    {
        $session = $this->currentSession();
        admin_error('title', 'the page expired');
        $this->runRequest($session, new RedirectResponse('http://localhost/next'));

        $next = $this->nextRequest($session);
        $this->assertSame('the page expired', $this->messageOf($next, 'error'));
        $this->runRequest($next, new Response('the page that shows the message'));

        $this->assertNull($this->nextRequest($next)->get('error'));
    }

    public function testAMessageOfAPageDrawnInTheSameRequestIsNotShownAgain(): void
    {
        $session = $this->currentSession();
        admin_warning('title', 'shown on this page');
        $this->runRequest($session, new Response('the page that shows the message'));

        $this->assertNull($this->nextRequest($session)->get('warning'));
    }

    public function testEveryKindOfMessageIsKeptButNotOtherData(): void
    {
        $session = $this->currentSession();
        admin_info('title', 'info message');
        admin_success('title', 'success message');
        admin_warning('title', 'warning message');
        admin_error('title', 'error message');
        $session->now('other', 'not a message of admin_info()');
        $this->runRequest($session, new RedirectResponse('http://localhost/next'));

        $next = $this->nextRequest($session);
        foreach (['info', 'success', 'warning', 'error'] as $type) {
            $this->assertSame("$type message", $this->messageOf($next, $type));
        }
        $this->assertNull($next->get('other'));
    }

    public function testARequestOfAScriptBeforeTheNextPageDoesNotUseUpTheMessage(): void
    {
        $scripts = [
            'ajax' => ['X-Requested-With' => 'XMLHttpRequest'],
            'json' => ['Accept' => 'application/json'],
            'ajax and json' => ['X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'application/json'],
        ];
        foreach ($scripts as $name => $headers) {
            $session = $this->currentSession();
            admin_error('title', 'the page expired');
            $this->runRequest($session, new RedirectResponse('http://localhost/next'));

            // scripts of the page ask before the browser opens the next page
            $script = $this->nextRequest($session);
            $this->runRequest($script, new Response('{"count":0}'), $headers);
            $script = $this->nextRequest($script);
            $this->runRequest($script, new Response('{"count":0}'), $headers);

            $next = $this->nextRequest($script);
            $this->assertSame('the page expired', $this->messageOf($next, 'error'), $name);
            $this->runRequest($next, new Response('the page that shows the message'));

            $this->assertNull($this->nextRequest($next)->get('error'), $name);
        }
    }

    public function testAPjaxRequestShowsTheMessageOnlyOnce(): void
    {
        $session = $this->currentSession();
        admin_error('title', 'the page expired');
        $this->runRequest($session, new RedirectResponse('http://localhost/next'));

        $next = $this->nextRequest($session);
        $this->assertSame('the page expired', $this->messageOf($next, 'error'));
        $this->runRequest($next, new Response('the page drawn by pjax'), ['X-PJAX' => 'true', 'X-Requested-With' => 'XMLHttpRequest']);

        $this->assertNull($this->nextRequest($next)->get('error'));
    }

    public function testAMessageARequestOfAScriptWritesIsNotKept(): void
    {
        $session = $this->currentSession();
        $request = Request::create('http://localhost/', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setLaravelSession($session);
        (new KeepAdminMessageOnRedirect())->handle($request, function () {
            admin_error('title', 'for this answer only');
            return new Response('{"result":false}');
        });
        $session->save();

        $this->assertNull($this->nextRequest($session)->get('error'));
    }

    public function testAMessageARequestOfAScriptReplacesIsNotKept(): void
    {
        $session = $this->currentSession();
        admin_error('title', 'the page expired');
        $this->runRequest($session, new RedirectResponse('http://localhost/next'));

        $script = $this->nextRequest($session);
        $request = Request::create('http://localhost/', 'GET', [], [], [], ['HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
        $request->setLaravelSession($script);
        (new KeepAdminMessageOnRedirect())->handle($request, function () use ($script) {
            $script->now('error', new MessageBag(['title' => 'title', 'message' => 'for this answer only']));
            return new Response('{"result":false}');
        });
        $script->save();

        $this->assertNull($this->nextRequest($script)->get('error'));
    }

    /**
     * The session of the current request: the one admin_info() writes to.
     */
    protected function currentSession(): Store
    {
        $session = app('session')->driver();
        $this->assertInstanceOf(Store::class, $session);
        $session->start();

        return $session;
    }

    /**
     * Run the middleware for a request that answers $response, then save the session as StartSession does.
     *
     * @param array<string, string> $headers the headers of the request
     */
    protected function runRequest(Store $session, Response|RedirectResponse $response, array $headers = []): void
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }
        $request = Request::create('http://localhost/', 'GET', [], [], [], $server);
        $request->setLaravelSession($session);

        $answer = (new KeepAdminMessageOnRedirect())->handle($request, function () use ($response) {
            return $response;
        });
        $this->assertSame($response, $answer);

        $session->save();
    }

    /**
     * The session of the next request of the same browser, read back from the session handler.
     */
    protected function nextRequest(Store $session): Store
    {
        $next = new Store($session->getName(), $session->getHandler(), $session->getId());
        $next->start();

        return $next;
    }

    protected function messageOf(Store $session, string $type): ?string
    {
        $bag = $session->get($type);

        return $bag instanceof MessageBag ? $bag->first('message') : null;
    }
}
