<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation\Model;

use PHPMVC\Foundation\Application;
use PHPMVC\Foundation\Model\ClassResolver;
use PHPMVC\Foundation\Model\ModelQueryBuilder;
use PHPMVC\Foundation\Model\Relationship\ToManyRelationship;
use PHPMVC\Foundation\Model\Relationship\ToOneRelationship;
use PHPMVC\Foundation\Service\DBService;
use PHPMVC\Foundation\Services;

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

    /**
     * @var  DBService
     */
    private $_dbService = null;

    /**
     * @var  Services
     */
    protected $services = null;

    public static $primaryKey = null;
    public static $relationships = [];
    public static $tableName;
    public static $columns = [];
    private static $tmpID = 0;

    /**
     * @var  DBService
     */
    protected static $dbService = null;

    protected static $_cachedModels = [];
    
    public function __construct()
    {
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
            } elseif ($relationshipType === Model::RELATIONSHIP_MANY_TO_ONE || $relationshipType === Model::RELATIONSHIP_ONE_TO_ONE) {
                $this->_relationships[$relationshipName] = new ToOneRelationship($modelClass);
            }
        }
        
        $this->{$primaryKey} = self::getTmpID();
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

    public static function findAll($withRelationships = true)
    {
        $selfClass = get_called_class();
        $queryBuilder =  ModelQueryBuilder::select($selfClass, 'm');

        if ($withRelationships) {
            $relationships = array_keys(self::$relationships);
            $relationshipsCount = count($relationships);

            for ($i = 0; $i < $relationshipsCount; $i++) {
                $relationshipName = $relationships[$i];
                $relationship = $selfClass::getRelationship($relationshipName);

                $queryBuilder->leftJoin("m.{$relationships[$i]}", "m{$i}");
            }
        }

        return $queryBuilder->getResult();
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

    /**
     * Inserts this current model into the database.
     * It uses the column as well as the relationship definitions to populate the data with.
     *
     * @param   boolean  $fetchAfter  'true' to fetch the inserted model from the database.
     *
     * @return  boolean               'true' if the creation has succeeded.
     */
    public function create($fetchAfter = false)
    {
        $attributes = $this->_attributes;
        $dbService = self::$dbService;
        $selfClass = get_class($this);
        $primaryKey = $selfClass::$primaryKey;
        $selfRelationships = $selfClass::$relationships;
        $tableName = $selfClass::$tableName;
        $statement = "INSERT INTO {$dbService->dbName}.{$tableName}(";
        $statementPart = ') VALUES (';
        $first = true;
        $arguments = [];

        $storedRelationships = [];

        foreach ($this->_relationships as $relationshipName => $relationship) {
            if (!isset($selfRelationships[$relationshipName])) {
                throw new \Exception("A relationship with the name of '$relationshipName' was not defined in the mode of '$selfClass'.");
            }

            $relationshipDefinition = $selfClass::getRelationship($relationshipName);
            $column = $relationshipDefinition['column'];
            $joinColumn = $relationshipDefinition['joinColumn'];
            $type = $relationshipDefinition['relationship'];

            if ($type === self::RELATIONSHIP_MANY_TO_ONE || $type === self::RELATIONSHIP_ONE_TO_ONE) {
                $relatedModel = $relationship->get();

                $attributes[$column] = $relatedModel === null ? null : $relatedModel->{$joinColumn};
            } else {
                $relatedModels = [];

                foreach ($relationship->getAll() as &$relatedModel) {
                    $relatedModels[] = $relatedModel;
                }

                if (!isset($storedRelationships[$relationshipName])) {
                    $storedRelationships[$relationshipName] = [
                        'definition' => $relationshipDefinition,
                        'models' => []
                    ];
                }

                $storedRelationships[$relationshipName]['models'] = array_merge(
                    $storedRelationships[$relationshipName]['models'],
                    $relatedModels
                );
            }
        }

        foreach ($attributes as $key => $value) {
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

        $cachedID = $this->{$primaryKey};
        $lastInsertId = $dbService->queryWithArray($statement, $arguments);
        $this->{$primaryKey} = $lastInsertId;

        foreach ($storedRelationships as $relationshipName => $relationship) {
            $models = $relationship['models'];

            if (empty($models)) {
                continue;
            }

            $definition = $relationship['definition'];
            $column = $definition['column'];
            $joinColumn = $definition['joinColumn'];

            foreach ($models as &$model) {
                $model->{$joinColumn} = $this->{$column};
            }
        }

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
            $result = $dbService->queryWithArray($statement, $this->{$primaryKey});

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

    public static function select($alias)
    {
        return ModelQueryBuilder::select(self::class, $alias);
    }

    public function update()
    {
        $selfClass = get_called_class();
        $primaryKey = $selfClass::$primaryKey;
        $dbService = self::$dbService;
        $dbName = $dbService->dbName;
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

            if (!call_user_func_array([$dbService, 'query'], [$statement, $arguments])) {
                return false;
            }
        }

        $relationships = array_keys($selfClass::$relationships);

        foreach ($relationships as $relationshipName) {
            if (!$this->updateRelationship($relationshipName)) {
                return false;
            }
        }

        return true;
	}

    protected function updateRelationship($relationshipName)
    {
        $selfClass = get_called_class();
        $relationship = $selfClass::getRelationship($relationshipName);
        $relationshipType = $relationship['relationship'];

        if ($relationshipType === self::RELATIONSHIP_MANY_TO_MANY || $relationshipType === self::RELATIONSHIP_ONE_TO_MANY) {
            if ($relationshipType === self::RELATIONSHIP_MANY_TO_MANY) {
                return $this->updateManyToManyRelationship($relationshipName, $relationship);
            } else {
                return $this->updateOneToManyRelationship($relationshipName, $relationship);
            }
        } elseif ($relationshipType === self::RELATIONSHIP_MANY_TO_ONE || $relationshipType === self::RELATIONSHIP_ONE_TO_ONE) {
            return $this->updateToOneRelationship($relationshipName, $relationship);
        }

        return true;
    }

    /**
     * Updates '$relationship' in the style of "many-to-many".
     * Each stored model is compared with the mapping table records,
     * then executes inserts and deletes where needed.
     *
     * @param   string  $relationshipName  Name of the relationship to update.
     * @param   array   $relationship      Details of the relationship.
     *
     * @return  boolean                    'true' if update was successful or has no changes to process.
     * @throws  exception                  If relationship is not "many-to-many".
     */
    protected function updateManyToManyRelationship($relationshipName, $relationship)
    {
        if ($relationship['relationship'] !== self::RELATIONSHIP_MANY_TO_MANY) {
            throw $this->createUpdateRelationshipException(
                $relationshipName,
                $relationship,
                self::RELATIONSHIP_MANY_TO_MANY
            );
        }

        $dbService = self::$dbService;
        $relationshipStore = $this->{$relationshipName};
        $selfClass = get_called_class();

        $pending = $relationshipStore->getPending();

        $joinTable = $relationship['joinTable'];
        $columns = $relationship['column'];
        $joinColumns = isset($relationship['joinColumn']) ? $relationship['joinColumn'] : $columns;

        $thisValue = $this->{$columns[0]};

        $deletes = [];

        foreach ($pending['toRemove'] as $modelToRemove) {
            $relatingValue = $modelToRemove->{$columns[1]};

            $deletes[] = "({$joinColumns[0]} = {$thisValue} AND {$joinColumns[1]} = {$relatingValue})";
        }

        if (!empty($deletes)) {
            $deleteStatement = "DELETE FROM {$joinTable} WHERE " . implode(' OR ', $deletes);

            if (!$dbService->query($deleteStatement)) {
                return false;
            }
        }

        $inserts = [];

        foreach ($pending['toAdd'] as $modelToAdd) {
            $relatingValue = $modelToAdd->{$columns[1]};

            $inserts[] = "({$thisValue}, {$relatingValue})";
        }

        if (!empty($inserts)) {
            $insertStatement = "INSERT INTO {$joinTable} ({$joinColumns[0]}, {$joinColumns[1]}) VALUES " . implode(', ', $inserts);

            if (!$dbService->query($insertStatement)) {
                return false;
            }
        }

        $relationshipStore->save();

        return true;
    }

    /**
     * Updates '$relationship' in the style of "one-to-many".
     *
     * @param   string  $relationshipName  Name of the relationship to update.
     * @param   array   $relationship      Details of the relationship.
     *
     * @return  boolean                    'true' if update was successful or has no changes to process.
     * @throws  exception                  If relationship is not "many-to-one" or "one-to-many".
     */
    protected function updateOneToManyRelationship($relationshipName, $relationship)
    {
        if ($relationship['relationship'] !== self::RELATIONSHIP_ONE_TO_MANY) {
            throw $this->createUpdateRelationshipException(
                $relationshipName,
                $relationship,
                self::RELATIONSHIP_ONE_TO_MANY
            );
        }

        $selfClass = get_called_class();
        $relationshipStore = $this->{$relationshipName};

        if (!$relationshipStore->hasChanges()) {
            return true;
        }

        // TODO: implement support.
        throw new \Exception('Function is unimplemented.');
    }

    /**
     * Updates '$relationship' in the style of "many-to-one" or "one-to-one".
     *
     * @param   string  $relationshipName  Name of the relationship to update.
     * @param   array   $relationship      Details of the relationship.
     *
     * @return  boolean                    'true' if update was successful or has no changes to process.
     * @throws  exception                  If relationship is not "many-to-one" or "one-to-one".
     */
    protected function updateToOneRelationship($relationshipName, $relationship)
    {
        $typeBitmask = self::RELATIONSHIP_MANY_TO_ONE | self::RELATIONSHIP_ONE_TO_ONE;

        if (!($typeBitmask & $relationship['relationship'])) {
            throw $this->createUpdateRelationshipException(
                $relationshipName,
                $relationship,
                $typeBitmask
            );
        }

        $selfClass = get_called_class();
        $relationshipStore = $this->{$relationshipName};

        if (!$relationshipStore->hasChanges()) {
            return true;
        }

        $column = $relationship['column'];
        $joinColumn = $relationship['joinColumn'];
        $joinColumnValue = $relationshipStore->get()->{$joinColumn};

        $primaryKey = $selfClass::$primaryKey;
        $primaryValue = $this->{$primaryKey};
        $tableName = $selfClass::$tableName;

        $statement = "UPDATE {$tableName} SET {$column} = {$joinColumnValue} WHERE {$primaryKey} = {$primaryValue};";
        $dbService = self::$dbService;

        return $dbService->query($statement) !== false;
    }

    protected function createUpdateRelationshipException($relationshipName, $relationship, $expectedType)
    {
        $selectedTypes = [];
        $types = [
            self::RELATIONSHIP_UNKNOWN => 'unknown',
            self::RELATIONSHIP_ONE_TO_ONE => 'one-to-one',
            self::RELATIONSHIP_ONE_TO_MANY => 'one-to-many',
            self::RELATIONSHIP_MANY_TO_ONE => 'many-to-one',
            self::RELATIONSHIP_MANY_TO_MANY => 'many-to-many'
        ];

        foreach ($types as $type => $name) {
            if ($expectedType & $type) {
                $selectedTypes[] = "'$name'";
            }
        }

        $lastType = array_pop($selectedTypes);
        $typeName = implode(', ', $selectedTypes);
        $typeName .= $typeName === '' ? '' : ' or ';
        $typeName .= $lastType;

        return new Exception(
            "Attempted to update relationship with the name of '$relationshipName' ($typeName) which was expected to be $typeName"
        );
    }

	public function delete()
	{
		$selfName = get_class($this);
        $selfPrimaryKey = $selfName::$primaryKey;
        $selfTableName = $selfName::$tableName;
		return self::$dbService->query("DELETE FROM $selfTableName WHERE $selfPrimaryKey = ?", $this->{$selfPrimaryKey});
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

            $reverseRelationship['column'] = array_reverse($reverseRelationship['joinColumn']);
            $reverseRelationship['joinColumn'] = array_reverse($reverseRelationship['column']);
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

                $relationship['column'] = $reverseRelationship['column'];
                $relationship['joinColumn'] = $reverseRelationship['joinColumn'];
            }
        }

        if (!isset($relationship['joinColumn'])) {
            $relationship['joinColumn'] = $relationship['column'];
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

    final public static function setDBService(DBService $dbService)
    {
        self::$dbService = $dbService;
    }

    final protected static function getDBService()
    {
        return self::$dbService;
    }

    protected static function getTmpID()
    {
        Model::$tmpID--;
        
        return Model::$tmpID;
    }
}
