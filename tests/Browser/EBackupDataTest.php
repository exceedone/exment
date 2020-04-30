<?php

namespace Exceedone\Exment\Tests\Browser;

use Illuminate\Support\Facades\Storage;

class EBackupDataTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp()
    {
        parent::setUp();
        $this->login();
    }

    /**
     * Initialize backup config.
     */
    public function testInitializeBackupConfig()
    {
        $data = [
            'backup_target' => ['database', 'plugin', 'attachment', 'log', 'config'],
            'backup_enable_automatic' => '0',
        ];
        // save config
        $this->post('/admin/backup/setting', $data)
        ;
    }

    /**
     * display backup page.
     */
    public function testDisplayBackupData()
    {
        $this->visit('/admin/backup')
                ->seePageIs('/admin/backup')
                ->seeInElement('h1', 'バックアップ一覧')
                ->seeInElement('h3[class=box-title]', 'バックアップ設定')
                ->seeInElement('label', 'バックアップ対象')
                ->seeInElement('label', '自動バックアップ')
                ->seeInElement('label', '自動バックアップ実行間隔(日)')
                ->seeInElement('label', '自動バックアップ開始時間(時)')
                ->seeInElement('th', 'ファイル名')
                ->seeInElement('th', 'ファイルサイズ')
                ->seeInElement('th', '作成日時')
                ->seeInElement('th', '操作')
                ->see('データベース')
                ->see('プラグインファイル')
                ->see('添付ファイル')
                ->see('ログファイル')
                ->see('設定ファイル')
                ->seeInField('backup_enable_automatic', '0')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=database][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=plugin][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=attachment][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=log][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=config][checked]')
        ;
    }

    /**
     * Backup data.
     */
    public function testBackupDataSuccess1()
    {
        $this->backupData();
    }

    /**
     * Restore data.
     */
    public function testRestoreDataSuccess1()
    {
        $this->restoreData();
    }

    /**
     * Save backup config.
     */
    public function testBackupConfigSave()
    {
        // 画面に送信ボタンが2つあるため、ボタン押下はできない
        $data = [
            'backup_target' => ['log'],
            'backup_enable_automatic' => '1',
            'backup_automatic_hour' => 20,
            'backup_automatic_term' => 7,
        ];
        // save config
        $this->post('/admin/backup/setting', $data)
        ;
        // check config update
        $this->visit('/admin/backup')
                ->seePageIs('/admin/backup')
                ->seeInField('backup_enable_automatic', '1')
                ->seeInField('backup_automatic_hour', '20')
                ->seeInField('backup_automatic_term', '7')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=database][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=plugin][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=attachment][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=log][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=config][checked]')
        ;
    }

    /**
     * Backup data. --after setting change--
     */
    public function testBackupDataSuccess2()
    {
        $this->backupData();
    }

    /**
     * Restore data. --after setting change--
     */
    public function testRestoreDataSuccess2()
    {
        $this->restoreData();
    }

    /**
     * Backup when config target is not selected.
     */
    public function testBackupNoTarget()
    {
        $data = [
            'backup_target' => [],
            'backup_enable_automatic' => '0',
        ];
        // save config(fail)
        $this->post('/admin/backup/setting', $data)
        ;
        // check config no change
        $this->visit('/admin/backup')
                ->seePageIs('/admin/backup')
                ->seeInField('backup_enable_automatic', '1')
                ->seeInField('backup_automatic_hour', '20')
                ->seeInField('backup_automatic_term', '7')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=database][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=plugin][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=attachment][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=log][checked]')
                ->dontSeeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=config][checked]')
        ;
    }

    protected function backupData()
    {
        $cnt = count($this->getArchiveFiles());
        // Check backup data count (before)
        $this->visit('/admin/backup')
            ->seeElementCount('tr[class=tableHoverLinkEvent]', $cnt)
        ;

        // Backup data
        $this->call('POST', '/admin/backup/save')
        ;

        // Check backup data count (after)
        $this->visit('/admin/backup')
            ->seeElementCount('tr[class=tableHoverLinkEvent]', $cnt + 1)
        ;
    }

    protected function restoreData()
    {
        // get latest backup file
        $files = $this->getArchiveFiles();
        rsort($files);

        if (count($files) > 0) {
            $file = pathinfo($files[0], PATHINFO_FILENAME);
            // Restore data
            $this->call('POST', '/admin/backup/restore', ['file' => $file])
            ;
            $this->assertRedirectedTo('/admin/auth/logout')
            ;
        }
    }

    /**
     * Get all archive file path.
     */
    protected function getArchiveFiles()
    {
        // get all archive files
        $files = array_filter(Storage::disk('backup')->files('list'), function ($file)
        {
            return preg_match('/list\/\d+\.zip$/i', $file);
        });
        return $files;
    }

}
