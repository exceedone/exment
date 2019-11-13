<?php

namespace Exceedone\Exment\Middleware;

use RenatoMarinho\LaravelPageSpeed\Middleware\PageSpeed;

class InlineJsRemoveComments extends PageSpeed
{
    private $html = '';
    private $class = [];
    private $style = [];
    private $inline = [];

    public function apply($buffer)
    {
        $this->html = $buffer;

        preg_match_all(
            '/(?P<head>\<script[\s\S]*?\>)(?P<body>[\s\S]*?)<\/script>/',
            $this->html,
            $matches
        );

        foreach ($matches['body'] as $index => $body) {
            if (empty($body)) {
                continue;
            }

            $bodies = explodeBreak($body);

            $newbodies = [];
            foreach ($bodies as $line) {
                $l = trim($line);
                if (strpos($l, '//') === 0) {
                    continue;
                }
                $newbodies[] = $line;
            }

            $replace = $matches['head'][$index] . implode("", $newbodies) . '</script>';

            $this->html = str_replace($matches[0][$index], $replace, $this->html);
        }

        return $this->html;
    }

    private function injectStyle()
    {
        collect($this->class)->each(function ($attributes, $class) {
            $this->inline[] = ".{$class}{ {$attributes} }";

            $this->style[] = [
                'class' => $class,
                'attributes' => preg_quote($attributes, '/')];
        });

        $injectStyle = implode(' ', $this->inline);

        $replace = [
            '#</head>(.*?)#' => "\n<style>{$injectStyle}</style>\n</head>"
        ];

        $this->html = $this->replace($replace, $this->html);

        return $this;
    }

    private function injectClass()
    {
        collect($this->style)->each(function ($item) {
            $replace = [
                '/style="'.$item['attributes'].'"/' => "class=\"{$item['class']}\"",
            ];

            $this->html = $this->replace($replace, $this->html);
        });

        return $this;
    }

    private function fixHTML()
    {
        $newHTML = [];
        $tmp = explode('<', $this->html);

        $replaceClass = [
            '/class="(.*?)"/' => "",
        ];

        foreach ($tmp as $value) {
            preg_match_all('/class="(.*?)"/', $value, $matches);

            if (count($matches[1]) > 1) {
                $replace = [
                    '/>/' => "class=\"".implode(' ', $matches[1])."\">",
                ];

                $newHTML[] = str_replace(
                    '  ',
                    ' ',
                    $this->replace($replace, $this->replace($replaceClass, $value))
                );
            } else {
                $newHTML[] = $value;
            }
        }

        $this->html = implode('<', $newHTML);

        return $this;
    }
}
