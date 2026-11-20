<?php

namespace Exceedone\Exment\Services\FileServer;

/**
 * File server: an operation that is not allowed or cannot be done.
 * The message is for the user; the status is the HTTP status to answer with.
 */
class FileServerException extends \RuntimeException
{
    protected int $status;

    public function __construct(string $message, int $status = 422)
    {
        parent::__construct($message);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function forbidden(): self
    {
        return new self(exmtrans('file_server.error_forbidden'), 403);
    }

    public static function notFound(): self
    {
        return new self(exmtrans('file_server.error_not_found'), 404);
    }
}
