<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Form;
use Encore\Admin\Grid;
use Exceedone\Exment\Form\Show;
use Encore\Admin\Grid\Linker;
//use Encore\Admin\Controllers\HasResourceActions;
//use Encore\Admin\Widgets\Form;
use Illuminate\Http\Request;
use Encore\Admin\Layout\Content;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyPage;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyBeforeAfter;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use DB;

class NotifyPageController extends AdminControllerBase
{
    use HasResourceActions;

    public function __construct(Request $request)
    {
        // 井坂さん：下記の見出しはNotirfyControllerからのコピペなので、ちゃんと修正する。
        // ページ名は「通知一覧」
        // 説明文は「ユーザーへの通知一覧です。」
        // アイコンは一緒でOK
        $this->setPageInfo(exmtrans("notify.header"), exmtrans("notify.header"), exmtrans("notify.description"), 'fa-bell');
    }

    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        // 井坂さん：下記の見出しはNotirfyControllerからのコピペなので、ちゃんと修正する。
        $grid = new Grid(new NotifyPage);
        $grid->column('read_flg', exmtrans("notify.notify_title"))->sortable();
        $grid->column('notify_subject', exmtrans("notify.notify_subject"))->sortable();
       
        $grid->disableCreation();
        $grid->disableExport();

        $grid->actions(function (Grid\Displayers\Actions $actions) {
            $actions->disableEdit();
            
            // 井坂さん：以下のコメントアウト内容を改造して、「対象データを表示】のリンクを追加する
            // $linker = (new Linker)
            //     ->url(admin_urls("notify_page/create?copy_id={$actions->row->id}"))
            //     ->icon('fa-copy')
            //     ->tooltip(exmtrans('common.copy_item', exmtrans('notify.notify')));
            // $actions->prepend($linker);

            //
            // [できれば]以下のURLの「CheckRow」を追加して、左上のチェックボックスのアクション「既読にする」を追加する。
            // ※「CheckRow」が用途が異なるものかもしれないので、一度検証いただけると嬉しいです
            // https://laravel-admin.org/docs/#/en/model-grid-actions
        });
        return $grid;
    }

    
    /**
     * Make a show builder.
     *
     * @param mixed   $id
     * @return Show
     */
    protected function detail($id)
    {
        $model = NotifyPage::findOrFail($id);

        //井坂さん：ここで$model->read_flgがfalseだった場合に、trueにして保存

        // 井坂さん：下記の日本語を、ちゃんとexmtrans追加って翻訳する
        return new Show($model, function (Show $show) use($model) {
            $show->field('id', 'ID');
            $show->field('target_custom_value', '対象データ')->as(function($v) use($model){
                return CustomTable::getEloquent(array_get($model, 'parent_type'))
                    ->getValueModel(array_get($model, 'parent_id'))
                    ->getValue(true);
            })->setEscape(false);
            $show->field('notify_subject', '通知件名');
            $show->field('notify_body', '通知本文');
        });
    }

    
    /**
     * redirect custom values's detail page
     *
     * @param mixed   $id
     */
    public function redirectTargetData(Request $request)
    {
        // 井坂さん：一度redirectTargetDataを実行させて、read_flgを立てる

        $model = NotifyPage::findOrFail($id);
        //井坂さん：ここで$model->read_flgがfalseだった場合に、trueにして保存

        // CustomValueモデル作成
        // CustomValueモデルのgetUrlメソッドを実行して、リダイレクト先のURL取得
        // リダイレクト実行
    }

}
