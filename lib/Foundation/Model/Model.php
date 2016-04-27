<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation\Model;

use \PHPMVC\Foundation\Application;

abstract class Model extends \ArrayObject
{
	const StateError = -1;
	const StateSelect = 1;
	const StateJoin = 2;
	const StateOn = 3;
    
    const RELATIONSHIP_UNKNOWN = -1;
    const RELATIONSHIP_ONE_TO_ONE = 0;
    const RELATIONSHIP_ONE_TO_MANY = 1;
    const RELATIONSHIP_MANY_TO_ONE = 2;
    const RELATIONSHIP_MANY_TO_MANY = 3;
    
    const COLUMN_BOOLEAN = 'boolean';
    const COLUMN_DATE = 'date';
    const COLUMN_INTEGER = 'integer';
    const COLUMN_STRING = 'string';
    
	public static $tableName;
    public static $relationships = [];
    public static $primaryKey = null;
    protected static $db = null;
    protected static $_cachedModels = [];
	public $columns;
    private $modelNamespace = null;
	private $isSaved = false;
	private $_tmp;
	
	final public function __construct()
	{
		parent::__construct(array(), \ArrayObject::ARRAY_AS_PROPS);
        $this->modelNamespace = Application::getConfigValue('NAME') . '\\Model\\';
	}
	
    final public static function setDB($db)
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
    final public function getRelatingModel($relatingModelName)
    {
        $selfName = get_class($this);
        $relatingModelClass = $this->modelNamespace . $relatingModelName;
        $relatingModelPrimaryKey = $relatingModelClass::$primaryKey;
        $relatingModel = new $relatingModelClass();
        $modelIsThisClass = ($relatingModelClass === $selfName);
        
        if ($modelIsThisClass) {
            $relatingModelPrimaryKeyValue = "{$this->{$relatingModelPrimaryKey}}";
        } else {
            $relatingModelPrimaryKeyValue = "{$this->{"$relatingModelName.$relatingModelPrimaryKey"}}";
        }
        
        if (array_key_exists($relatingModelName, self::$_cachedModels) && array_key_exists($relatingModelPrimaryKeyValue, self::$_cachedModels[$relatingModelName])) {
            return self::$_cachedModels[$relatingModelName][$relatingModelPrimaryKeyValue];
        }
        
        $relatingModelColumns = array_keys($relatingModel->columns);
        
        foreach ($relatingModelColumns as $columnName) {
			$key = ($modelIsThisClass ? '' : "$relatingModelName.") . $columnName;
            $value = $this[$key];
			$relatingModel->{$columnName} = $value;
            
            if ($columnName !== $relatingModelPrimaryKey) {
                $oldKey = "__old_$columnName";
                $relatingModel->{$oldKey} = $value;
            }
		}
        
        self::$_cachedModels[$relatingModelName][$relatingModelPrimaryKeyValue] = $relatingModel;
        
        return $relatingModel;
    }
    
    final private static function mapModelRelationships($parentModel, $childModel, $joinDefinition)
    {
        $parentModelName = get_class($parentModel);
        $childModelName = get_class($childModel);
        $parentModelKey = lcfirst($parentModelName);
        $childModelKey = lcfirst($childModelName);
        
        $joinDefinition['method'] = 'guess';
        $relationships = $parentModelName::$relationships;
        
        foreach ($relationships as $relationshipName => $relationship) {
            // if relationship is defined in class, we'll go by that definition.
            if ($relationship['model'] === $childModelName) {
                $joinDefinition['method'] = $relationship['relationship'];
                $joinDefinition['property_key'] = $relationshipName;
                $joinDefinition['primary_key'] = $relationship['column'];
                $joinDefinition['foreign_key'] = array_key_exists('relatedColumn', $relationship) ? $relationship['relatedColumn'] : $relationship['column'];
                break;
            }
        }
        
        if ($joinDefinition['method'] !== 'guess') {
            $propertyKey = $joinDefinition['property_key'];
            $relationshipType = $joinDefinition['method'];
            
            if ($relationshipType === self::RELATIONSHIP_ONE_TO_MANY || $relationshipType === self::RELATIONSHIP_MANY_TO_MANY) {
                if (!property_exists($parentModel, $propertyKey)) {
                    $parentModel->{$propertyKey} = [];
                }
                
                array_push($parentModel->{$propertyKey}, $childModel);
            } else {
                $parentModel->{$propertyKey} = $childModel;
            }
        } else {
            $add = true;
            
            if (property_exists($parentModel, $childModelKey)) {
                $storedChildModel = $parentModel->{$childModelKey};
                
                if ($storedChildModel === $childModel) {
                    $add = false;
                } else {
                    unset($parentModel->{$childModelKey});
                    $parentModel->{$childModelKey . 's'} = [$storedChildModel];
                }
            }
            
            if ($add) {
                if (property_exists($parentModel, $childModelKey . 's')) {
                    if (!in_array($childModel, $parentModel->{$childModelKey. 's'})) {
                        array_push($parentModel->{$childModelKey. 's'}, $childModel);
                    }
                } else {
                    $parentModel->{$childModelKey} = $childModel;
                }
            }
        }
    }
    
    public static function findByID($id)
    {
        $selfClass = get_called_class();
        $selfPrimaryKey = $selfClass::$primaryKey;
        
        $urls = $selfClass::select(
            new ModelPredicate("WHERE $selfPrimaryKey = ? LIMIT 1", $id)
        );
        
        $url = empty($urls) ? null : $urls[0];
        
        return $url;
    }
    
	public function create($fetchAfter = false)
	{
		$selfName = get_class($this);
        $selfPrimaryKey = $selfName::$primaryKey;
		$statement = 'INSERT INTO ' . self::$db->dbName . ".{$selfName::$tableName} (";
		$statementPart = ') VALUES (';
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
					$statement .= ', ';
					$statementPart .= ', ';
				}
				
				$statement .= $key;
				$statementPart .= '?';
				array_push($arguments, $value);
				
                if ($key !== $selfPrimaryKey) {
				    $oldKey = "__old_$key";
				    $this->{$oldKey} = $value;
                }
				
				unset($columns[$i]);
			}
		}
		
		$statement .= $statementPart . ');';
		
		$lastInsertId = self::$db->query($statement, ((bool)count($arguments) ? $arguments : null));
		$this->{$selfPrimaryKey} = $lastInsertId;
		
		if ($fetchAfter && count($columns)) {
			$statement = 'SELECT ';
			$isFirst = true;
			foreach ($columns as $column) {
				if ($isFirst)
					$isFirst = false;
				else
					$statement .= ', ';
				$statement .= $column;
			}
			$statement .= " FROM {$selfName::$tableName} WHERE {$selfPrimaryKey} = ? LIMIT 1";
			$result = self::$db->query($statement, $this->{$selfPrimaryKey});
			
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
		
		return (bool)$this->{$selfPrimaryKey};
	}
	
	public static function select(ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		return $selfName::selectColumns('*', $predicate);
	}
	
	public static function selectColumns($columns, ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		$statement = 'SELECT ';
		if (gettype($columns) == 'string') {
			if (preg_match('/\ /', $columns)) {
				$strComponents = explode(' ', $columns);
				for ($i = 0; $i < count($strComponents); $i++) {
					$strComponent = $strComponents[$i];
					if (preg_match('/\\$::(\w+)\./', $strComponent)) {
						$strComponentParts = explode('.', $strComponent);
						$modelName = preg_replace('/\\\$::/', '', $strComponentParts[0]);
						$columnName = $strComponentParts[1];
						$strComponents[$i] = $modelName::$tableName . ".$columnName";
					}
				}
				$columns = implode(' ', $strComponents);
				
			}
			$statement .= "$columns ";
		} else {
			$first = true;
			foreach ($columns as $column) {
				if ($first)
					$first = false;
				else
					$statement .= ', ';
				
				if (gettype($column) != 'string') {
					$subFirst = true;
					foreach ($column as $columnName) {
						if ($subFirst)
							$subFirst = false;
						else
							$statement .= ', ';
						
						$statement .= $column->tableName . ".$columnName";
					}
				} else
					$statement .= $column;
			}
		}
		
		$statement .= ' FROM ' . $selfName::$tableName;
        
		if ($predicate !== null) {
            $classParts = explode('\\', $selfName);
            $modelClass = array_pop($classParts);
            $predicate->addClasses([$modelClass => $selfName]);
			$statement .= ' ' . $predicate->getFormattedQuery();
        }
        
        $fetchedData = self::$db->query($statement, (isset($predicate) ? $predicate->arguments : null));
        
		$fetchedObjects = [];
        
		foreach ($fetchedData as $data) {
			$model = new $selfName();
			foreach ($data as $key => $value) {
				$model->{$key} = $value;
                
                if ($key !== $selfName::$primaryKey) {
				    $oldKey = "__old_$key";
				    $model->{$oldKey} = $value;
                }
			}
			array_push($fetchedObjects, $model);
		}
        
		return $fetchedObjects;
	}
	
	public static function selectWith(ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		return $selfName::selectColumnsWith('*', $predicate);
	}
	
	public static function selectColumnsWith($columns, ModelPredicate $predicate = null)
	{
		$selfName = get_called_class();
		$statement = 'SELECT ';
        
		if (gettype($columns) == 'string') {
			if (preg_match('/\ /', $columns)) {
				$strComponents = explode(' ', $columns);
				
                for ($i = 0; $i < count($strComponents); $i++) {
					$strComponent = $strComponents[$i];
					
                    if (preg_match('/\\$::(\w+)\./', $strComponent)) {
						$strComponentParts = explode('.', $strComponent);
						$modelName = preg_replace('/\\\$::/', '', $strComponentParts[0]);
						$columnName = $strComponentParts[1];
						$strComponents[$i] = $modelName::$tableName . ".$columnName";
					}
				}
                
				$columns = implode(' ', $strComponents);
				
			}
            
			$statement .= '$columns ';
		} else {
			$first = true;
			foreach ($columns as $column) {
				if ($first)
					$first = false;
				else
					$statement .= ', ';
				
				if (gettype($column) != 'string') {
					$subFirst = true;
					foreach ($column as $columnName) {
						if ($subFirst)
							$subFirst = false;
						else
							$statement .= ', ';
						
						$statement .= $column->tableName . ".$columnName";
					}
				} else
					$statement .= $column;
			}
		}
		
		$statement .= ' FROM ' . $selfName::$tableName;
		
        if ($predicate !== null) {
            $classParts = explode('\\', $selfName);
            $modelClass = array_pop($classParts);
            $predicate->addClasses([$modelClass => $selfName]);
        }
        
		$model = new $selfName();
		$model->setTmp('state', 1);
		$model->setTmp('statement', $statement);
		$model->setTmp('predicate', $predicate);
		$model->setTmp('arguments', (isset($predicate) ? $predicate->arguments : null));
		
		return $model;
	}
	
	public function innerJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, 'INNER', $isTable);
	}

	public function leftJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, 'LEFT', $isTable);
	}

	public function rightJoin($parentModel, $childModel, $isTable = false)
	{
		return $this->joinMethod($parentModel, $childModel, 'RIGHT', $isTable);
	}

	final private function joinMethod($parentModel, $childModel, $joinMethod, $isTable)
	{
		if (!isset($this->_tmp) || !(($this->_tmp['state'] != 1 || $this->_tmp['state'] != 3))) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN)) {
				$this->debug('join');
            }
            
			return $this;
		}
        
		if (!array_key_exists('joins', $this->_tmp)) {
			$this->_tmp['joins'] = [];
        }
        
		array_push($this->_tmp['joins'], [
			'parent_model' => $parentModel,
			'child_model' => $childModel,
			'method' => $joinMethod,
			'is_table' => $isTable
		]);
        
        if (!array_key_exists('current_join', $this->_tmp)) {
			$this->_tmp['current_join'] = self::StateError;
        }
        
        $predicate = $this->_tmp['predicate'];
        
        // if predicate exists and we're not joining via table names, add Model classes.
        if ($predicate !== null && !$isTable) {
            $selfName = get_called_class();
            
            $classParts = explode('\\', $selfName);
            array_pop($classParts);
            $modelNamespace = implode('\\', $classParts);
            
            // add parent class.
            $predicate->addClasses([$parentModel => "$modelNamespace\\$parentModel"]);
            
            // add child class.
            $predicate->addClasses([$childModel => "$modelNamespace\\$childModel"]);
        }
        
		$this->_tmp['current_join']++;
		$this->_tmp['state'] = self::StateJoin;

		return $this;
	}
	
	public function on($primaryKey, $foreignKey = null)
	{
		if (!isset($this->_tmp) || $this->_tmp['state'] != 2) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN))
				$this->debug('on');
			return $this;
		}

		if (is_null($foreignKey))
			$foreignKey = $primaryKey;

		$joinDefinition = $this->_tmp['joins'][$this->_tmp['current_join']];
		$joinDefinition['primary_key'] = $primaryKey;
		$joinDefinition['foreign_key'] = $foreignKey;
		
		$this->_tmp['joins'][$this->_tmp['current_join']] = $joinDefinition;

		$this->_tmp['state'] = 3;

		return $this;
	}
	
	public function execute($returnRaw = false)
	{
		if (!isset($this->_tmp) || !($this->_tmp['state'] != 1 || $this->_tmp['state'] != 3)) {
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN)) {
				$this->debug('execute');
            }
            
			return $this;
		}
		
		$relationships = [];
		$columns = $this->_tmp;
		$statement = '';
        $modelNamespace = $this->modelNamespace;
        
		if (isset($this->_tmp['joins'])) {
			foreach ($this->_tmp['joins'] as $joinDefinition) {
				$joinMethod = $joinDefinition['method'];                
                $tableName = $joinDefinition['child_model'];
				$childModel = $modelNamespace . $tableName;
				$parentTableName = $joinDefinition['parent_model'];
				$parentModel = $modelNamespace . $parentTableName;
				$primaryKey = $joinDefinition['primary_key'];
				$foreignKey = $joinDefinition['foreign_key'];

				if (!$joinDefinition['is_table']) {
					$tableName = $childModel::$tableName;
					$parentTableName = $parentModel::$tableName;
					if (!array_key_exists($parentModel, $relationships)) {
						$relationships[$parentModel] = [];
					}
					$relationships[$parentModel][$childModel] = [
						'primaryKey' => $primaryKey,
						'foreignKey' => $foreignKey
					];
				}

				if ($parentTableName == $tableName) {
					die(var_dump($joinDefinition));
                }
                
				$statement .= " $joinMethod JOIN $tableName ON $parentTableName.$primaryKey = $tableName.$foreignKey";
			}
			
			$columns = [];
            $modelNamespaceRegex = '/' . $this->modelNamespace . '\/';
            
			foreach ($relationships as $parentModelClass => $parentRelationships) {
				if (!array_key_exists($parentModelClass, $columns)) {
                    $parentModelNameParts = explode('\\', $parentModelClass);
                    $parentModelName = array_pop($parentModelNameParts);
                    
					$modelColumns = [];
					$model = new $parentModelClass();
                                        
					foreach (array_keys($model->columns) as $modelColumnName) {
                        $columnString = ($parentModelClass::$tableName . ".$modelColumnName AS '$parentModelName.$modelColumnName'");
						array_push($modelColumns, $columnString);
					}
					$columns[$parentModelName] = $modelColumns;
					$model = null;
				}
				
				foreach ($parentRelationships as $childModelClass => $childRelationship) {
					if (!array_key_exists($childModelClass, $columns)) {
						$childModelNameParts = explode('\\', $childModelClass);
                        $childModelName = array_pop($childModelNameParts);
                        
                        $modelColumns = array();
						$model = new $childModelClass();
                        
						foreach (array_keys($model->columns) as $modelColumnName) {
							$columnString = ($childModelClass::$tableName . ".$modelColumnName AS '$childModelName.$modelColumnName'");
                            array_push($modelColumns, $columnString);
						}
						$columns[$childModelName] = $modelColumns;
						$model = null;
					}
				}
			}
		}
		
		$selectStatement = 'SELECT ';
        
		if (gettype($columns) == 'string') {
			$selectStatement .= '$columns ';
        } else {
			$first = true;
			
            foreach ($columns as $column) {
				if ($first) {
					$first = false;
                } else {
					$selectStatement .= ', ';
                }
                
				if (gettype($column) != 'string') {
					$subFirst = true;
                    
					foreach ($column as $columnName) {
						if ($subFirst) {
							$subFirst = false;
                        } else {
							$selectStatement .= ', ';
                        }

						$selectStatement .= $columnName;
					}
				} else
					$selectStatement .= $column;
			}
		}
        		
		$selfClass = get_class($this);
        $selfNameParts = explode('\\', $selfClass);
        $selfName = array_pop($selfNameParts);
        $selfPrimaryKey = $selfClass::$primaryKey;
        
		$selectStatement .= ' FROM ' . $selfClass::$tableName;
		$statement = $selectStatement . $statement;
		
		if (isset($this->_tmp['predicate'])) {
			$statement .= ' ' . $this->_tmp['predicate']->getFormattedQuery();
        }
        
		$arguments = $this->_tmp['arguments'];
		        
		$fetchedData = self::$db->query($statement, (isset($arguments) ? $arguments : null));
        $fetchedObjects = [];
		
		foreach ($fetchedData as $data) {
			$model = new $selfClass();
            
			foreach ($data as $key => $value) {
				if (strpos($key, "$selfName.") === 0) {
                    $key = str_replace("$selfName.", '', $key);
                    
                    if ($key !== $selfPrimaryKey) {
                        $oldKey = "__old_$key";
                        $model->{$oldKey} = $value;
                    }
                }
				                
				$model->{$key} = $value;
			}
			array_push($fetchedObjects, $model);
		}
		
        if (!$returnRaw) {
            $joinDefinitions = $this->_tmp['joins'];
            $returningObjects = [];
            $fetchedObjectsCount = count($fetchedObjects);
            
            for ($i = 0; $i < $fetchedObjectsCount; $i++) {
                $fetchedObject = $fetchedObjects[$i];
                
                foreach ($joinDefinitions as $joinDefinition) {
                    $parentModelName = $joinDefinition['parent_model'];
                    $childModelName = $joinDefinition['child_model'];
                    
                    $parentModel = $fetchedObject->getRelatingModel($parentModelName);
                    $childModel = $fetchedObject->getRelatingModel($childModelName);
                    
                    self::mapModelRelationships($parentModel, $childModel, $joinDefinition);
                }
                
                $modelToAdd = $fetchedObject->getRelatingModel($selfName);
                if (!in_array($modelToAdd, $returningObjects)) {
                    array_push($returningObjects, $modelToAdd);
                }
            }
            
            $fetchedObjects = $returningObjects;
        }
        
		$this->_tmp = null;
		
		return $fetchedObjects;
	}
	
	public function update()
	{
		$selfName = get_called_class();
        $selfPrimaryKey = $selfName::$primaryKey;
		$statement = 'UPDATE ' . self::$db->dbName . '.' . $selfName::$tableName . ' SET ';
		$arguments = array();
		
		$first = true;
		foreach ($this as $key => $value) {
			if (preg_match('/__old_/', $key) || !array_key_exists($key, $this->columns) || $key === $selfPrimaryKey) {
				continue;
			}
			
			$oldKey = "__old_$key";
			$oldValue = $this->{$oldKey};
			
			if ($value != $oldValue) {
				if ($first) {
					$first = false;
                } else {
					$statement .= ', ';
                }
                
                $value = $this->getDatabaseValue($key, $value);
				$statement .= "$key = ?";
				array_push($arguments, $value);
			}
		}
		
		if (count($arguments) > 0) {
			$statement .= " WHERE $selfPrimaryKey = " . $this->{$selfPrimaryKey};
            $result = call_user_func_array([self::$db, 'query'], $arguments);
            
			return $result;
		}
		
		return true;
	}
	
	public function delete()
	{
		$selfName = get_class($this);
        $selfPrimaryKey = $selfName::$primaryKey;
        $selfTableName = $selfName::$tableName;
		return (bool)self::$db->query("DELETE FROM $selfTableName WHERE $selfPrimaryKey = ?", $this->{$selfPrimaryKey});
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
	
    /**
     * Converts '$value' into a safe database value for the column we're about to insert the data into.
     * @param   string  $columnName     The key to get the type of column from '$this->columns'.
     * @param   mixed   $value          The value to be converted.
     * @return  mixed                   The value converted to the type of column declared.
     */
    protected function getDatabaseValue($columnName, $value)
    {
        $columns = $this->columns;
        
        if (array_key_exists($columnName, $columns)) {
            $columnType = $columns[$columnName];
            
            switch ($columnType) {
                case 'string':
                    $value = strval($value);
                    break;
                case 'integer':
                    $value = intval($value);
                    break;
                case 'boolean':
                    $value = boolval($value) ? 1 : 0;
                    break;
                case 'date':
                    // TODO: convert possible date format into UTC timestamp.
                    break;
                default:
                    break;
            }
        }
        
        return $value;
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