<?php

namespace Exceedone\Exment\Tests\Constraints;

use Laravel\BrowserKitTesting\Constraints\HasInElement as HasInElementBase;
use Symfony\Component\DomCrawler\Crawler;

class HasOuterElement extends HasInElementBase
{
    /**
     * Check if the source or text is found within the element in the given crawler.
     *
     * @param  \Symfony\Component\DomCrawler\Crawler|string  $crawler
     * @return bool
     */
    public function matches($crawler): bool
    {
        $elements = $this->crawler($crawler)->filter($this->element);

        $pattern = $this->getEscapedPattern($this->text);

        foreach ($elements as $element) {
            $element = new Crawler($element);

            if (preg_match("/$pattern/i", $element->outerHtml())) {
                return true;
            }
        }

        return false;
    }
}
