<?php

namespace Exceedone\Exment\DashboardBoxItems\SystemItems;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use ExmentAdminCore\Admin\Widgets\Table as WidgetTable;
use Carbon\Carbon;

class News
{
    /** When the news could not be read (the session): not asked again for RETRY_SECONDS. */
    public const SESSION_KEY_FAILED = 'exment_update_news_failed';
    public const RETRY_SECONDS = 600;

    /** The news could not be read in this request. */
    protected bool $unreachable = false;

    /**
     * WordPress Page Items
     *
     * @var array
     */
    // @phpstan-ignore-next-line
    protected $items = [];

    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function header()
    {
        return null;
    }

    /**
     * get footer
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function body()
    {
        if (!$this->outputApi) {
            return exmtrans('error.disabled_outside_api');
        }

        $this->setItems();
        if ($this->unreachable) {
            return esc_html(exmtrans('error.news_unreachable'));
        }

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

        // @phpstan-ignore-next-line
        return $widgetTable->render() ?? null;
    }

    /**
     * Get WordPress query string
     *
     * @return array query string array
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    // @phpstan-ignore-next-line
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
     * Pick the news of a page: the posts of the category, newest first.
     * The old WordPress API did this with the query; the static file has every post.
     *
     * @param array<mixed> $items the posts (WordPress REST API format)
     * @param int $per_page
     * @param int $page 1 or more
     * @param int|null $category only the posts of this category. null: all
     * @return array<int, array<mixed>>
     */
    public static function filterItems(array $items, int $per_page, int $page = 1, ?int $category = 6): array
    {
        $items = array_values(array_filter($items, function ($item) use ($category) {
            if (!is_array($item) || !isset($item['date'])) {
                return false;
            }
            if (isset($category) && isset($item['categories']) && is_array($item['categories'])) {
                return in_array($category, $item['categories']);
            }
            return true;
        }));

        usort($items, function ($a, $b) {
            return strcmp((string)$b['date'], (string)$a['date']);
        });

        $per_page = max(1, $per_page);
        $page = max(1, $page);
        return array_slice($items, ($page - 1) * $per_page, $per_page);
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
                // @phpstan-ignore-next-line
                $update_time = array_get($update_news, 'update_time');
                if (isset($update_time)) {
                    $update_time = new Carbon($update_time);
                    if ($update_time->diffInMinutes(Carbon::now()) <= 60) {
                        // @phpstan-ignore-next-line
                        $contents = array_get($update_news, 'contents');
                    }
                }
            }

            // could not be read a short while ago: not asked again yet (each try waits for the timeout)
            $failed = session()->get(static::SESSION_KEY_FAILED);
            if (!isset($contents) && is_numeric($failed) && time() - intval($failed) < static::RETRY_SECONDS) {
                $this->unreachable = true;
                return;
            }

            if (!isset($contents)) {
                // get update news from api
                // from the container: the same client, and a test can give its own
                $client = app(\GuzzleHttp\Client::class);
                $response = $client->request('GET', Define::EXMENT_NEWS_API_URL, [
                    'http_errors' => false,
                    'query' => $this->getQuery(),
                    'timeout' => 3, // Response timeout
                    'connect_timeout' => 3, // Connection timeout
                ]);

                $contents = $response->getBody()->getContents();
                if ($response->getStatusCode() != 200) {
                    $this->couldNotRead('HTTP ' . $response->getStatusCode());
                    return;
                }
                session()->put(Define::SYSTEM_KEY_SESSION_UPDATE_NEWS, json_encode([
                    'update_time' => Carbon::now()->toDateTimeString(),
                    'contents' => $contents
                ]));
            }

            // get the news items. The file has every post, so pick the page here.
            $items = json_decode_ex($contents, true);
            $query = $this->getQuery();
            $this->items = static::filterItems(is_array($items) ? $items : [], (int)$query['per_page'], (int)$query['page'], (int)$query['categories']);
        } catch (\Exception $ex) {
            // the server cannot reach exment.net (a closed network, DNS): one line in the log, not a stack trace each time
            $this->couldNotRead(get_class($ex) . ': ' . strtok($ex->getMessage(), "
"));
        }
    }

    /**
     * The news could not be read: the box says so, the session does not ask again for a while,
     * and the log gets one warning line an hour.
     */
    protected function couldNotRead(string $reason): void
    {
        $this->unreachable = true;
        $this->items = [];
        try {
            session()->put(static::SESSION_KEY_FAILED, time());
        } catch (\Throwable $e) {
            // no session: asked again next time
        }
        try {
            if (!\Illuminate\Support\Facades\Cache::add('exment_news_unreachable_logged', 1, 3600)) {
                return;
            }
        } catch (\Throwable $e) {
            // no cache: logged
        }
        \Log::warning('[Exment] the news of exment.net could not be read: ' . mb_substr($reason, 0, 300));
    }
}
