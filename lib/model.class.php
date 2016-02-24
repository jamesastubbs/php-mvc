<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

abstract class Model extends ArrayObject
{
	const StateError = -1;
	const StateSelect = 1;
	const StateJoin = 2;
	const StateOn = 3;
	
	public static $tableName;
    static $db = null;
	public $primaryKey;
	public $columns;
	private $isSaved = false;
	private $_tmp;
	
	final public function __construct()
	{
		parent::__construct(array(), ArrayObject::ARRAY_AS_PROPS);
	}
	
    public final static function setDB($db)
    {
        self::$db = $db;
    }
    
	final function getTmp($key)
	{
		if (!isset($this->_tmp))
			return null;
		return $this->_tmp[$key];
	}
	
	final function setTmp($key, $value)
	{
		if (!isset($this->_tmp))
			$this->_tmp = array();
		$this->_tmp[$key] = $value;
	}
	
    /**
     * Returns a model object with the type of '$relatingModelName'.
     * The values of the relating model object are automatically populated from this model instance having performed a join query.
     * @var     string  The class name of the relating model object.
     * @return  mixed   The relating model object.
     */
    final function getRelatingModel($relatingModelName)
    {
        $relatingModel = new $relatingModelName();
        $relatingModelColumns = array_keys($relatingModel->columns);
        
        foreach ($relatingModelColumns as $columnName) {
			$relatingModel->{$columnName} = $this["$relatingModelName.$columnName"];
		}
        
        return $relatingModel;
    }
    
	public function create($fetchAfter = false)
	{
		$selfName = get_class($this);
		$statement = "INSERT INTO " . $this->db->dbName . "." . $selfName::$tableName . " (";
		$statementPart = ") VALUES (";
		$first = true;
		$arguments = array();
		$columns = array_keys($this->columns);
		$columnCount = count($columns);
		for ($i = 0; $i < $columnCount; $i++) {
			$key = $columns[$i];
			if (array_key_exists($key, $this)) {
				$value = $this->{$key};
				if ($first) {
					$first = false;
				} else {
					$statement .= ", ";
					$statementPart .= ", ";
				}
				
				$statement .= $key;
				$statementPart .= "?";
				array_push($arguments, $value);
				
				$oldKey = "__old_$key";
				$this->{$oldKey} = $value;
				
				unset($columns[$i]);
			}
		}
		
		$statement .= $statementPart . ");";
		
		$lastInsertId = $this->db->query($statement, ((bool)count($arguments) ? $arguments : null));
		$this->{$this->primaryKey} = $lastInsertId;
		
		if ($fetchAfter && count($columns)) {
			$statement = "SELECT ";
			$isFirst = true;
			foreach ($columns as $column) {
				if ($isFirst)
					$isFirst = false;
				else
					$statement .= ", ";
				$statement .= $column;
			}
			$statement .= " FROM " . $selfName::$tableName . " WHERE " . $this->primaryKey . " = ? LIMIT 1";
			$result = $this->db->query($statement, $this->{$this->primaryKey});
			
			if (empty($result)) {
				return false;
			}
			
			$result = $result[0];
			
			foreach ($columns as $column) {
				if (array_key_exists($column, $result)) {
					$this->{$column} = $result[$column];
					$oldColumn = "__old_$column";
					$this->{$oldColumn} = $result[$column];
				}
			}
		}
		
		return (bool)$this->{$this->primaryKey};
	}
	
	public static function select(ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		return $selfName::selectColumns("*", $predicate);
	}
	
	public static function selectColumns($columns, ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		$statement = "SELECT ";
		if (gettype($columns) == "string") {
			if (preg_match("/\ /", $columns)) {
				$strComponents = explode(" ", $columns);
				for ($i = 0; $i < count($strComponents); $i++) {
					$strComponent = $strComponents[$i];
					if (preg_match("/\\$::(\w+)\./", $strComponent)) {
						$strComponentParts = explode(".", $strComponent);
						$modelName = preg_replace("/\\\$::/", "", $strComponentParts[0]);
						$columnName = $strComponentParts[1];
						$strComponents[$i] = $modelName::$tableName . ".$columnName";
					}
				}
				$columns = implode(" ", $strComponents);
				
			}
			$statement .= "$columns ";
		} else {
			$first = true;
			foreach ($columns as $column) {
				if ($first)
					$first = false;
				else
					$statement .= ", ";
				
				if (gettype($column) != "string") {
					$subFirst = true;
					foreach ($column as $columnName) {
						if ($subFirst)
							$subFirst = false;
						else
							$statement .= ", ";
						
						$statement .= $column->tableName . ".$columnName";
					}
				} else
					$statement .= $column;
			}
		}
		
		$statement .= " FROM " . $selfName::$tableName;
		if (isset($predicate))
			$statement .= " " . $predicate->format;
		
		$fetchedData = self::$db->query($statement, (isset($predicate) ? $predicate->arguments : null));
		
		$fetchedObjects = array();
		
		foreach ($fetchedData as $data) {
			$model = new $selfName();
			foreach ($data as $key => $value) {
				$model->{$key} = $value;
				$oldKey = "__old_$key";
				$model->{$oldKey} = $value;
			}
			array_push($fetchedObjects, $model);
		}
		
		return $fetchedObjects;
	}
	
	public static function selectWith(ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		return $selfName::selectColumnsWith("*", $predicate);
	}
	
	public static function selectColumnsWith($columns, ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		$statement = "SELECT ";
		if (gettype($columns) == "string") {
			if (preg_match("/\ /", $columns)) {
				$strComponents = explode(" ", $columns);
				for ($i = 0; $i < count($strComponents); $i++) {
					$strComponent = $strComponents[$i];
					if (preg_match("/\\$::(\w+)\./", $strComponent)) {
						$strComponentParts = explode(".", $strComponent);
						$modelName = preg_replace("/\\\$::/", "", $strComponentParts[0]);
						$columnName = $strComponentParts[1];
						$strComponents[$i] = $modelName::$tableName . ".$columnName";
					}
				}
				$columns = implode(" ", $strComponents);
				
			}
			$statement .= "$columns ";
		} else {
			$first = true;
			foreach ($columns as $column) {
				if ($first)
					$first = false;
				else
					$statement .= ", ";
				
				if (gettype($column) != "string") {
					$subFirst = true;
					foreach ($column as $columnName) {
						if ($subFirst)
							$subFirst = false;
						else
							$statement .= ", ";
						
						$statement .= $column->tableName . ".$columnName";
					}
				} else
					$statement .= $column;
			}
		}
		
		$statement .= " FROM " . $selfName::$tableName;
		
		$model = new $selfName();
		$model->setTmp("state", 1);
		$model->setTmp("statement", $statement);
		$model->setTmp("predicate", (isset($predicate) ? $predicate->format : null));
		$model->setTmp("arguments", (isset($predicate) ? $predicate->arguments : null));
		
		return $model;
	}
	
	public function innerJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, "INNER", $isTable);
	}

	public function leftJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, "LEFT", $isTable);
	}

	public function rightJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, "RIGHT", $isTable);
	}

	final private function joinMethod($parentModel, $childModel, $joinMethod, $isTable)
	{
		if (!isset($this->_tmp) || !(($this->_tmp["state"] != 1 || $this->_tmp["state"] != 3))) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN))
				$this->debug("join");
			return $this;
		}

		if (!array_key_exists("joins", $this->_tmp))
			$this->_tmp["joins"] = array();

		array_push($this->_tmp["joins"], array(
			"parent_model" => $parentModel,
			"child_model" => $childModel,
			"method" => $joinMethod,
			"is_table" => $isTable
		));

		if (!array_key_exists("current_join", $this->_tmp))
			$this->_tmp["current_join"] = self::StateError;

		$this->_tmp["current_join"]++;
		$this->_tmp["state"] = self::StateJoin;

		return $this;
	}
	
	public function on($primaryKey, $foreignKey = null)
	{
		if (!isset($this->_tmp) || $this->_tmp["state"] != 2) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN))
				$this->debug("on");
			return $this;
		}

		if (is_null($foreignKey))
			$foreignKey = $primaryKey;

		$joinDefinition = $this->_tmp["joins"][$this->_tmp["current_join"]];
		$joinDefinition["primary_key"] = $primaryKey;
		$joinDefinition["foreign_key"] = $foreignKey;
		
		$this->_tmp["joins"][$this->_tmp["current_join"]] = $joinDefinition;

		$this->_tmp["state"] = 3;

		return $this;
	}
	
	public function execute()
	{
		if (!isset($this->_tmp) || !($this->_tmp["state"] != 1 || $this->_tmp["state"] != 3)) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN))
				$this->debug("execute");
			return $this;
		}
		
		$relationships = array();
		$columns = $this->_tmp;
		$statement = "";
		
		if (isset($this->_tmp["joins"])) {
			foreach ($this->_tmp["joins"] as $joinDefinition) {
				$joinMethod = $joinDefinition["method"];
				$childModel = $joinDefinition["child_model"];
				$tableName = $childModel;
				$parentModel = $joinDefinition["parent_model"];
				$parentTableName = $parentModel;
				$primaryKey = $joinDefinition["primary_key"];
				$foreignKey = $joinDefinition["foreign_key"];

				if (!$joinDefinition["is_table"]) {
					$tableName = $childModel::$tableName;
					$parentTableName = $parentModel::$tableName;
					if (!array_key_exists($parentModel, $relationships)) {
						$relationships[$parentModel] = array();
					}
					$relationships[$parentModel][$childModel] = array(
						"primaryKey" => $primaryKey,
						"foreignKey" => $foreignKey
					);
				}

				if ($parentTableName == $tableName)
					die(var_dump($joinDefinition));
				$statement .= " $joinMethod JOIN $tableName ON $parentTableName.$primaryKey = $tableName.$foreignKey";
			}
			
			$columns = array();
			foreach ($relationships as $parentModelName => $parentRelationships) {
				if (!array_key_exists($parentModelName, $columns)) {
					$modelColumns = array();
					$model = new $parentModelName();
					foreach (array_keys($model->columns) as $modelColumnName) {
						$columnString = ($parentModelName::$tableName . ".$modelColumnName AS '$parentModelName.$modelColumnName'");
						array_push($modelColumns, $columnString);
					}
					$columns[$parentModelName] = $modelColumns;
					$model = null;
				}
				
				foreach ($parentRelationships as $childModelName => $childRelationship) {
					if (!array_key_exists($childModelName, $columns)) {
						$modelColumns = array();
						$model = new $childModelName();
						foreach (array_keys($model->columns) as $modelColumnName) {
							$columnString = ($childModelName::$tableName . ".$modelColumnName AS '$childModelName.$modelColumnName'");							
                            array_push($modelColumns, $columnString);
						}
						$columns[$childModelName] = $modelColumns;
						$model = null;
					}
				}
			}
		}
		
		$selectStatement = "SELECT ";
		if (gettype($columns) == "string")
			$selectStatement .= "$columns ";
		else {
			$first = true;
			foreach ($columns as $column) {
				if ($first)
					$first = false;
				else
					$selectStatement .= ", ";

				if (gettype($column) != "string") {
					$subFirst = true;
					foreach ($column as $columnName) {
						if ($subFirst)
							$subFirst = false;
						else
							$selectStatement .= ", ";

						$selectStatement .= $columnName;
					}
				} else
					$selectStatement .= $column;
			}
		}
		
		$selfName = get_class($this);
		$selectStatement .= " FROM " . $selfName::$tableName;
		$statement = $selectStatement . $statement;
		
		if (isset($this->_tmp["predicate"]))
			$statement .= " " . $this->_tmp["predicate"];
		$arguments = $this->_tmp["arguments"];
		
		$fetchedData = self::$db->query($statement, (isset($arguments) ? $arguments : null));
		
		$fetchedObjects = array();
		
		foreach ($fetchedData as $data) {
			$model = new $selfName();
			foreach ($data as $key => $value) {
				if (strpos($key, "$selfName.") === 0) {
                    $key = str_replace("$selfName.", "", $key);
                    $oldKey = "__old_$key";
                    $model->{$oldKey} = $value;
                }
				
				$model->{$key} = $value;
			}
			array_push($fetchedObjects, $model);
		}
		
		$this->_tmp = null;
		
		return $fetchedObjects;
	}
	
	public function update()
	{	
		$selfName = get_called_class();
		$statement = "UPDATE " . self::$db->dbName . "." . $selfName::$tableName . " SET ";
		$arguments = array();
		
		$first = true;
		foreach ($this as $key => $value) {
			if (preg_match("/__old_/", $key) || !array_key_exists($key, $this->columns)) {
				continue;
			}
			
			$oldKey = "__old_$key";
			$oldValue = $this->{$oldKey};
			
			if ($value != $oldValue) {
				if ($first)
					$first = false;
				else
					$statement .= ", ";
				$statement .= "$key = ?";
				array_push($arguments, $value);
			}
		}
		
		if ((bool)count($arguments)) {
			$primaryKeyName = $this->primaryKey;
			$statement .= " WHERE $primaryKeyName = " . $this->{$primaryKeyName};
			return (bool)self::$db->query($statement, $arguments);
		}
		
		return true;
	}
	
	public function delete()
	{
		$selfName = get_class($this);
		$primaryKeyName = $this->primaryKey;
		return (bool)self::$db->query("DELETE FROM " . $selfName::$tableName . " WHERE $primaryKeyName = " . $this->{$primaryKeyName});
	}
	
	public static function columns()
	{
		$selfName = get_called_class();
		$tableName = $selfName::$tableName;
		$model = new $selfName(null);
		$_columns = $model->columns;
		$model = null;
		
		$columns = array();
		foreach (array_keys($_columns) as $columnName) {
			$columnName = $tableName . ".$columnName";
			array_push($columns, $columnName);
		}
		
		return array_keys($columns);
	}
	
	private function debug($method)
	{
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtraceStr = "";
		
		if (count($backtrace) > 1)
			$backtraceStr = $backtrace[1]['file'] . " on line " . $backtrace[1]['line'];
		
		$selfName = get_class($this);
		trigger_error("$selfName cannot continue with SELECT query. Stopped at $method() - $backtraceStr", E_USER_ERROR);
	}
}

final class ModelColumn extends ArrayObject
{
	public $tableName;
	
	public function __construct($tableName)
	{
		parent::__construct(array(), ArrayObject::ARRAY_AS_PROPS);
		$this->tableName = $tableName;
		
		$columns = array_slice(func_get_args(), 1);
		
		foreach ($columns as $column) {
			$this->append($column);
		}
	}
}

final class ModelPredicate
{
	public $format;
	public $arguments;

	public function __construct($str)
	{
		if (preg_match("/\ /", $str)) {
			$strComponents = explode(" ", $str);
			for ($i = 0; $i < count($strComponents); $i++) {
				$strComponent = $strComponents[$i];
				if (preg_match("/\\$::(\w+)\./", $strComponent)) {
					$strComponentParts = explode(".", $strComponent);
					$modelName = preg_replace("/\\\$::/", "", $strComponentParts[0]);
					$columnName = $strComponentParts[1];
					$strComponents[$i] = $modelName::$tableName . ".$columnName";
				}
			}
			$str = implode(" ", $strComponents);
		}

		$this->format = $str;
		$arguments = array_slice(func_get_args(), 1);
		if (isset($arguments) && (bool)count($arguments)) {
			$this->arguments = $arguments;
		}
	}

	public function isLimitOne() {
		return (preg_match("/LIMIT 1/", $this->format) || preg_match("/limit 1/", $this->format));
	}
}