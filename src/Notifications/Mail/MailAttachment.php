<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Model\File;

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
     * Make instance
     *
     * @param File|array $attachment
     * @return MailAttachment
     */
    public static function make($attachment)
    {
        if ($attachment instanceof File) {
            return new MailAttachment(\Storage::disk(config('admin.upload.disk'))->path($attachment->path), $attachment->filename);
        } elseif (is_array($attachment)) {
            return new MailAttachment(array_get($attachment, 'path'), array_get($attachment, 'filename'));
        }
        return null;
    }
}
