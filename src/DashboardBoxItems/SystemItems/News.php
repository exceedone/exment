<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Encore\Admin\Widgets\Table as WidgetTable;
use Carbon\Carbon;

class News
{
    /**
     * WordPress Page Items
     *
     * @var array
     */
    protected $items = [];

    protected $outputApi = true;

    public function __construct()
    {
        if (!System::outside_api()) {
            $this->outputApi = false;
            return;
        }
    }

    /**
     * get header
     */
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    public function footer()
    {
        if (!$this->outputApi) {
            return null;
        }
        $link = Define::EXMENT_NEWS_LINK;
        $label = trans('admin.list');
        return "<div style='padding:8px;'><a href='{$link}' target='_blank'>{$label}</a></div>";
    }

    /**
     * get html body
     */
    public function body()
    {
        if (!$this->outputApi) {
            return exmtrans('error.disabled_outside_api');
        }

        $this->setItems();

        // get table items
        $headers = [
            exmtrans('common.published_date'),
            trans('admin.title'),
        ];
        $bodies = [];

        foreach ($this->items as $item) {
            $date = \Carbon\Carbon::parse(array_get($item, 'date'))->format(config('admin.date_format'));
            $link = array_get($item, 'link');
            $title = array_get($item, 'title.rendered');
            $bodies[] = [
                $date,
                "<a href='{$link}' target='_blank'>{$title}</a>",
            ];
        }

        $widgetTable = new WidgetTable($headers, $bodies);

        /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
        return $widgetTable->render() ?? null;
    }

    /**
     * Get WordPress query string
     *
     * @return array query string array
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function getQuery()
    {
        $request = request();

        // get querystring
        $query = [
            'categories' => 6,
            'per_page' => System::datalist_pager_count() ?? 5,
            'page' => $request->get('page') ?? 1,
        ];

        return $query;
    }

    /**
     * Set exment news items
     *
     * @return void|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    protected function setItems()
    {
        if (!\is_nullorempty($this->items)) {
            return;
        }

        try {
            // get update news from session
            $update_news = session()->get(Define::SYSTEM_KEY_SESSION_UPDATE_NEWS);

            // if already executed
            if (isset($update_news)) {
                $update_news = json_decode_ex($update_news, true);
                $update_time = array_get($update_news, 'update_time');
                if (isset($update_time)) {
                    $update_time = new Carbon($update_time);
                    if ($update_time->diffInMinutes(Carbon::now()) <= 60) {
                        $contents = array_get($update_news, 'contents');
                    }
                }
            }

            if (!isset($contents)) {
                // get update news from api
                $client = new \GuzzleHttp\Client();
                $response = $client->request('GET', Define::EXMENT_NEWS_API_URL, [
                    'http_errors' => false,
                    'query' => $this->getQuery(),
                    'timeout' => 3, // Response timeout
                    'connect_timeout' => 3, // Connection timeout
                ]);

                $contents = $response->getBody()->getContents();
                if ($response->getStatusCode() != 200) {
                    return null;
                }
                session()->put(Define::SYSTEM_KEY_SESSION_UPDATE_NEWS, json_encode([
                    'update_time' => Carbon::now()->toDateTimeString(),
                    'contents' => $contents
                ]));
            }

            // get wordpress items
            $this->items = json_decode_ex($contents, true);
        } catch (\Exception $ex) {
            \Log::error($ex);
        }
    }
}
