<?php

namespace Exceedone\Exment\Tests\Constraints;

use Symfony\Component\DomCrawler\Crawler;
use Laravel\BrowserKitTesting\Constraints\PageConstraint;

class ExactSelectOption extends PageConstraint
{
    /**
     * The name or ID of the element.
     *
     * @var string
     */
    protected $element;

    /**
     * Select options.
     * key: option's value, value: text.
     *
     * @var array
     */
    protected $options;

    /**
     * Real select options.
     * key: option's value, value: text.
     *
     * @var array
     */
    protected $realOptions;

    /**
     * Create a new constraint instance.
     *
     * @param  string  $element
     * @param  array  $options
     * @return void
     */
    public function __construct($element, array $options)
    {
        $this->options = $options;
        $this->element = $element;
    }

    /**
     * Check if the source or text is found within the element in the given crawler.
     *
     * @param  \Symfony\Component\DomCrawler\Crawler|string  $crawler
     * @return bool
     */
    public function matches($crawler): bool
    {
        $elements = $this->crawler($crawler)->filter($this->element);

        foreach ($elements as $element) {
            $element = new Crawler($element);
            if($element->nodeName() != 'select'){
                return false;
            }

            $this->realOptions = $this->getOptionsItemFromSelect($element);

            // check expect options
            $checkFunc = function($arr1, $arr2) : bool{
                foreach($arr1 as $arrKey => $arrValue){
                    if(!collect($arr2)->contains(function($v, $k) use($arrKey, $arrValue){
                        return isMatchString($arrKey, $k) && isMatchString($arrValue, $v);
                    })){
                        return false;
                    };
                    return true;
                }
            };

            if(!$this->test2Array()){
                return false;
            }
        }

        return true;
    }


    /**
     * test 2 array result.
     *
     * @return bool
     */
    protected function test2Array() : bool
    {
        return $this->contains2Array($this->options, $this->realOptions) && $this->contains2Array($this->realOptions, $this->options);
    }


    /**
     * Contains 2 array
     *
     * @param array $testArr1
     * @param array $targetArr2
     * @return boolean
     */
    protected function contains2Array($testArr1, $targetArr2) : bool
    {
        foreach($testArr1 as $arrKey => $arrValue){
            if(!collect($targetArr2)->contains(function($v, $k) use($arrKey, $arrValue){
                return isMatchString($arrKey, $k) && isMatchString($arrValue, $v);
            })){
                return false;
            };
            return true;
        }
    }

    /**
     * Get the options value from a select field.
     *
     * @param  \Symfony\Component\DomCrawler\Crawler  $select
     * @return array
     */
    protected function getOptionsItemFromSelect(Crawler $select)
    {
        $options = [];

        foreach ($select->children() as $option) {
            if ($option->nodeName === 'optgroup') {
                foreach ($option->childNodes as $child) {
                    $options[$child->getAttribute('value')] = $child->textContent;
                }
            } else {
                $options[$option->getAttribute('value')] = $option->textContent;
            }
        }

        $options = collect($options)->filter(function($s, $v){
            return !is_nullorempty($v);
        })->toArray();

        return $options;
    }


    /**
     * Returns the description of the failure.
     *
     * @return string
     */
    protected function getFailureDescription()
    {
        return sprintf('[%s] exacts options %s, real option is %s', $this->element, json_encode($this->options), json_encode($this->realOptions));
    }

    /**
     * Returns the reversed description of the failure.
     *
     * @return string
     */
    protected function getReverseFailureDescription()
    {
        return sprintf('[%s] does not exact options %s, real option is %s', $this->element, json_encode($this->options), json_encode($this->realOptions));
    }
}
