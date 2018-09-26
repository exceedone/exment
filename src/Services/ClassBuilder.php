<?php
namespace Exceedone\Exment\Services;

use \Exceedone\Exment\Model\Define;
use \Exceedone\Exment\Model\System;
use \Exceedone\Exment\Model\Authority;
use \Exceedone\Exment\Model\CustomColumn;
use \Exceedone\Exment\Model\CustomRelation;
use Illuminate\Support\Facades\DB;


class ClassBuilder {
    private $isTrait;

	private $namespace;

	private $className;

	private $superClass;

	private $interfaces = array();

	private $uses = array();

	private $inUses = array();

	private $properties = array();

	private $methods = array();

	public static function startBuild($className){
		return new self($className);
	}

	private function __construct($className){
		$this->className = $className;
        $this->isTrait = false;
	}

	public function addTrait(){
		$this->isTrait = true;
		return $this;
	}

	public function extend($superClass){
		$this->superClass = $superClass;
		return $this;
	}

	public function implement($interface){
		$this->interfaces[] = $interface;
		return $this;
	}

	public function addNamespace($namespace){
		$this->namespace = $namespace;

		return $this;
	}

	public function addUse($use){
		$this->uses[] = $use;
		return $this;
	}

	public function addInUse($inUse){
		$this->inUses[] = $inUse;
		return $this;
	}

	public function addProperty($scope, $name, $default = null){
		$this->properties[] = array(
			"scope" => $scope,
			"name"  => $name,
			"default"  => $default,
		);

		return $this;
	}

	public function addMethod($scope, $signature, $contents){
		$this->methods[] = array(
			"scope"     => $scope,
			"signature" => $signature,
			"contents"  => $contents,
		);

		return $this;
	}

	public function toString(){
        $namespace = empty($this->namespace) ? "" : "namespace {$this->namespace};";
        $className  = $this->className;
		$superClass = empty($this->superClass) ? "" : " extends {$this->superClass}";
		$trait = $this->isTrait ? 'trait' : 'class';

		$interface  = '';
		if(count($this->interfaces) > 0){
			$interface = " implements ".implode(",", $this->interfaces);
		}

		$uses = array();
		foreach($this->uses as $use){

			$uses[] = sprintf("use %s; ", $use);
		}

		$inUses = array();
		foreach($this->inUses as $inUse){

			$inUses[] = sprintf("use %s; ", $inUse);
		}

		$properties = array();
		foreach($this->properties as $property){

			$scope = empty($property["scope"]) ? "" : $property["scope"];
			$name  = empty($property["name"])  ? "" : '$'.$property["name"];
            $default  = empty($property["default"])  ? "" : " = {$property["default"]}";

			$properties[] = sprintf("%s %s %s;", $scope, $name, $default);
		}

		$methods = array();
		foreach($this->methods as $method){

			$scope     = empty($method["scope"])     ? "" : $method["scope"];
			$signature = empty($method["signature"]) ? "" : $method["signature"];
			$contents  = empty($method["contents"])  ? "" : $method["contents"];

			$methods[] = sprintf(
				"%s function %s {" .
				"%s" .
				"}"
				,$scope, $signature, $contents
			);
		}

		$class = sprintf(
            "%s \n"
            . "%s \n"
			. "%s %s%s%s {\n"
			. "%s\n"
			. "%s\n"
			. "%s\n"
			. "}"

			, $namespace,
            implode(" ", $uses),
            $trait, $className, $superClass, $interface,
            implode(" ", $inUses),
            implode(" ", $properties),
            implode(" ", $methods)
		);

		return $class;
	}

	public function build(){
		eval( $this->toString() );
	}

	/**
	 * Create Custom Value Class Definition
	 */
	public static function createCustomValue($namespace, $className, $fillpath, $table, $obj){
		$builder = static::startBuild($className)
                ->addNamespace($namespace)
                ->addUse("\Exceedone\Exment\Model\CustomValue")
                //->addUse("\Exceedone\Exment\Model\AutoSUuid")
                ->extend("CustomValue")
                //->addInUse('AutoSUuid')
                ->addProperty("protected", 'table', "'".getDBTableName($table)."'")
                //->addProperty("protected", 'casts', "['value' => 'json']")
                ;

            // Add column functions --------------------------------------------------
            $columns = CustomColumn
            ::where('custom_table_id', $table->id)
            ->get();
            foreach ($columns as $column) {
                // add getAttribute function
                $builder->addMethod(
                    "public",
                    "get".pascalize(getColumnName($column))."Attribute()",
                         "return \$this->getValue('{$column->column_name}');"
                );
				// add method for grid page
				$builder->addMethod(
                    "public",
                    "get".pascalize(getColumnName($column))."LabelAttribute()",
                         "return \$this->getValue('{$column->column_name}', true);"
                );

                $builder->addMethod(
                    "public",
                    "set".pascalize(getColumnName($column))."Attribute(\$val)",
                         "\$this->setValue('{$column->column_name}', \$val);"
                );
            }

			// create field
			$column_props = $columns->map(function($value, $key){return "'".getColumnName($value)."'";})->toArray();
			$column_props = array_merge($columns->map(function($value, $key){return "'".getColumnName($value, true)."'";})->toArray(), $column_props);
            $builder->addProperty("protected", 'appends', "[".implode(",", $column_props)."]");

            // Create Relationship --------------------------------------------------
            $relations = CustomRelation
            ::with('child_custom_table')
            ->where('parent_custom_table_id', $table->id)
            ->get();
            
            // loop children tables
            foreach ($relations as $relation) {
                $pivot_table_name = getRelationName($relation);
                // Get Parent and child table Name.
                // case 1 to many
                if ($relation->relation_type == 'one_to_many') {
                    $function_string = 'return $this->morphMany("'.getModelName($relation->child_custom_table).'", "parent");';
                }
                // case many to many
                else {
                    // Create pivot table
                    $db = DB::connection();
                    $db->statement("CREATE TABLE IF NOT EXISTS ".$pivot_table_name." LIKE custom_relation_values");

                    $function_string = 'return $this->belongsToMany("'.getModelName($relation->child_custom_table).'", "'.$pivot_table_name.'", "parent_id", "child_id");';
                }
                $builder = $builder->addMethod("public", "{$pivot_table_name}()", $function_string);
            }
            
            // add authority --------------------------------------------------
            authorityLoop(Define::AUTHORITY_TYPE_VALUE, function($authority, $related_type) use($builder, $obj){
				$target_model = getModelName($related_type, true);
                $builder->addMethod(
                    "public",
                    getAuthorityName($authority, $related_type)."()",
                        "return \$this->morphToMany('$target_model', 'morph', 'value_authoritable', 'morph_id', 'related_id')
                        ->withPivot('related_id', 'related_type', 'authority_id')
                        ->wherePivot('related_type', '".$related_type."')
                        ->wherePivot('authority_id', {$authority->id});"
                    );
            });

            // especially flow if table is user --------------------------------------------------
            if ($table->table_name == Define::SYSTEM_TABLE_NAME_USER) {
                $builder->addInUse('\Exceedone\Exment\Model\UserTrait');
            }

            $builder->build();
	}
	
	/**
	 * Create Custom Table Exts Definition
	 */
	public static function createCustomTableExt($namespace, $className, $fillpath){
		$builder = ClassBuilder::startBuild($className)
                ->addNamespace($namespace)
                ->addTrait()
                ;
            // Ad Authority. for system, table --------------------------------------------------
            authorityLoop(Define::AUTHORITY_TYPE_TABLE, function($authority, $related_type) use($builder){
				$target_model = getModelName($related_type, true);
                $builder->addMethod(
                    "public",
                    getAuthorityName($authority, $related_type)."()",
                        "return \$this->morphToMany('$target_model', 'morph', 'system_authoritable', 'morph_id', 'related_id')
                        ->withPivot('related_id', 'related_type', 'authority_id')
                        ->wherePivot('related_type', '".$related_type."')
                        ->wherePivot('authority_id', {$authority->id});"
                    );
            });
            $builder->build();
	}
}