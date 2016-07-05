<?php

namespace PHPMVC\Foundation\Model;

use PHPMVC\Foundation\Model\ClassResolver;
use PHPMVC\Foundation\Model\Model;

class ModelQueryBuilder
{
    const ALIAS_KEY = 'alias';
    const JOIN_ALIAS_KEY = 'joinAlias';
    const METHOD_KEY = 'method';
    const ON_EXPR_KEY = 'onExpr';
    const RELATIONSHIP_KEY = 'relationship';
    const RELATIONSHIP_ATTRIBUTE_KEY = 'relationshipAttribute';
    
    private $aliases = [];
    private $columns = [];
    private $joins = [];
    private $selectAlias = null;
    private $whereArguments = [];
    private $whereExpr = null;
    protected static $db = null;
    
    public function __construct($model, $alias)
    {
        $this->selectAlias = $alias;
        $model = ClassResolver::resolve($model);
        
        $this->setAlias($alias, $model);
    }
    
    final public static function setDB($db)
    {
        self::$db = $db;
    }
    
    final protected static function getDB()
    {
        return self::$db;
    }
    
    public static function select($model, $alias)
    {
        $selfClass = __CLASS__;
        $queryBuilder = new $selfClass($model, $alias);
        
        return $queryBuilder;
    }
    
    public function innerJoin($attribute, $alias, $onExpr = null)
    {
        return $this->join('INNER', $attribute, $alias, $onExpr);
    }
    
    public function leftJoin($attribute, $alias, $onExpr = null)
    {
        return $this->join('LEFT', $attribute, $alias, $onExpr);
    }
    
    public function rightJoin($attribute, $alias, $onExpr = null)
    {
        return $this->join('RIGHT', $attribute, $alias, $onExpr);
    }
    
    protected function join($joinMethod, $attribute, $alias, $onExpr = null)
    {
        if (strpos($attribute, '.') === false) {
            throw new \Exception("Unsupported attribute: '$attribute'.");
        }
        
        $attributeParts = explode('.', $attribute);
        $parentAlias = $attributeParts[0];
        $relationshipAttribute = $attributeParts[1];
        $parentModelClass = $this->getAlias($parentAlias);
        
        // fetch the relationship.
        $relationship = $parentModelClass::getRelationship($relationshipAttribute);
        
        // get the child model class and store the value with the associating alias.
        $childModelClass = ClassResolver::resolve($relationship['model']);
        $this->setAlias($alias, $childModelClass);
        
        // build the default ON expression defined in the relationship if not overriden.
        if ($onExpr === null) {
            $column = $relationship['column'];
            $joinColumn = isset($relationship['joinColumn']) ? $relationship['joinColumn'] : $column;
            
            $onExpr = "$parentAlias.$column = $alias.$joinColumn";
        }
        
        $this->joins[] = [
            self::ALIAS_KEY => $alias,
            self::JOIN_ALIAS_KEY => $parentAlias,
            self::METHOD_KEY => $joinMethod,
            self::ON_EXPR_KEY => $onExpr,
            self::RELATIONSHIP_KEY => $relationship,
            self::RELATIONSHIP_ATTRIBUTE_KEY => $relationshipAttribute
        ];
        
        return $this;
    }
    
    public function where($expr)
    {
        $this->whereArguments = array_slice(func_get_args(), 1);
        $this->whereExpr = $expr;
        
        return $this;
    }
    
    public function getSQL()
    {
        $aliases = $this->aliases;
        $aliasesCount = count($aliases);
        
        if (empty($aliases)) {
            throw new \Exception('No model selected.');
        }
        
        $selectAlias = $this->selectAlias;
        $selectModel = $this->getAlias($selectAlias);
        $selectTable = $selectModel::$tableName;
        $this->addColumnsFromAlias($selectAlias);
        
        $sql = "FROM $selectTable AS $selectAlias";
        
        foreach ($this->joins as $join) {
            // get the values from the join declaration.
            $alias = $join[self::ALIAS_KEY];
            $method = $join[self::METHOD_KEY];
            $model = $this->getAlias($alias);
            $modelTable = $model::$tableName;
            $onExpr = $join[self::ON_EXPR_KEY];
            
            // add the columns in the SELECT clause.
            $this->addColumnsFromAlias($alias);
            
            $sql .= " $method JOIN $modelTable AS `$alias` ON $onExpr";
        }
        
        $sql = 'SELECT ' . implode(', ', $this->columns) . ' ' . $sql;
        
        if ($this->whereExpr !== null) {
            $sql .= ' WHERE ' . $this->whereExpr;
        }
        
        return $sql;
    }
    
    public function getResult()
    {
        // get DB connection object, SQL statement and arguments.
        $db = self::getDB();
        $sql = $this->getSQL();
        $whereArguments = !empty($this->whereArguments) ? $this->whereArguments : null;
        
        // execute query.
        $result = null;
        $fetchedData = $db->query($sql, $whereArguments);
        
        if (is_array($fetchedData)) {
            // initalise return object now we have deemed the response to be successful.
            // store the objects in the cache, then add them to the relationships.
            $cache = [];
            $result = [];
            
            // fetch the aliases and the joins which have been populated earlier.
            $aliases = $this->aliases;
            $joins = $this->joins;
            $selectAlias = $this->getAlias($this->selectAlias);
            
            // iterate through all fetched records.
            foreach ($fetchedData as $fetchedRow) {
                // iterate through all of the selected models.
                // add the model to the cache if it doesn't already exist.
                foreach ($aliases as $alias => $modelClass) {
                    $columns = $modelClass::$columns;
                    $primaryKey = $modelClass::$primaryKey;
                    $primaryValue = $fetchedRow["{$alias}_{$primaryKey}"] . '';
                    $model = new $modelClass();
                    
                    // if the model with the current primary key value already exists in the cache,
                    // skip this and continue on with the loop.
                    if (isset($cache[$alias][$primaryValue])) {
                        continue;
                    }
                    
                    // iterate through each model's columns.
                    // populating the model's values from the current '$fetchedRow' by using the column name as the key.
                    foreach ($columns as $column => $columnType) {
                        $value = $fetchedRow["{$alias}_{$column}"];
                        $model->{$column} = $value;
                        
                        if ($column !== $primaryKey) {
                            $model->{"__old_$column"} = $value;
                        }
                    }
                    
                    // populate an array for this model alias.
                    if (!isset($cache[$alias])) {
                        $cache[$alias] = [];
                    }
                    
                    // store the model in the cache as it is new.
                    $cache[$alias][$primaryValue] = $model;
                }
            }
            
            if (!empty($cache)) {
                // iterate through all of the joins.
                foreach ($joins as &$join) {                
                    $alias = $join[self::ALIAS_KEY];
                    $joinAlias = $join[self::JOIN_ALIAS_KEY];
                    
                    if (!isset($cache[$alias])) {
                        throw new \Exception("Cannot process alias '$alias' as it has not been found.");
                    }
                    
                    if (!isset($cache[$joinAlias])) {
                        throw new \Exception("Cannot process alias '$joinAlias' as it has not been found.");
                    }
                    
                    // get the cached models.
                    $models = $cache[$alias];
                    $joinModels = $cache[$joinAlias];
                    
                    $relationship = $join[self::RELATIONSHIP_KEY];
                    $relationshipAttribute = $join[self::RELATIONSHIP_ATTRIBUTE_KEY];
                    //$relationshipModel = ClassResolver::resolve($relationship['model']);
                    //$relationship = $relationshipModel::$relationship[$relationship]
                    
                    // TODO: remove second parameter.
                    $reverseRelationship = Model::getReverseRelationship($relationship);
                    $reverseRelationshipAttribute = isset($relationship['inverse']) ? $relationship['inverse'] : $relationship['mappedBy'];
                    
                    $column = $relationship['column'];
                    $joinColumn = isset($relationship['joinColumn']) ? $relationship['joinColumn'] : $column;
                    
                    foreach ($models as &$model) {
                        $columnValue = $model->{$column};
                        
                        foreach ($joinModels as &$joinModel) {
                            $joinColumnValue = $joinModel->{$joinColumn};
                            
                            if ($columnValue === $joinColumnValue) {
                                $this->addModelFromRelationship($model, $joinModel, $relationship, $relationshipAttribute, $reverseRelationship, $reverseRelationshipAttribute);
                            }
                        }
                    }
                }
                
                // return the collection of models (if any).
                $result = array_merge($cache[$this->selectAlias], []);
            }
        }
        
        return $result;
    }
    
    private function addModelFromRelationship(&$model, &$joinModel, $relationship, $relationshipAttribute, $reverseRelationship, $reverseRelationshipAttribute)
    {
        $this->addRelatedModelToModel($joinModel, $model, $relationshipAttribute, $relationship);
        
        /*
        if ($reverseRelationshipAttribute === 'sectionLocations') {
            var_dump($reverseRelationshipAttribute);
            var_dump($reverseRelationship);
            var_dump($model);
            var_dump($joinModel);
            die(__FILE__ . ':' . __LINE__);
        }
        */
        
        $this->addRelatedModelToModel($model, $joinModel, $reverseRelationshipAttribute, $reverseRelationship);
    }
    
    /**
     * @param   boolean $toMany
     */
    private function addRelatedModelToModel(&$model, &$joinModel, $attribute, $relationship)
    {
        $relationshipType = $relationship['relationship'];
        $toMany = $relationshipType === Model::RELATIONSHIP_ONE_TO_MANY || $relationshipType === Model::RELATIONSHIP_MANY_TO_MANY;
        
        if ($toMany) {
            if (!isset($model->{$attribute})) {
                $model->{$attribute}  = [];
            }
            
            // only add the $joinModel if it hasn't already been added.
            if (!in_array($joinModel, $model->{$attribute})) {
                $model->{$attribute}[] = $joinModel;
            }
        } else if (!$toMany) {
            if (isset($model->{$attribute}) && $model->{$attribute} !== $joinModel) {
                //throw new \Exception("The attribute '$attribute' has already been set in the model " . get_class($model) . ".'");
            }
            
            $model->{$attribute} = $joinModel;
        }
    }
    
    private function addColumnsFromAlias($alias)
    {
        $model = $this->getAlias($alias);
        $modelColumns = array_keys($model::$columns);
        
        foreach ($modelColumns as $modelColumn) {            
            $this->columns[] = "$alias.$modelColumn AS {$alias}_{$modelColumn}";
        }
    }
    
    private function getAlias($alias)
    {
        if (!$this->aliases[$alias]) {
            throw new \Exception("Alias '$alias' could not be found.");
        }
        
        return $this->aliases[$alias];
    }
    
    private function setAlias($alias, $model)
    {
        if (isset($this->aliases[$alias])) {
            throw new \Exception("The alias '$alias' already exists - '{$this->aliases[$alias]}'");
        }
        
        $this->aliases[$alias] = $model;
    }
}
