<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\Define;

/**
 * @uses \Exceedone\Exment\Model\Traits\MailTemplateTrait
 * @uses \Exceedone\Exment\Model\Traits\UserTrait
 * @uses \Exceedone\Exment\Model\Traits\DocumentTrait
 * @uses \Exceedone\Exment\Model\Traits\OrganizationTrait
 */
class ClassBuilder
{
    private $isTrait;

    private $namespace;

    private $className;

    private $superClass;

    private $interfaces = array();

    private $uses = array();

    private $inUses = array();

    private $properties = array();

    private $methods = array();

    public static function startBuild($className)
    {
        return new self($className);
    }

    private function __construct($className)
    {
        $this->className = $className;
        $this->isTrait = false;
    }

    public function addTrait()
    {
        $this->isTrait = true;
        return $this;
    }

    public function extend($superClass)
    {
        $this->superClass = $superClass;
        return $this;
    }

    public function implement($interface)
    {
        $this->interfaces[] = $interface;
        return $this;
    }

    public function addNamespace($namespace)
    {
        $this->namespace = $namespace;

        return $this;
    }

    public function addUse($use)
    {
        $this->uses[] = $use;
        return $this;
    }

    public function addInUse($inUse)
    {
        $this->inUses[] = $inUse;
        return $this;
    }

    public function addProperty($scope, $name, $default = null)
    {
        $this->properties[] = array(
            "scope" => $scope,
            "name"  => $name,
            "default"  => $default,
        );

        return $this;
    }

    public function addMethod($scope, $signature, $contents)
    {
        $this->methods[] = array(
            "scope"     => $scope,
            "signature" => $signature,
            "contents"  => $contents,
        );

        return $this;
    }

    public function toString()
    {
        $namespace = empty($this->namespace) ? "" : "namespace {$this->namespace};";
        $className  = $this->className;
        $superClass = empty($this->superClass) ? "" : " extends {$this->superClass}";
        $trait = $this->isTrait ? 'trait' : 'class';

        $interface  = '';
        if (count($this->interfaces) > 0) {
            $interface = " implements ".implode(",", $this->interfaces);
        }

        $uses = array();
        foreach ($this->uses as $use) {
            $uses[] = sprintf("use %s; ", $use);
        }

        $inUses = array();
        foreach ($this->inUses as $inUse) {
            $inUses[] = sprintf("use %s; ", $inUse);
        }

        $properties = array();
        foreach ($this->properties as $property) {
            $scope = empty($property["scope"]) ? "" : $property["scope"];
            $name  = empty($property["name"]) ? "" : '$'.$property["name"];
            $default  = empty($property["default"]) ? "" : " = {$property["default"]}";

            $properties[] = sprintf("%s %s %s;", $scope, $name, $default);
        }

        $methods = array();
        foreach ($this->methods as $method) {
            $scope     = empty($method["scope"]) ? "" : $method["scope"];
            $signature = empty($method["signature"]) ? "" : $method["signature"];
            $contents  = empty($method["contents"]) ? "" : $method["contents"];

            $methods[] = sprintf(
                "%s function %s {" .
                "%s" .
                "}",
                $scope,
                $signature,
                $contents
            );
        }

        $class = sprintf(
            "%s \n"
            . "%s \n"
            . "%s %s%s%s {\n"
            . "%s\n"
            . "%s\n"
            . "%s\n"
            . "}",
            $namespace,
            implode(" ", $uses),
            $trait,
            $className,
            $superClass,
            $interface,
            implode(" ", $inUses),
            implode(" ", $properties),
            implode(" ", $methods)
        );

        return $class;
    }

    public function build()
    {
        eval($this->toString());
    }


    // static method --------------------------------------------------

    /**
     * Create Custom Value Class Definition
     */
    public static function createCustomValue($namespace, $className, $fillpath, $table, $obj)
    {
        $table = CustomTable::getEloquent($table);

        $builder = static::startBuild($className)
                ->addNamespace($namespace)
                ->addUse("\Exceedone\Exment\Model\CustomValue")
                ->extend("CustomValue")
                ->addProperty("protected", 'table', "'".getDBTableName($table)."'")
                ->addMethod("public", "getCustomTableNameAttribute()", "return '".$table->table_name."';")
                //->addProperty("public", 'custom_table_name', "'".$table->table_name."'")
        ;

        // set revision property
        $revisionEnabled = boolval($table->getOption('revision_flg', true));
        if (!$revisionEnabled) {
            $builder->addProperty("protected", 'revisionEnabled', "false");
        } else {
            $historyLimit = intval($table->getOption('revision_count', 100));
            $builder->addProperty("protected", 'historyLimit', "$historyLimit");
            $builder->addProperty("protected", 'revisionCreationsEnabled', "true");
        }

        // Create Relationship --------------------------------------------------
        $relations = CustomRelation::getRelationsByParent($table);

        // loop children tables
        foreach ($relations as $relation) {
            $function_string = 'return $this->getDynamicRelationValue(' . $relation->id . ', true);';
            $pivot_table_name = $relation->getRelationName();

            $builder = $builder->addMethod("public", "{$pivot_table_name}()", $function_string);
        }

        $relations = CustomRelation::getRelationsByChild($table);
        // loop children tables
        foreach ($relations as $relation) {
            $function_string = 'return $this->getDynamicRelationValue(' . $relation->id . ', false);';
            $pivot_table_name = $relation->getRelationName();

            $builder = $builder->addMethod("public", "{$pivot_table_name}()", $function_string);
        }

        // especially flow if table is user --------------------------------------------------
        if (array_has(Define::CUSTOM_VALUE_TRAITS, $table->table_name)) {
            $builder->addInUse(Define::CUSTOM_VALUE_TRAITS[$table->table_name]);
        }

        $builder->build();
    }
}
