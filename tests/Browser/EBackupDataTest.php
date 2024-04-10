<?php

namespace Exceedone\Exment\Tests\Browser;

use Illuminate\Support\Facades\Storage;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;

class EBackupDataTest extends ExmentKitTestCase
{
    /**
     * pre-excecute process before test.
     */
    protected function setUp(): void
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

        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        // check config update
        $this->visit(admin_url('backup'))
                ->seePageIs(admin_url('backup'))
                ->seeInField('backup_enable_automatic', '0')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=database][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=plugin][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=attachment][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=log][checked]')
                ->seeElement('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=config][checked]')
        ;
    }

    /**
     * display backup page.
     */
    public function testDisplayBackupData()
    {
        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        $this->visit(admin_url('backup'))
                ->seePageIs(admin_url('backup'))
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
        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        // 画面に送信ボタンが2つあるため、ボタン押下はできない
        $data = [
            'backup_target' => ['log'],
            'backup_enable_automatic' => '1',
            'backup_automatic_hour' => 20,
            'backup_automatic_term' => 7,
        ];
        // save config
        $response = $this->post(admin_url('backup/setting'), $data);

        // check config update
        $this->visit(admin_url('backup'))
                ->seePageIs(admin_url('backup'))
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
        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        $backup_target = System::backup_target();
        $backup_enable_automatic = System::backup_enable_automatic();
        $data = [
            'backup_target' => [],
            'backup_enable_automatic' => $backup_enable_automatic ? 0 : 1,
        ];
        // save config(fail)
        $this->post(admin_url('backup/setting'), $data);

        // check config no change
        $this->visit(admin_url('backup'))
                ->seePageIs(admin_url('backup'))
                ->seeInField('backup_enable_automatic', $backup_enable_automatic ? 1 : 0);

        // loop target
        $targets = BackupTarget::toArray();
        foreach ($targets as $target) {
            $func = in_array($target, $backup_target) ? 'seeElement' : 'dontSeeElement';
            $this->{$func}('div[id=backup_target] input[type=checkbox][name="backup_target[]"][value=' . $target . '][checked]');
        }
    }

    protected function backupData()
    {
        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        $cnt = count($this->getArchiveFiles());
        // Check backup data count (before)
        $this->visit(admin_url('backup'))
            ->seeElementCount('tr[class=tableHoverLinkEvent]', $cnt)
        ;

        // Backup data
        $this->call('POST', '/admin/backup/save')
        ;

        // Check backup data count (after)
        $this->visit(admin_url('backup'))
            ->seeElementCount('tr[class=tableHoverLinkEvent]', $cnt + 1)
        ;
    }

    protected function restoreData()
    {
        try {
            !\ExmentDB::checkBackup();
            /** @phpstan-ignore-next-line Dead catch - Exceedone\Exment\Exceptions\BackupRestoreCheckException is never thrown in the try block. */
        } catch (BackupRestoreCheckException $ex) {
            $this->assertTrue(true);
            return;
        }

        // get latest backup file
        $files = $this->getArchiveFiles();
        rsort($files);

        if (count($files) > 0) {
            $file = pathinfo($files[0], PATHINFO_FILENAME);
            // Restore data
            $this->call('POST', '/admin/backup/restore', ['file' => $file])
            ;
            $this->seeJson(['result' => true])
            ;
        }
    }

    /**
     * Get all archive file path.
     */
    protected function getArchiveFiles()
    {
        // get all archive files
        $disk = Storage::disk('backup');
        $files = collect($disk->files('list'))->map(function ($filename) use ($disk) {
            return [
                'name' => $filename,
                'lastModified' => $disk->lastModified($filename),
            ];
        })
        ->sortBy('lastModified')
        ->map(function ($file) {
            return $file['name'];
        })->toArray();

        return $files;
    }
}
