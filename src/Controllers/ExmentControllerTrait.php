<?php

namespace Exceedone\Exment\Controllers;

use Encore\Admin\Facades\Admin;
use Illuminate\Support\Facades\Config;
use Encore\Admin\Auth\Permission as Checker;
use Exceedone\Exment\Model\CustomTable;

trait ExmentControllerTrait
{
    protected $title;
    protected $header;
    protected $description;
    
    protected function setPageInfo($title = null, $header = null, $description = null)
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

        // set admin.config
        // if (isset($this->title)) {
        //     Config::set('admin.title', $this->title);
        // }
    }

    protected function AdminContent($content)
    {
        if (isset($this->header)) {
            $content->header($this->header);
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
        if (!$table->hasPermission($role_name)) {
            Checker::error();
            return false;
        }
        return true;
    }
}
