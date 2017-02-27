<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation\Model;

use PHPMVC\Foundation\Application;
use PHPMVC\Foundation\Model\ClassResolver;
use PHPMVC\Foundation\Model\Relationship\ToManyRelationship;
use PHPMVC\Foundation\Model\Relationship\ToOneRelationship;

abstract class Model
{
    const COLUMN_BOOLEAN = 'boolean';
    const COLUMN_DATE = 'date';
    const COLUMN_INTEGER = 'integer';
    const COLUMN_STRING = 'string';
    
    const RELATIONSHIP_UNKNOWN = -1;
    const RELATIONSHIP_ONE_TO_ONE = 0;
    const RELATIONSHIP_ONE_TO_MANY = 1;
    const RELATIONSHIP_MANY_TO_ONE = 2;
    const RELATIONSHIP_MANY_TO_MANY = 3;
    
    private $_attributes = [];
    private $_cachedAttributes = [];
    private $_relationships = [];
    public static $primaryKey = null;
    public static $relationships = [];
    public static $tableName;
    public static $columns;
    private static $tmpID = 0;
    protected static $db = null;
    protected static $_cachedModels = [];
    protected static $_cacheSetup = false;
    
    public function __construct()
    {
        if (Model::$_cacheSetup === null) {
            // TODO: initalise cache handler.
        }
        
        $selfClass = get_class($this);
        $primaryKey = $selfClass::$primaryKey;
        $columns = $selfClass::$columns;
        $relationships = $selfClass::$relationships;
        
        foreach (array_keys($columns) as $column) {
            $this->_attributes[$column] = null;
            $this->_cachedAttributes[$column] = null;
        }
        
        foreach ($relationships as $relationshipName => $relationship) {
            $modelClass = ClassResolver::resolve($relationship['model']);
            $relationshipType = isset($relationship['relationship']) ? $relationship['relationship'] : $selfClass::getRelationship($relationshipName)['relationship'];
            
            if ($relationshipType === Model::RELATIONSHIP_ONE_TO_MANY || $relationshipType === Model::RELATIONSHIP_MANY_TO_MANY) {
                $this->_relationships[$relationshipName] = new ToManyRelationship($modelClass);
            } else {
                $this->_relationships[$relationshipName] = new ToOneRelationship($modelClass);
            }
        }
        
        $this->{$primaryKey} = self::getTmpID();
    }
    
    final public static function setDB($db)
    {
        self::$db = $db;
        $selfClass = get_called_class();
    }
    
    /*
    final public static function getStored($id)
    {
        $model = null;
        $selfClass = get_called_class();
        
        if (isset(self::$_storage[$selfClass]) && isset(self::$_storage[$selfClass]["$id"])) {
            $model = self::$_storage[$selfClass]["$id"];
        }
        
        return $model;
    }
    */
    
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
        
        if (isset(Model::$_cachedModels[$relatingModelClass]) && isset(Model::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue])) {
            // if relating model has already been set up and stored, return the cached value.
            return Model::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue];
        }
        
        $relatingModelColumns = array_keys($relatingModelClass::$columns);
        
        foreach ($relatingModelColumns as $columnName) {
			$key = ($modelIsThisClass ? '' : "$relatingModelName.") . $columnName;
            $value = $this[$key];
			$relatingModel->{$columnName} = $value;
            
            if ($columnName !== $relatingModelPrimaryKey) {
                $_cachedAttributes[$columnName] = $value;
            }
		}
        
        Model::$_cachedModels[$relatingModelClass][$relatingModelPrimaryKeyValue] = $relatingModel;
        
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
        $primaryKey = $selfClass::$primaryKey;
        
        $modelClass = ClassResolver::shorten($selfClass, $modelName);
        $alias = strtolower(substr($modelName, 1, 1));
        
        $models = ModelQueryBuilder::select($modelClass, $alias)
            ->where("$alias.$primaryKey = ?", $id)
            ->limit(1)
            ->getResult();
        
        $model = empty($models) ? null : $models[0];
        
        return $model;
    }
    
	public function create($fetchAfter = false)
	{
        $db = self::$db;
		$selfClass = get_class($this);
        $primaryKey = $selfClass::$primaryKey;
        $selfRelationships = $selfClass::$relationships;
        $tableName = $selfClass::$tableName;
		$statement = "INSERT INTO {$db->dbName}.{$tableName}(";
		$statementPart = ') VALUES (';
		$first = true;
		$arguments = [];
		
        foreach ($this->_relationships as $relationshipName => $relationship) {
            if (!isset($selfRelationships[$relationshipName])) {
                throw new \Exception("A relationship with the name of '$relationshipName' was not defined in the mode of '$selfClass'.");
            }
            
            $relationshipDefinition = $selfClass::getRelationship($relationshipName);
            $type = $relationshipDefinition['relationship'];
            
            if ($type === self::RELATIONSHIP_MANY_TO_ONE || $type === self::RELATIONSHIP_ONE_TO_ONE) {
                $relatedModel = $relationship->get();
                
                // TODO: implement support for optional relationships.
                if ($relatedModel !== null) {
                    $joinKey = $relationshipDefinition['column'];
                    $foreignKey = isset($relationshipDefinition['joinColumn']) ? $relationshipDefinition['joinColumn'] : $relationshipDefinition['column'];
                    
                    $this->_attributes[$foreignKey] = $relatedModel->{$joinKey};
                }
            }
        }
        
		foreach ($this->_attributes as $key => $value) {
            if ($key === $primaryKey || $value === null) {
                continue;
            }
            
			if ($first) {
				$first = false;
			} else {
				$statement .= ', ';
				$statementPart .= ', ';
			}
			
            $statement .= $key;
			$statementPart .= '?';
            
			$arguments[] = $selfClass::getColumnValue($key, $value);
		}
		
		$statement .= $statementPart . ');';
		
		$lastInsertId = self::$db->query($statement, !empty($arguments) ? $arguments : null);
		$this->{$primaryKey} = $lastInsertId;
        
		if ($fetchAfter && !empty($columns)) {
			$statement = 'SELECT ';
			$isFirst = true;
            
			foreach ($columns as $column) {
				if ($isFirst) {
					$isFirst = false;
				} else {
					$statement .= ', ';
                }
                
				$statement .= $column;
			}
            
			$statement .= " FROM $tableName WHERE $primaryKey = ? LIMIT 1";
			$result = $db->query($statement, $this->{$primaryKey});
			
			if (empty($result)) {
				return false;
			}
			
			$result = $result[0];
			
			foreach ($columns as $column) {
				if (array_key_exists($column, $result)) {
					$this->_attributes[$column] = $result[$column];
                    $this->_cachedAttributes[$column] = $result[$column];
				}
			}
		}
		
        Model::cacheModel($this);
        
		return (bool)$this->{$primaryKey};
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
				$model->_attributes[$key] = $value;
                
                if ($key !== $selfName::$primaryKey) {
				    $model->{"__old_$key"} = self::getColumnValue($key, $value, false);
                }
			}
			array_push($fetchedObjects, $model);
		}
        
		return $fetchedObjects;
	}
	
	public function update()
	{
		$selfClass = get_called_class();
        $primaryKey = $selfClass::$primaryKey;
        $db = self::$db;
		$dbName = $db->dbName;
        $tableName = $selfClass::$tableName;
        $statement = "UPDATE $dbName.$tableName SET ";
		$arguments = [];
        $columns = $selfClass::$columns;
		
        $first = true;
        
		foreach ($this->_attributes as $key => $value) {
			if (!array_key_exists($key, $columns) || $key === $primaryKey) {
				continue;
			}
			
			$oldValue = $this->_cachedAttributes[$key];
			
			if ($value != $oldValue) {
				if ($first) {
					$first = false;
                } else {
					$statement .= ', ';
                }
                
                $value = $selfClass::getColumnValue($key, $value);
                $this->{$key} = $value;
				$statement .= "$key = ?";
				$arguments[] = $value;
			}
		}
		
		if (!empty($arguments)) {
			$statement .= " WHERE $primaryKey = " . $this->{$primaryKey};
            
            $result = call_user_func_array([$db, 'query'], [$statement, $arguments]);
            
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
    
    /**
     * Returns a JSON formatted value of the model together with it's relating models.
     * By default, the returning value is a JSON string of the model unless '$outputObjects' has been set accordingly.
     *
     * @param   boolean     $outputObjects      'true' if we want the returning value not to be an encoded JSON string.
     * @param   array       $outputLog          Used internally to avoid infinate recursive calls. Should never be manually set.
     *
     * @return  mixed       The JSON result.
     */
    public function toJSON($outputObjects = false, &$outputLog = [])
    {
        // set up variables and retrieve model columns.
        // $callingClass = get_class($callingModel);
        $data = [];
        $outputRelationships = true;
        $selfClass = get_class($this);
        $primaryKeyValue = $this->{$selfClass::$primaryKey};
        $selfClass = get_class($this);
        $columns = array_keys($selfClass::$columns);
        
        // set up an array for the current calling class if it doesn't exist in the output log.
        if (!isset($outputLog[$selfClass])) {
            $outputLog[$selfClass] = [];
        }
        
        // if the ID of the current model exists in the log...
        if (in_array($primaryKeyValue, $outputLog[$selfClass])) {
            // ... do not recursively call this function again for the relationships
            // as this will result in an infinate callback.
            $outputRelationships = false;
        } else {
            // otherwise, add the ID to the log as this indicates the relating models will use the recursive callback.
            $outputLog[$selfClass][] = $primaryKeyValue;
        }
        
        // iterate through each of the model's columns and add the associated values to '$data'.
        foreach ($columns as $column) {
            $data[$column] = $this->{$column};
        }
        
        // check to see if we have marked the current model to output it's relationships.
        if ($outputRelationships) {
            // iterate through the current model's relationships and get the JSON values from the relating models.
            // append the JSON data to '$data'.
            foreach ($this->_relationships as $relationshipName => $relationship) {
                if (get_class($relationship) === ToManyRelationship::class) {
                    $childData = [];
                    $models = $relationship->getAll();
                    
                    foreach ($models as $model) {
                        $childData[] = $model->toJSON(true, $outputLog);
                    }
                    
                    $data[$relationshipName] = $childData;
                } else {
                    $model = $relationship->get();
                    
                    if ($model !== null) {
                        $modelClass = get_class($model);
                        $modelPrimaryKeyValue = $modelClass::$primaryKey;
                        
                        if (!isset($outputLog[$modelClass]) || !in_array($modelPrimaryKeyValue, $outputLog[$modelClass])) {
                            $data[$relationshipName] = $model->toJSON(true, $outputLog);
                        }
                    }
                }
            }
        }
        
        // if we have told the function not to encode the data,
        // return the data as is.
        if ($outputObjects) {
            return $data;
        }
        
        // otherwise, encode the data and return the resulting strong.
        return json_encode($data, Application::getConfigValue('DEBUG') ? JSON_PRETTY_PRINT : 0);
    }
    
    /**
     * Converts '$value' into a safe database value for the column we're about to insert the data into.
     *
     * @param   string  $columnName     The key to get the type of column from 'self::$columns'.
     * @param   mixed   $value          The value to be converted.
     *
     * @return  mixed                   The value converted to the type of column declared.
     */
    public static function getColumnValue($columnName, $value, $toDatabase = true)
    {
        $selfClass = get_called_class();
        $columns = $selfClass::$columns;
        
        if ($columns === null) {
            var_dump($columns);
            var_dump($selfClass);
            die(__FILE__ . ':' . __LINE__);
        }
        
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
                    if ($toDatabase) {
                        $value = $value->format('Y-m-d H:i:s');
                    } else {
                        $value = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    }
                    break;
                default:
                    break;
            }
        }
        
        return $value;
    }
    
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
            return null;
        }
        
        $relationshipName = $relationship[$reverseKey];
        $reverseRelationship = $model::getRelationship($relationshipName);
        
        $inversingRelationship = $reverseKey === 'inverse' ? $model::getRelationship($relationshipName) : $relationship;
        $mappedRelationship = $reverseKey === 'inverse' ? $relationship : $model::getRelationship($relationshipName);
        
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

        if ($relationshipType === self::RELATIONSHIP_MANY_TO_MANY) {
            $reverseRelationship['joinTable'] = isset($mappedRelationship['joinTable']) ? $mappedRelationship['joinTable'] : $mappedRelationship['joinTable'];
        }

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
                break;
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

            if ($relationship['relationship'] === self::RELATIONSHIP_MANY_TO_MANY) {
                $relationship['joinTable'] = $reverseRelationship['joinTable'];
            }
        }
        
        return $relationship;
    }
    
    public function __get($name)
    {
        $selfClass = get_class($this);
        
        if (isset($selfClass::$relationships[$name])) {
            return $this->_relationships[$name];
        }
        
        if (array_key_exists($name, $this->_attributes)) {
            return $this->_attributes[$name];
        }
        
        return null;
    }
    
    public function __set($name, $value)
    {
        $isOldValue = false;
        $selfClass = get_class($this);
        
        if (strstr($name, '__old_') !== false) {
            $isOldValue = true;
            $name = str_replace('__old_', '', $name);
        }
        
        if (isset($selfClass::$columns[$name])) {
            if ($isOldValue) {
                $this->_cachedAttributes[$name] = $value;
            } else {
                $this->_attributes[$name] = $value;
            }
        }
    }
    
    public static function getCachedModel($modelClass, $modelID)
    {
        if (isset(Model::$_cachedModels[$modelClass]["$modelID"])) {
            return Model::$_cachedModels[$modelClass]["$modelID"];
        }
        
        return null;
    }
    
    public static function cacheModel(Model $model)
    {
        $modelClass = get_class($model);
        $privateKey = $modelClass::$primaryKey;
        $modelID = $model->{$privateKey};
        
        if (!isset(Model::$_cachedModels[$modelClass])) {
            Model::$_cachedModels[$modelClass] = [];
        }
        
        Model::$_cachedModels[$modelClass]["$modelID"] = $model;
    }
    
    protected static function getTmpID()
    {
        Model::$tmpID--;
        
        return Model::$tmpID;
    }
}
