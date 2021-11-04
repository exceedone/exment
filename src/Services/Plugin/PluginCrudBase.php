<?php
namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Widgets\Form;

/**
 * Plugin CRUD(and List)
 */
class PluginCrudBase
{
    use PluginBase, PluginPageTrait;
    
    public function __construct($plugin, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginOptions = new PluginOption\PluginOptionBatch($options);
    }

    /**
     * GET fields definitions
     *
     * @return array
     */
    public function getFieldDefinitions() : array
    {
        // ToDo:テスト用
        return [
            'id' => ['label' => 'ID', 'primary' => true],
            'name' => ['label' => '名前'],
            'sex' => ['label' => '性別'],
            'age' => ['label' => '年齢'],
            'ikemen' => ['label' => 'イケメンかどうか'],
        ];
    }

    /**
     * GET data list
     *
     * @return array
     */
    public function getList(array $options = []) : array
    {
        $values = \DB::table('members')->paginate();
        return $values;
        // ToDo:テスト用
        // return [
        //     ['name' => '佐藤', 'sex' => 'male'],
        //     ['name' => '鈴木', 'sex' => 'female'],
        // ];
    }

    /**
     * read single data
     *
     * @return array
     */
    public function getSingleData($primaryValue, array $options = []) : array
    {
        // ToDo:テスト用
        $value = \DB::table('members')->find($primaryValue);
        return $value;
    }

    /**
     * get create form
     *
     * @return Form
     */
    public function setForm(Form $form, array $options = []) : Form
    {
        // ToDo:テスト用
        $form->text('name');
        $form->select('sex', ['make' => '男性', 'female' => '女性']);
        $form->numver('age');
        $form->switchbool('ikemen');

        return $form;
    }
    
    /**
     * post create form
     *
     * @return mixed
     */
    public function postCreate(array $posts, array $options = []) : mixed
    {
        // ToDo:テスト用
        // 独自のデータベースに保存する。
        $posts = array_only($posts, [
            'name',
            'sex',
            'age',
            'ikemen'
        ]);
        
        $value = \DB::table('members')->create($posts);

        // 主キーを戻す・・・が良いのかな。
    }

    /**
     * post create form
     *
     * @return mixed
     */
    public function putEdit(Request $request, array $posts, array $options = []) : mixed
    {
        // ToDo:テスト用
        // 独自のデータベースに保存する。
        $posts = array_only($posts, [
            'name',
            'sex',
            'age',
            'ikemen'
        ]);
        
        $value = \DB::table('members')->create($posts);

        // 主キーを戻す・・・が良いのかな。
    }
}
