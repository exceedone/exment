<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Auth\Permission as Checker;
use Exceedone\Exment\Model\CustomTable;

trait ExmentControllerTrait
{
    protected $title;
    protected $header;
    protected $description;
    protected $headericon;

    protected function setPageInfo($title = null, $header = null, $description = null, $headericon = null)
    {
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
