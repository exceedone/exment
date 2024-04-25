<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;

class MailAttachment
{
    public function __construct(string $path, string $filename)
    {
        $this->path = $path;
        $this->filename = $filename;
    }

    /**
     * Fillpath to file
     *
     * @var string
     */
    public $path;

    /**
     * Sending file name
     *
     * @var string
     */
    public $filename;

    /**
     * Get file full path
     *
     * @return string|null
     */
    public function getFullPath(): ?string
    {
        return \Storage::disk(Define::DISKNAME_ADMIN)->path($this->path);
    }

    /**
     * Get file object
     */
    public function getFile()
    {
        return \Storage::disk(Define::DISKNAME_ADMIN)->get($this->path);
    }

    /**
     * Make instance
     *
     * @param File|array $attachment
     * @return MailAttachment|null
     */
    public static function make($attachment)
    {
        if ($attachment instanceof File) {
            return new MailAttachment($attachment->path, $attachment->filename);
        } elseif (is_array($attachment)) {
            return new MailAttachment(array_get($attachment, 'path'), array_get($attachment, 'filename'));
        }
    }
}
