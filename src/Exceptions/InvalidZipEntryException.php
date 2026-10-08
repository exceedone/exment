<?php

namespace Exceedone\Exment\Exceptions;

/**
 * A zip file was refused because one of its entry names is not a plain relative path.
 *
 * The message carried here is already translated and safe to show: the offending entry
 * name itself is only written to the log, never put in the message.
 */
class InvalidZipEntryException extends \RuntimeException
{
    /**
     * Turn this exception into a response by itself.
     *
     * Laravel's exception handler calls render() when the exception defines it, so every
     * caller that does not catch this exception still answers with a readable error
     * instead of a blank 500. Callers that DO catch it keep their own, more precise
     * message; this is only the fallback.
     *
     * The console never reaches this method: the console kernel calls renderForConsole()
     * instead, so RestoreCommand catches the exception by hand.
     *
     * @param \Illuminate\Http\Request $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function render($request)
    {
        // laravel-admin modals post by ajax and read this exact shape
        if ($request->ajax() || $request->pjax() || $request->expectsJson()) {
            return getAjaxResponse([
                'result' => false,
                'toastr' => $this->getMessage(),
                'errors' => [],
            ]);
        }

        admin_toastr($this->getMessage(), 'error');

        return back();
    }
}
