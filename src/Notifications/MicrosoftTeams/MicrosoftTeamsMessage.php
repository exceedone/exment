<?php

namespace Exceedone\Exment\Notifications\MicrosoftTeams;

class MicrosoftTeamsMessage
{
    /**
     * The text content of the message.
     *
     * @var string
     */
    public $content;

    /**
     * The text title of the message.
     *
     * @var string
     */
    public $title;

    /**
     * Set the content of the Teams message.
     *
     * @param  string  $content
     * @return $this
     */
    public function content($content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * Set the title of the Teams message.
     *
     * @param  string  $title
     * @return $this
     */
    public function title($title)
    {
        $this->title = $title;

        return $this;
    }
}
