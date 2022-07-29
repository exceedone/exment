<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\Define;

/**
 * Plugin (External Database) trait
 */
trait PluginDatabaseExTrait
{
    /**
     * Set external connection
     *
     */
    public function setConnection()
    {
        config(['database.connections.plugin_connection' => [
            'driver'    => $this->plugin->getCustomOption('custom_driver', 'mysql'),
            'host'      => $this->plugin->getCustomOption('custom_host', '127.0.0.1'),
            'port'  => $this->plugin->getCustomOption('custom_port', '3306'),
            'database'  => $this->plugin->getCustomOption('custom_database', 'test'),
            'username'  => $this->plugin->getCustomOption('custom_user', 'root'),
            'password'  => $this->plugin->getCustomOption('custom_password', 'password'),
            'charset'   => 'utf8',
            'collation' => 'utf8_unicode_ci'
        ]]);
    }

    /**
     * カスタムオプション（外部データベース接続先）
     *
     * @param $form
     * @return void
     */
    public function setCustomOptionForm(&$form)
    {
        $form->exmheader('外部データベースの情報');
        $form->select('custom_driver', 'データベースの種類')
            ->options(Define::DATABASE_TYPE)
            ->default('mysql');
        $form->text('custom_host', 'ホスト名')
            ->default('127.0.0.1');
        $form->text('custom_port', 'ポート番号')
            ->default('3306');
        $form->text('custom_database', 'データベース名');
        $form->text('custom_user', 'ユーザー名')
            ->default('root');
        $form->password('custom_password', 'パスワード');
        $form->text('custom_table', '対象テーブル');
    }
}
