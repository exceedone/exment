<?php

namespace Exceedone\Exment\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Encore\Admin\Controllers\AdminController;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use App\Http\Controllers\Controller;
use Encore\Admin\Layout\Content;
use Encore\Admin\Grid;
use Encore\Admin\Show;
use Encore\Admin\Form;
use Encore\Admin\Grid\ArrayDataSource;

class PluginMarketController extends AdminController
{
    protected $repoUrl = 'http://test.local/api/mock/plugin-repo';

    
    protected $title = 'Plugin Market';

    protected function grid()
    {
        // Gọi API repo để lấy plugin list
        $response = \Http::get($this->repoUrl);
        $data = $response->json() ?? [];

        // Grid với dữ liệu từ API
        $grid = new Grid(new ArrayDataSource(collect($data)));

        $grid->column('id', 'ID');
        $grid->column('name', 'Tên plugin');
        $grid->column('version', 'Phiên bản');
        $grid->column('description', 'Mô tả');

        return $grid;
    }
    public function index(Content $content)
    {
        $response = \Http::get($this->repoUrl);
        $plugins = $response->json();

        return $content
            ->title('Plugin Market')
            ->body(view('exment::plugin.market.index', compact('plugins'))
        );
    }

    protected function detail($id)
    {
        $show = new Show(Plugin::findOrFail($id));

        $show->field('id', __('ID'));
        $show->field('name', __('Name plugin'));
        $show->field('version', __('Version'));
        $show->field('description', __('Description'));

        return $show;
    }

    protected function form()
    {
        $form = new Form(new Plugin());

        $form->text('name', __('Name plugin'));
        $form->text('version', __('Version'));
        $form->textarea('description', __('Description'));

        return $form;
    }
    /**
     * Cài đặt plugin từ repo
     */
    public function install(Request $request, $id)
    {
        try {
            $license = $request->input('license_key');

            $response = Http::timeout(30)->post("http://test1.local/api/plugin/validate-license", [
                'plugin_id' => $id,
                'license_key' => $license,
                'user_id' => auth()->id(),
            ]);

            if ($response->failed()) {
                return response()->json(['error' => 'Xác thực license thất bại'], 400);
            }

            $data = $response->json();
            if (empty($data['download_url'])) {
                return response()->json(['error' => 'Không có link tải plugin'], 400);
            }

            $downloadUrl = $data['download_url'];

            $zipResp = Http::timeout(60)->get($downloadUrl);
            if ($zipResp->failed()) {
                return response()->json(['error' => 'Tải file plugin thất bại'], 500);
            }

            $tmpPath = 'tmp/' . Str::random(10) . '.zip';
            Storage::disk('local')->put($tmpPath, $zipResp->body());

            $fullPath = Storage::disk('local')->path($tmpPath);

            PluginInstaller::uploadPlugin(new \Illuminate\Http\File($fullPath));

            return response()->json(['success' => true]);

        } catch (\Throwable $e) {
            Log::error("[PluginMarket] Lỗi khi cài plugin $id: " . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['error' => 'Có lỗi xảy ra khi cài plugin'], 500);
        }
    }

    
}
