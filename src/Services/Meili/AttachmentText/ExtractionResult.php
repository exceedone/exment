<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

/** Immutable parser outcome. Parser failures are returned, never thrown to a caller. */
final class ExtractionResult
{
    public const READY = 'ready';
    public const UNSUPPORTED = 'unsupported';
    public const NO_EXTRACTABLE_TEXT = 'no_extractable_text';
    public const TOO_LARGE = 'too_large';
    public const MISSING_FILE = 'missing_file';
    public const FAILED = 'failed';
    public const PASSWORD_PROTECTED = 'password_protected';

    /**
     * @param array<string,int|string|bool> $metadata
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $text,
        public readonly string $parser,
        public readonly ?string $extension,
        public readonly ?string $mime,
        public readonly ?int $sizeBytes,
        public readonly ?string $sha256,
        public readonly int $charCount = 0,
        public readonly bool $truncated = false,
        public readonly ?string $errorCode = null,
        public readonly array $metadata = [],
    ) {
    }

    public function isReady(): bool
    {
        return $this->status === self::READY;
    }
}
