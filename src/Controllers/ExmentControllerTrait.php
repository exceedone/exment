<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Auth\Permission as Checker;
use Exceedone\Exment\Model\CustomTable;

trait ExmentControllerTrait
{
    /** @var string|null the language of the title and the header when they were set */
    protected $pageInfoLocale;

    // @phpstan-ignore-next-line
    protected $title;
    // @phpstan-ignore-next-line
    protected $header;
    // @phpstan-ignore-next-line
    protected $description;
    // @phpstan-ignore-next-line
    protected $headericon;

    /**
     * The title and the header in the language of the user.
     *
     * Laravel makes the controller before the middleware run (to ask it for its middleware), so a
     * constructor that sets them with exmtrans() reads them in the language of the site. The
     * language of the user is applied later (UserLocaleTimezone, after the login is known): when it
     * is another one, the constructor is run again. Called by callAction() before the action.
     */
    protected function localizePageInfo(): void
    {
        if (isset($this->pageInfoLocale) && $this->pageInfoLocale !== app()->getLocale() && method_exists($this, '__construct')) {
            app()->call([$this, '__construct']);
        }
    }

    // @phpstan-ignore-next-line
    protected function setPageInfo($title = null, $header = null, $description = null, $headericon = null)
    {
        // the language the texts are in (see localizePageInfo())
        $this->pageInfoLocale = app()->getLocale();
        if (isset($header)) {
            $this->header = $header;
        }
        if (isset($description)) {
            $this->description = $description;
        }
        if (isset($title)) {
            $this->title = $title;
        }
        if (isset($headericon)) {
            $this->headericon = $headericon;
        }
    }

    // @phpstan-ignore-next-line
    protected function AdminContent($content)
    {
        if (isset($this->header)) {
            $content->header($this->header);
        }
        if (isset($this->headericon)) {
            $content->headericon($this->headericon);
        }
        if (isset($this->description)) {
            $content->description($this->description);
        } else {
            $content->description(' ');
        }
        return $content;
    }

    /**
     * validation table
     * @param mixed $table id or customtable
     */
    // @phpstan-ignore-next-line
    protected function validateTable($table, $role_name)
    {
        $table = CustomTable::getEloquent($table);
        //check permission
        // if not exists, filter model using permission
        if (!isset($table) || !$table->hasPermission($role_name)) {
            Checker::error();
            return false;
        }
        return true;
    }
}
