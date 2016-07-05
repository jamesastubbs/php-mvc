<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation\Model;

use \PHPMVC\Foundation\Application;
use \PHPMVC\Foundation\Model\ClassResolver;
use \PHPMVC\Foundation\Model\ModelPredicate;

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
    public static $columns;
    protected static $db = null;
    protected static $_cachedModels = [];
    private $isSaved = false;
	private $_tmp;
	
	final public function __construct()
	{
		parent::__construct(array(), \ArrayObject::ARRAY_AS_PROPS);
	}
	
    final public static function setDB($db)
    {
        self::$db = $db;
        $selfClass = get_called_class();
    }
    
    final protected static function getDB()
    {
        return self::$db;
    }
    
	final protected function getTmp($key)
	{
		if (!isset($this->_tmp)) {
			return null;
        }
        
		return $this->_tmp[$key];
	}
	
	final protected function setTmp($key, $value)
	{
		if (!isset($this->_tmp)) {
			$this->_tmp = [];
        }
        
		$this->_tmp[$key] = $value;
	}
	
    /**
     * Returns a model object with the type of '$relatingModelName'.
     * The values of the relating model object are automatically populated from this model instance having performed a join query.
     * @param       string  $relatingModelName      The class name of the relating model object.
     * @return      mixed                           The relating model object.
     */
    final public function getRelatingModel($relatingModelClassName)
    {
        $selfClass = get_class($this);
        $relatingModelClass = ClassResolver::resolve($relatingModelClassName, $relatingModelName);
        
        $relatingModelPrimaryKey = $relatingModelClass::$primaryKey;
        $relatingModel = new $relatingModelClass();
        $modelIsThisClass = ($relatingModelClass === $selfClass);
        
        if ($modelIsThisClass) {
            $relatingModelPrimaryKeyValue = "{$this->{$relatingModelPrimaryKey}}";
        } else {
            $relatingModelPrimaryKeyValue = "{$this->{"$relatingModelName.$relatingModelPrimaryKey"}}";
        }
        
        if (isset(self::$_cachedModels[$relatingModelClass]) && isset(self::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue])) {
            // if relating model has already been set up and stored, return the cached value.
            return self::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue];
        }
        
        $relatingModelColumns = array_keys($relatingModelClass::$columns);
        
        foreach ($relatingModelColumns as $columnName) {
			$key = ($modelIsThisClass ? '' : "$relatingModelName.") . $columnName;
            $value = $this[$key];
			$relatingModel->{$columnName} = $value;
            
            if ($columnName !== $relatingModelPrimaryKey) {
                $oldKey = "__old_$columnName";
                $relatingModel->{$oldKey} = $value;
            }
		}
        
        self::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue] = $relatingModel;
        
        return $relatingModel;
    }
    
    final private static function mapModelRelationships($parentModel, $childModel, $joinDefinition)
    {
        $parentModelClass = get_class($parentModel);
        $childModelName = get_class($childModel);
        preg_match_all('/^([A-Za-z0-9_]+)\\\Model\\\([A-Za-z0-9_]+)$/', $childModelName, $childModelMatches);
        array_shift($childModelMatches);
        $childModelName = $childModelMatches[0][0] . ':' . $childModelMatches[1][0];
        
        $relationshipProperty = lcfirst($childModelMatches[1][0]);
        
        $joinDefinition['method'] = 'guess';
        $relationships = $parentModelClass::$relationships;
        
        foreach ($relationships as $relationshipName => $relationship) {
            // if relationship is defined in class, we'll go by that definition.
            if ($relationship['model'] === $childModelName) {
                
                // get reverse relationship if the current is defined as an 'inverse'.
                if (isset($relationship['inverse'])) {
                    $relationship = self::getReverseRelationship($relationship);
                }
                
                // change join method, so that we don't guess the relationship name.
                $joinDefinition['method'] = $relationship['relationship'];
                
                $joinDefinition['property_key'] = $relationshipName;
                $joinDefinition['primary_key'] = $relationship['column'];
                
                // set the foreign key as the same column name of the primary key, if the key 'joinColumn' doesn't exist.
                $joinDefinition['foreign_key'] = isset($relationship['joinColumn']) ? $relationship['joinColumn'] : $relationship['column'];
                
                // store the relationship name as we'll use this as the property name to store the relationship array.
                $relationshipProperty = $relationshipName;
                
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
                
                // TODO: investigate why the child mode is being added twice, then fix.
                if (!in_array($childModel, $parentModel->{$propertyKey})) {
                    $parentModel->{$propertyKey}[] = $childModel;
                }
            } else {
                $parentModel->{$propertyKey} = $childModel;
            }
        } else {
            $add = true;
            $childModelKey = lcfirst($childModelMatches[1][0]);
            
            if (property_exists($parentModel, $relationshipProperty)) {
                $storedChildModel = $parentModel->{$relationshipProperty};
                
                if ($storedChildModel === $childModel) {
                    $add = false;
                } else {
                    unset($parentModel->{$childModelKey});
                    $parentModel->{$childModelKey . 's'} = [$storedChildModel];
                }
            }
            
            if ($add) {
                if (property_exists($parentModel, $relationshipProperty . 's')) {
                    if (!in_array($childModel, $parentModel->{$relationshipProperty. 's'})) {
                        array_push($parentModel->{$childModelKey. 's'}, $childModel);
                    }
                } else {
                    $parentModel->{$relationshipProperty} = $childModel;
                }
            }
        }
    }
    
    public static function findAll()
    {
        $selfClass = get_called_class();
        $models = $selfClass::select();
        
        return $models;
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
		$columns = array_keys($selfName::$columns);
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
				    $model->{$oldKey} = self::getColumnValue($key, $value, false);
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
					
                    // TODO: refactor column name resolution.
                    // use either regex or explode, not both.
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
        }
        
		$model = new $selfName();
		$model->setTmp('state', 1);
		$model->setTmp('statement', $statement);
		$model->setTmp('predicate', $predicate);
		$model->setTmp('arguments', (isset($predicate) ? $predicate->arguments : null));
		
		return $model;
	}
    
    /**
     * Automatically builds the join queries based on the relationships declared in the '$parentModel'.
     * @param   string      $parentModel
     * @return  Model
     */
    /*
    public function innerJoinRelationships($parentModel = null)
    {
        return $this->joinRelationships('inner', $parentModel);
    }
    
    public function leftJoinRelationships($parentModel = null)
    {
        return $this->joinRelationships('left', $parentModel);
    }
    
    protected function joinRelationships($joinMethod, $parentModel = null)
    {
        $joinMethod = $joinMethod . 'Join';
        $selfClass = get_class($this);
        $class = ClassResolver::resolve($parentModel === null ? $parentModel : $selfClass);
        $relationshipsNames = array_keys($class::$relationships);
        $model = $this;
        
        foreach ($relationshipsNames as $relationshipName) {
            $relationship = $class::getRelationship($relationshipName);
            $model = $model->{$joinMethod}($class, $relationship['model'])->on($relationship['joinColumn'], $relationship['column']);
        }
        
        return $this;
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
        $selfClass = get_called_class();
        
		if (isset($this->_tmp['joins'])) {
			$joinDefinitions = $this->_tmp['joins'];
            $joinDefinitionsCount = count($joinDefinitions);
            
            for ($i = 0; $i < $joinDefinitionsCount; $i++) {
                $joinDefinition = $joinDefinitions[$i];
				$joinMethod = $joinDefinition['method'];
                $tableName = $joinDefinition['child_model'];
				$childModel = ClassResolver::resolve($tableName);
                
				$parentTableName = $joinDefinition['parent_model'];
				$parentModel = ClassResolver::resolve($parentTableName);
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
                    var_dump($joinDefinition);
					die(__FILE__ . ':' . __LINE__);
                }
                
				$statement .= " $joinMethod JOIN $tableName ON $parentTableName.$primaryKey = $tableName.$foreignKey";
            }
			
			$columns = [];
            //$modelNamespaceRegex = '/' . $this->modelNamespace . '\/';
            
			foreach ($relationships as $parentModelClass => $parentRelationships) {
				if (!array_key_exists($parentModelClass, $columns)) {
                    $parentModelNameParts = explode('\\', $parentModelClass);
                    $parentModelName = array_pop($parentModelNameParts);
                    
					$modelColumns = [];
                    $parentModelColumnsKeys = array_keys($parentModelClass::$columns);
                    
					foreach ($parentModelColumnsKeys as $modelColumnName) {
                        $columnString = ($parentModelClass::$tableName . ".$modelColumnName AS '$parentModelName.$modelColumnName'");
						array_push($modelColumns, $columnString);
					}
                    
					$columns[$parentModelName] = $modelColumns;
				}
				
				foreach ($parentRelationships as $childModelClass => $childRelationship) {
					if (!array_key_exists($childModelClass, $columns)) {
						$childModelNameParts = explode('\\', $childModelClass);
                        $childModelName = array_pop($childModelNameParts);
                        
                        $modelColumns = [];
                        $childModelColumnsKeys = array_keys($childModelClass::$columns);
                        
						foreach ($childModelColumnsKeys as $modelColumnName) {
							$columnString = ($childModelClass::$tableName . ".$modelColumnName AS '$childModelName.$modelColumnName'");
                            array_push($modelColumns, $columnString);
						}
                        
						$columns[$childModelName] = $modelColumns;
					}
				}
			}
		}
        
		$selectStatement = 'SELECT ';
        
		if (gettype($columns) == 'string') {
			$selectStatement .= "$columns ";
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
			$statement .= ' ' . $this->_tmp['predicate']->getFormattedQuery(
                $selfClass === \JStubbsCMS\Model\Section::class ? \JStubbsCMS\Model\Section::class : null
            );
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
                
                $modelToAdd = $fetchedObject->getRelatingModel($selfClass);
                if (!in_array($modelToAdd, $returningObjects)) {
                    array_push($returningObjects, $modelToAdd);
                }
            }
            
            $fetchedObjects = $returningObjects;
        }
        
		$this->_tmp = null;
        
		return $fetchedObjects;
	}
	*/
    
	public function update()
	{
		$selfName = get_called_class();
        $selfPrimaryKey = $selfName::$primaryKey;
		$statement = 'UPDATE ' . self::$db->dbName . '.' . $selfName::$tableName . ' SET ';
		$arguments = array();
        $columns = $selfName::$columns;
		
		$first = true;
		foreach ($this as $key => $value) {
			if (preg_match('/__old_/', $key) || !array_key_exists($key, $columns) || $key === $selfPrimaryKey) {
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
                
                $value = self::getColumnValue($key, $value);
                $this->{$key} = $value;
				$statement .= "$key = ?";
				array_push($arguments, $value);
			}
		}
		
		if (count($arguments) > 0) {
			$statement .= " WHERE $selfPrimaryKey = " . $this->{$selfPrimaryKey};
            $result = call_user_func_array([self::$db, 'query'], [$statement, $arguments]);
            
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
	
    // TODO: decide if this is being used. If not, deprecate or delete.
	public static function columns()
	{
		$selfName = get_called_class();
		$tableName = $selfName::$tableName;
		$model = new $selfName(null);
		$_columns = $selfName::$columns;
		$model = null;
		
		$columns = array();
		foreach (array_keys($_columns) as $columnName) {
			$columnName = $tableName . ".$columnName";
			array_push($columns, $columnName);
		}
		
		return array_keys($columns);
	}
    
    public function toJSON($outputObjects = false, $callingClass = null)
    {
        $data = [];
        
        $selfClass = get_class($this);
        if ($callingClass === null) {
            $callingClass = $selfClass;
        }
        $columns = array_keys($selfClass::$columns);
        $relationships = $selfClass::$relationships;
        
        foreach ($columns as $column) {
            $data[$column] = $this->{$column};
        }
        
        foreach ($relationships as $relationshipName => $relationship) {
            if ($relationship['relationship'] === self::RELATIONSHIP_ONE_TO_MANY || $relationship['relationship'] === self::RELATIONSHIP_MANY_TO_MANY) {
                $childData = [];
                $childModels = $this->{$relationshipName};
                
                foreach ($childModels as $childModel) {
                    if ($callingClass === get_class($childModel)) {
                        continue;
                    }
                    
                    $childData[] = $childModel->toJSON($outputObjects, $callingClass);
                }
                
                $data[$relationshipName] = $childData;
            } else {
                $data[$relationshipName] = $this->{$relationshipName};
            }
        }
        
        if ($outputObjects) {
            return $data;
        }
        
        return json_encode($data, Application::getConfigValue('DEBUG') ? JSON_PRETTY_PRINT : 0);
    }
    
    /**
     * Converts '$value' into a safe database value for the column we're about to insert the data into.
     * @param   string  $columnName     The key to get the type of column from 'self::$columns'.
     * @param   mixed   $value          The value to be converted.
     * @return  mixed                   The value converted to the type of column declared.
     */
    protected static function getColumnValue($columnName, $value, $toDatabase = true)
    {
        $selfClass = get_called_class();
        $columns = $selfClass::$columns;
        
        if (array_key_exists($columnName, $columns)) {
            $columnType = $columns[$columnName];
            
            switch ($columnType) {
                case self::COLUMN_STRING:
                    $value = strval($value);
                    break;
                case self::COLUMN_INTEGER:
                    $value = intval($value);
                    break;
                case self::COLUMN_BOOLEAN:
                    $value = boolval($value);
                    
                    if ($toDatabase) {
                        $value = $value ? 1 : 0;
                    }
                    break;
                case self::COLUMN_DATE:
                    // TODO: convert possible date format into UTC timestamp.
                    break;
                default:
                    break;
            }
        }
        
        return $value;
    }
    
    /**
     * Returns the relationship of the key '$relationshipName' declared in the calling model class.
     * If 'inverse' has been set within the found relationship, an inverted relationship produced from the opposing model and returned. 
     * @param       string      $relationshipName       The name of the relationship to return.
     * @return      array                               The found relationship or an inverted relationship. 'null' is returned if no relationship is found.
     */
    /*
    protected static function getRelationship($relationshipName)
    {
        $selfClass = get_called_class();
        $relationships = $selfClass::$relationships;
        
        if (isset($relationships[$relationshipName])) {
            $relationship = $relationships[$relationshipName];
            
            // if 'inverse' is set, calculate relationship based on other model's declaration.
            if (isset($relationship['inverse'])) {
                $modelClass = ClassResolver::resolve($relationship['model']);
                $modelRelationships = $modelClass::$relationships;
                $modelRelationship = $modelRelationships[$relationship['inverse']];
                
                $relationship = [
                    'column' => isset($modelRelationship['joinColumn']) ? $modelRelationship['joinColumn'] : $modelRelationship['column'],
                    'joinColumn' => $modelRelationship['column'],
                    'model' => $relationship['model'],
                    'relationship' => $selfClass::getReverseRelationshipType($modelRelationship['relationship'])
                ];
            }
            
            if (!isset($relationship['joinColumn'])) {
                $relationship['joinColumn'] = $relationship['column'];
            }
            
            return $relationship;
        }
        
        return null;
    }
    */
    
    /**
     * The opposite value of '$relationship' is returned.
     *
     * @param       array       $relationship       The relationship value to reverse.
     *
     * @return      array                           The reverse relationship value.
     */
    public static function getReverseRelationship(array $relationship)
    {
        $inversingRelationship = null;
        $mappedRelationship = null;
        
        $model = ClassResolver::resolve($relationship['model']);
        
        if (!class_exists($model)) {
            throw new \Exception("The class '$model' which has been resolved from '{$relationship['model']}' does not exist.");
        }
        
        $reverseKey = null;
        
        if (isset($relationship['inverse'])) {
            $reverseKey = 'inverse';
        } else if (isset($relationship['mappedBy'])) {
            $reverseKey = 'mappedBy';
        } else {
            // TODO: create better exception messages to reveal the problematic relationship.
            throw new \Exception('Relationship has not been configured for reversing.');
        }
        
        $relationshipName = $relationship[$reverseKey];
        $modelRelationships = $model::$relationships;
        
        if (!isset($modelRelationships[$relationshipName])) {
            throw new \Exception("Relationship '$relationshipName' doesn't exist in the Model '$model'.");
        }
        
        $inversingRelationship = $reverseKey === 'inverse' ? $modelRelationships[$relationshipName] : $relationship;
        $mappedRelationship = $reverseKey === 'inverse' ? $relationship : $modelRelationships[$relationshipName];
        
        // check to see if mapped relationship has all properties set.
        if (!(isset($mappedRelationship['column']) && isset($mappedRelationship['model']) && isset($mappedRelationship['inverse']) && isset($mappedRelationship['relationship']))) {
            $missing = [];
            
            if (!isset($mappedRelationship['column'])) { $missing[] = 'column'; }
            if (!isset($mappedRelationship['model'])) { $missing[] = 'model'; }
            if (!isset($mappedRelationship['inverse'])) { $missing[] = 'inverse'; }
            if (!isset($mappedRelationship['relationship'])) { $missing[] = 'relationship'; }
            
            throw new \Exception('Mapped relationship has not been configured for reversing. Missing properties: [\'' . implode('\', \'', $missing) . '\'].');
        }
        
        // check to see if inversing relationship has all properties set.
        if (!(isset($inversingRelationship['model']) && isset($inversingRelationship['mappedBy']))) {
            $missing = [];
            
            if (!isset($inversingRelationship['model'])) { $missing[] = 'model'; }
            if (!isset($inversingRelationship['mappedBy'])) { $missing[] = 'mappedBy'; }
            
            throw new \Exception("Relationship '$relationshipName' has not been configured for reversing. Missing properties: [' " . implode('\', \'', $missing) . "'].");
        }
        
        $column = $mappedRelationship['column'];
        $joinColumn = $column;
        $model = $reverseKey === 'inverse' ? $inversingRelationship['model'] : $mappedRelationship['model'];
        
        $relationshipType = $reverseKey === 'inverse' ? self::getReverseRelationshipType($mappedRelationship['relationship']) : $mappedRelationship['relationship'];
        
        if ($relationshipType === $mappedRelationship['relationship']) {
            //die(__FILE__ . ':' . __LINE__);
        } else if (isset($relationship['mappedBy']) && $relationship['mappedBy'] === 'entries') {
            /*
            var_dump($reverseKey);
            var_dump($mappedRelationship['relationship']);
            var_dump($relationship);
            var_dump($relationshipType);
            die(__FILE__ . ':' . __LINE__);
            */
        }
        
        if (isset($mappedRelationship['joinColumn'])) {
            // swap the columns as we're still reversing the relationship.
            $joinColumn = $column;
            $column = $mappedRelationship['joinColumn'];
        }
        
        $reverseRelationship = array_merge($relationship, []);
        
        $reverseRelationship['column'] = $column;
        $reverseRelationship['joinColumn'] = $joinColumn;
        $reverseRelationship['model'] = $model;
        $reverseRelationship['relationship'] = $relationshipType;
        
        return $reverseRelationship;
    }
    
    /**
     * The opposite value of '$relationshipType' is returned.
     *
     * @param       integer     $relationshipType   The relationship type value to reverse.
     *
     * @return      integer                         The reverse relationship type value.
     */
    protected static function getReverseRelationshipType($relationshipType)
    {
        switch ($relationshipType) {
            case self::RELATIONSHIP_MANY_TO_ONE:
                $relationshipType = self::RELATIONSHIP_ONE_TO_MANY;
            case self::RELATIONSHIP_ONE_TO_MANY:
                $relationshipType = self::RELATIONSHIP_MANY_TO_ONE;
                break;
            case self::RELATIONSHIP_MANY_TO_MANY:
                // no break.
            case self::RELATIONSHIP_ONE_TO_ONE:
                // no break.
            default:
                break;
        }
        
        return $relationshipType;
    }
    
    public static function getRelationship($relationshipName)
    {
        $selfClass = get_called_class();
        
        if (!isset($selfClass::$relationships[$relationshipName])) {
            throw new \Exception("Relationship '$relationshipName' not found in model '$selfClass'.");
        }
        
        $relationship = $selfClass::$relationships[$relationshipName];
        
        if (isset($relationship['mappedBy']) && (!isset($relationship['column']) || !isset($relationship['relationship']))) {
            $reverseModel = ClassResolver::resolve($relationship['model']);
            $reverseRelationship = $reverseModel::getRelationship($relationship['mappedBy']);
            
            $relationship['column'] = isset($reverseRelationship['joinColumn']) ? $reverseRelationship['joinColumn'] : $reverseRelationship['column'];
            $relationship['joinColumn'] = $reverseRelationship['column'];
            $relationship['relationship'] = self::getReverseRelationshipType($reverseRelationship['relationship']);
        }
        
        return $relationship;
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