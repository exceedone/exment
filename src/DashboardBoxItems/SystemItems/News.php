<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Illuminate\Pagination\LengthAwarePaginator;
use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Model\Define;
use Encore\Admin\Widgets\Table as WidgetTable;

class News
{
    /**
     * WordPress Page Items
     *
     * @var array
     */
    protected $items = [];

    protected $outputApi = true;

    public function __construct(){
        if(config('exment.disabled_outside_api', false) === true){
            $this->outputApi = false;
            return;
        }

        $client = new \GuzzleHttp\Client();
        $response = $client->request('GET', Define::EXMENT_NEWS_API_URL, [
            'http_errors' => false,
            'query' => $this->getQuery()
        ]);

        $contents = $response->getBody()->getContents();
        if ($response->getStatusCode() != 200) {
            return null;
        }

        // get wordpress items
        $this->items = json_decode($contents, true);
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
        if(!$this->outputApi){
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
        if(!$this->outputApi){
            return exmtrans('error.disabled_outside_api');
        }

        // get table items
        $headers = [
            exmtrans('common.published_date'),
            trans('admin.title'),
        ];
        $bodies = [];
        
        foreach($this->items as $item){
            $date = \Carbon\Carbon::parse(array_get($item, 'date'))->format('Y-m-d');
            $link = array_get($item, 'link');
            $title = array_get($item, 'title.rendered');
            $bodies[] = [
                $date,
                "<a href='{$link}' target='_blank'>{$title}</a>",
            ];
        }

        $widgetTable = new WidgetTable($headers, $bodies);

        return $widgetTable->render() ?? null;
    }

    /**
     * Get wordpress query string
     *
     * @return array query string array
     */
    protected function getQuery(){
        $request = request();

        // get querystring
        $query = [
            'categories' => 6,
            'per_page' => 5,
            'page' => $request->get('page') ?? 1,
        ];

        return $query;
    }
}
