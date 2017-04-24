<?php

namespace PHPMVC\Foundation\Model;

use PHPMVC\Foundation\Exception\QueryException;
use PHPMVC\Foundation\Model\ClassResolver;
use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\Relationship\ToManyRelationship;
use PHPMVC\Foundation\Model\Relationship\ToOneRelationship;

class ModelQueryBuilder
{
    const ALIAS_KEY = 'alias';
    const COLUMN_KEY = 'column';
    const JOIN_ALIAS_KEY = 'joinAlias';
    const JOIN_COLUMN_KEY = 'joinColumn';
    const JOIN_TABLE_KEY = 'joinTable';
    const METHOD_KEY = 'method';
    const ON_EXPR_KEY = 'onExpr';
    const ORDER_BY_ASC = 'ASC';
    const ORDER_BY_DESC = 'DESC';
    const RELATIONSHIP_KEY = 'relationship';
    const RELATIONSHIP_ATTRIBUTE_KEY = 'relationshipAttribute';
    const SUB_QUERY_KEY = 'subQuery';

    private $aliases = [];
    private $joins = [];
    private $limit = -1;
    private $offset = -1;
    private $orderByColumns = null;
    private $selectAlias = null;
    private $selectColumns = null;
    private $selectModel = null;
    private $whereExpr = null;
    protected $queryArguments = [];
    protected static $db = null;
    
    public function __construct($data, $isModel)
    {
        if ($isModel) {
            $this->from(
                $data['model'],
                $data['alias']
            );
        } else {
            $this->selectColumns = $data['columns'];
        }
    }
    
    final public static function setDB($db)
    {
        self::$db = $db;
    }
    
    final protected static function getDB()
    {
        return self::$db;
    }
    
    public static function select($modelOrColumns, $alias = null)
    {
        $selfClass = __CLASS__;
        $isModel = $alias !== null;
        
        $data = $isModel ? [
            'alias' => $alias,
            'model' => $modelOrColumns
        ] : [
            'columns' => $modelOrColumns
        ];
        
        $queryBuilder = new $selfClass($data, $isModel);
        
        return $queryBuilder;
    }
    
    public function from($model, $alias)
    {
        if ($this->selectAlias !== null) {
            throw new \Exception('The select alias has already been set.');
        }
        
        $this->selectAlias = $alias;
        $this->selectModel = ClassResolver::resolve($model);
        $this->setAlias($alias, $this->selectModel);
        
        return $this;
    }
    
    public function innerJoin($attribute, $alias, $onExpr = null, array $params = [])
    {
        return $this->join('INNER', $attribute, $alias, $onExpr);
    }
    
    public function leftJoin($attribute, $alias, $onExpr = null, array $params = [])
    {
        return $this->join('LEFT', $attribute, $alias, $onExpr);
    }
    
    public function rightJoin($attribute, $alias, $onExpr = null, array $params = [])
    {
        return $this->join('RIGHT', $attribute, $alias, $onExpr);
    }
    
    protected function join($joinMethod, $attribute, $alias, $onExpr = null, array $params = [])
    {
        if (preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $attribute) === 0) {
            return $this->joinSubQuery($joinMethod, $attribute, $alias, $onExpr);
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
            $column = $relationship[self::COLUMN_KEY];
            $joinColumn = isset($relationship[self::JOIN_COLUMN_KEY]) ? $relationship[self::JOIN_COLUMN_KEY] : $column;

            if ($relationship[self::RELATIONSHIP_KEY] === Model::RELATIONSHIP_MANY_TO_MANY) {
                $column = $column[0];
                $joinColumn = $joinColumn[0];
            }

            $onExpr = "`$parentAlias`.`$column` = `$alias`.`$joinColumn`";
        }

        // create the join definition.
        $join = [
            self::ALIAS_KEY => $alias,
            self::JOIN_ALIAS_KEY => $parentAlias,
            self::METHOD_KEY => $joinMethod,
            self::ON_EXPR_KEY => $onExpr,
            self::RELATIONSHIP_KEY => $relationship,
            self::RELATIONSHIP_ATTRIBUTE_KEY => $relationshipAttribute
        ];

        // add the 'joinTable' value if the relationship is many-to-many.
        if ($relationship === Model::RELATIONSHIP_MANY_TO_MANY) {
            $join[self::JOIN_TABLE_KEY] = $relationship[self::JOIN_TABLE_KEY];
        }

        // store the join definition.
        $this->joins[] = $join;

        // add the parameters.
        if (!empty($params)) {
            $this->queryArguments = array_merge($this->queryArguments, $params);
        }

        // return '$this' for method chaining.
        return $this;
    }

    protected function joinSubQuery($joinMethod, $subQuery, $alias, $onExpr = null, $params = [])
    {
        $subQuery = "($subQuery)";

        // create the join definition.
        $join = [
            self::ALIAS_KEY => $alias,
            self::METHOD_KEY => $joinMethod,
            self::ON_EXPR_KEY => $onExpr,
            self::SUB_QUERY_KEY => $subQuery
        ];

        // store the join definition.
        $this->joins[] = $join;

        // set the alias to preserve it.
        $this->setAlias($alias, self::SUB_QUERY_KEY);

        // add the parameters.
        if (!empty($params)) {
            $this->queryArguments = array_merge($this->queryArguments, $params);
        }

        return $this;
    }

    public function where($expr)
    {
        if ($this->limit !== -1) {
            throw new \Exception('Cannot set the where clause after the limit has been set.');
        }
        
        if ($this->offset !== -1) {
            throw new \Exception('Cannot set the where clause after the offset has been set.');
        }
        
        $this->queryArguments = array_merge($this->queryArguments, array_slice(func_get_args(), 1));
        $this->whereExpr = $expr;
        
        return $this;
    }
    
    public function orderBy(array $orderByColumn)
    {
        if ($this->orderByColumns === null) {
            $this->orderByColumns = [];
        }
        
        if (!is_associative($orderByColumn)) {
            throw new \Exception('Unexpected \'ORDER BY\' format. Expecting [column => order].');
        }
        
        $this->orderByColumns = array_merge($this->orderByColumns, $orderByColumn);
        
        return $this;
    }
    
    public function limit($limit)
    {
        if ($this->offset !== -1) {
            throw new \Exception('Cannot set the limit as the offset value has already been set.');
        }
        
        if (!is_int($limit)) {
            throw new \Exception('Limit value must be a numeric value.');
        }
        
        $this->limit = $limit;
        
        return $this;
    }
    
    public function offset($offset)
    {
        if (!is_int($offset)) {
            throw new \Exception('Offset value must be a numeric value.');
        }
        
        $this->offset = $offset;
        
        return $this;
    }
    
    public function getSQL()
    {
        $aliases = $this->aliases;
        $aliasesCount = count($aliases);
        $columns = [];
        
        if (empty($aliases)) {
            throw new \Exception('No model selected.');
        }
        
        $selectAlias = $this->selectAlias;
        $selectModel = $this->getAlias($selectAlias);
        $selectTable = $selectModel::$tableName;
        $usesColumns = $this->selectColumns !== null;
        
        if ($usesColumns) {
            preg_match_all('/[A-Za-z0-9_]+\.[A-Za-z0-9_]+/', $this->selectColumns, $matchedColumns);
            $matchedColumns = $matchedColumns[0];
            
            foreach ($matchedColumns as $matchedColumn) {
                if (strpos($matchedColumn, '.') === false) {
                    throw new \Exception("Unsupported column: '$matchedColumn'.");
                }
                
                $columnParts = explode('.', $matchedColumn);
                $alias = $columnParts[0];
                $column = $columnParts[1];
                
                $this->addColumn($column, $alias, $columns);
            }
        } else {
            $this->addColumnsFromAlias($selectAlias, $columns);
        }
        
        $sql = "FROM `$selectTable` AS `$selectAlias`";
        
        foreach ($this->joins as $join) {
            // get the values from the join declaration.
            $alias = $join[self::ALIAS_KEY];
            $method = $join[self::METHOD_KEY];
            $onExpr = $join[self::ON_EXPR_KEY];

            if (!isset($join[self::RELATIONSHIP_KEY]) && isset($join[self::SUB_QUERY_KEY])) {
                $subQuery = $join[self::SUB_QUERY_KEY];
                $sql .= " $method JOIN $subQuery AS `$alias` ON $onExpr";

                continue;
            }

            // get the model definition along with the table name.
            $model = $this->getAlias($alias);
            $modelTable = $model::$tableName;

            // if the relationship is many-to-many, join the mapping table. 
            if ($join[self::RELATIONSHIP_KEY]['relationship'] === Model::RELATIONSHIP_MANY_TO_MANY) {
                $column = $join[self::RELATIONSHIP_KEY][self::COLUMN_KEY];
                $joinColumn = isset($join[self::RELATIONSHIP_KEY][self::JOIN_COLUMN_KEY]) ? $join[self::RELATIONSHIP_KEY][self::JOIN_COLUMN_KEY] : $column;
                $joinTable = $join[self::RELATIONSHIP_KEY][self::JOIN_TABLE_KEY];

                // TODO: make this actually dynamic!
                $sql .= " $method JOIN `$joinTable` ON `u`.`{$column[0]}` = `$joinTable`.`{$joinColumn[0]}`";
                $onExpr = "`$joinTable`.`{$joinColumn[1]}` = `$alias`.`$column[1]`";
            }

            if (!$usesColumns) {
                // add the columns in the SELECT clause.
                $this->addColumnsFromAlias($alias, $columns);
            }

            $sql .= " $method JOIN `$modelTable` AS `$alias` ON $onExpr";
        }
        
        $sql = 'SELECT ' . implode(', ', $columns) . ' ' . $sql;
        
        if ($this->whereExpr !== null) {
            $sql .= ' WHERE ' . $this->whereExpr;
        }
        
        if ($this->orderByColumns !== null) {
            $sql .= ' ORDER BY';
            
            $first = true;
            
            foreach ($this->orderByColumns as $columnName => $columnOrder) {
                if ($first) {
                    $first = false;
                } else {
                    $sql .= ',';
                }
                
                $sql .= " $columnName $columnOrder";
            }
        }
        
        if ($this->limit !== -1) {
            $sql .= " LIMIT {$this->limit}";
        }
        
        if ($this->offset !== -1) {
            $sql .= " OFFSET {$this->OFFSET}";
        }
        
        return $sql;
    }
    
    public function getResult()
    {
        // get DB connection object, SQL statement and arguments.
        $db = self::getDB();
        $sql = $this->getSQL();

        // execute query.
        $result = null;
        $fetchedData = null;
        
        try {
            $fetchedData = call_user_func_array(
                [$db, 'query'],
                array_merge([$sql], $this->queryArguments)
            );
        } catch (QueryException $e) {
            throw $e;
        }
        
        if (is_array($fetchedData)) {
            // initalise return object now we have deemed the response to be successful.
            // store the objects in the cache, then add them to the relationships.
            $cache = [];
            $result = [];
            
            // fetch the aliases and the joins which have been populated earlier.
            $aliases = $this->aliases;
            $joins = $this->joins;
            $joinsCount = count($joins);
            $selectAlias = $this->getAlias($this->selectAlias);
            
            // iterate through all fetched records.
            foreach ($fetchedData as $fetchedRow) {
                // iterate through all of the selected models.
                // add the model to the cache if it doesn't already exist.
                foreach ($aliases as $alias => $modelClass) {
                    if ($modelClass === self::SUB_QUERY_KEY) {
                        continue;
                    }

                    $columns = $modelClass::$columns;
                    $primaryKey = $modelClass::$primaryKey;
                    $fetchedRowKey = "{$alias}_{$primaryKey}";
                    
                    if (array_key_exists($fetchedRowKey, $fetchedRow)) {
                        $primaryValue = $fetchedRow[$fetchedRowKey] . '';
                        
                        // if the model with the current primary key value already exists in the cache,
                        // skip this and continue on with the loop.
                        if ($primaryValue === null || $primaryValue === '' || isset($cache[$alias][$primaryValue])) {
                            continue;
                        }
                        
                        $model = new $modelClass();
                        
                        // iterate through each model's columns.
                        // populating the model's values from the current '$fetchedRow' by using the column name as the key.
                        foreach ($columns as $column => $columnType) {
                            $key = "{$alias}_{$column}";
                            
                            if (array_key_exists($key, $fetchedRow)) {
                                $value = $modelClass::getColumnValue($column, $fetchedRow[$key], false);
                                $model->{$column} = $value;
                                
                                if ($column !== $primaryKey) {
                                    $model->{"__old_$column"} = $value;
                                }
                            }
                        }
                        
                        // populate an array for this model alias.
                        if (!isset($cache[$modelClass])) {
                            $cache[$modelClass] = [];
                        }
                        
                        // store the model in the cache as it is new.
                        $cache[$modelClass][$primaryValue] = $model;
                        Model::cacheModel($model);
                    }
                }
            }
            
            if (!empty($cache)) {
                // iterate through all of the joins.
                for ($i = 0; $i < $joinsCount; $i++) {
                    // if the current join doesn't support object mapping, skip it.
                    if (!isset($joins[$i][self::JOIN_ALIAS_KEY])) {
                        // remove the join from the '$joins' array to prevent iterating throuh it again.
                        array_splice($joins, $i, 1);
                        $joinsCount--;
                        $i--;

                        // continue on with iteration.
                        continue;
                    }

                    $join = &$joins[$i];
                    $alias = $join[self::ALIAS_KEY];
                    $joinAlias = $join[self::JOIN_ALIAS_KEY];
                    $modelClass = $aliases[$alias];
                    $joinModelClass = $aliases[$joinAlias];
                    
                    if (isset($cache[$modelClass]) && isset($cache[$joinModelClass])) {
                        // get the cached models.
                        $models = &$cache[$modelClass];
                        $joinModels = &$cache[$joinModelClass];
                        
                        $relationship = $join[self::RELATIONSHIP_KEY];
                        $relationshipAttribute = $join[self::RELATIONSHIP_ATTRIBUTE_KEY];
                        
                        $reverseRelationship = Model::getReverseRelationship($relationship);
                        
                        if ($reverseRelationship === null && !isset($relationship[self::COLUMN_KEY])) {
                            // TODO: create more meaningful exception message.
                            throw new \Exception('Model is not configured for reverse relationships.');
                        }
                        
                        $reverseRelationshipAttribute = null;
                        
                        if ($reverseRelationship !== null) {
                            $reverseRelationshipAttribute = isset($relationship['inverse']) ? $relationship['inverse'] : $relationship['mappedBy'];
                        }
                        
                        $column = $relationship[self::COLUMN_KEY];
                        $joinColumn = isset($relationship[self::JOIN_COLUMN_KEY]) ? $relationship[self::JOIN_COLUMN_KEY] : $column;

                        if ($relationship[self::RELATIONSHIP_KEY] === Model::RELATIONSHIP_MANY_TO_MANY) {
                            $column = $column[isset($relationship['mappedBy']) ? 1 : 0];
                            $joinColumn = $joinColumn[isset($relationship['mappedBy']) ? 0 : 1];
                        }

                        foreach ($models as &$model) {
                            $columnValue = $model->{$joinColumn};
                            
                            foreach ($joinModels as &$joinModel) {
                                $joinColumnValue = $joinModel->{$column};
                                
                                if ($columnValue === $joinColumnValue) {
                                    $this->addModelFromRelationship(
                                        $model,
                                        $joinModel,
                                        $relationship,
                                        $relationshipAttribute,
                                        $reverseRelationship,
                                        $reverseRelationshipAttribute
                                    );
                                }
                            }
                        }
                    }

                    // remove pointer.
                    unset($join);
                }
                
                // return the collection of models (if any).
                $result = array_merge($cache[$aliases[$this->selectAlias]], []);
            }
        }

        return $result;
    }
    
    private function addModelFromRelationship(&$model, &$joinModel, $relationship, $relationshipAttribute, $reverseRelationship = null, $reverseRelationshipAttribute = null)
    {
        $modelClass = get_class($model);
        $modelPrimaryKey = $modelClass::$primaryKey;
        $joinModelClass = get_class($joinModel);
        $joinModelPrimaryKey = $joinModelClass::$primaryKey;
        
        $this->addRelatedModelToModel($joinModel, $model, $relationshipAttribute, $relationship);
        
        if ($reverseRelationship !== null && $reverseRelationshipAttribute !== null) {
            $this->addRelatedModelToModel($model, $joinModel, $reverseRelationshipAttribute, $reverseRelationship);
        }
    }
    
    /**
     * @param   boolean $toMany
     */
    private function addRelatedModelToModel(&$model, &$joinModel, $attribute, $relationship)
    {
        $relationshipType = $relationship['relationship'];
        $toMany = $relationshipType === Model::RELATIONSHIP_ONE_TO_MANY || $relationshipType === Model::RELATIONSHIP_MANY_TO_MANY;
        
        if ($toMany) {
            //if (!isset($model->{$attribute})) {
            //    $modelClass = get_class($model);
            //    $model->{$attribute} = new ModelCollection($modelClass);
            //}
            
            $add = true;
            $column = $relationship[self::COLUMN_KEY];
            $joinModelClass = get_class($joinModel);
            $primaryKey = $joinModelClass::$primaryKey;
            
            foreach ($model->{$attribute} as $compareModel) {
                $compareModelColumn = isset($relationship[self::JOIN_COLUMN_KEY]) ? $relationship[self::JOIN_COLUMN_KEY] : $relationship[self::COLUMN_KEY];
                
                if ($compareModel->{$compareModelColumn} === $joinModel->{$column}) {
                    $add = false;
                    break;
                }
            }
            
            // only add the $joinModel if it hasn't already been added.
            if ($add) {
                $model->{$attribute}->add($joinModel);
                $model->{$attribute}->save();
            }
        } else if (!$toMany) {
            if (isset($model->{$attribute}) && $model->{$attribute} !== $joinModel) {
                $modelClass = get_class($model);
                throw new \Exception("The attribute '$attribute' has already been set in the model '$modelClass'.");
            }
            
            $model->{$attribute}->set($joinModel);
            $model->{$attribute}->save();
        }
    }
    
    private function addColumnsFromAlias($alias, &$columns)
    {
        $model = $this->getAlias($alias);
        $modelColumns = array_keys($model::$columns);
        
        foreach ($modelColumns as $modelColumn) {            
            $this->addColumn($modelColumn, $alias, $columns);
        }
    }
    
    private function addColumn($column, $alias, &$columns)
    {
        $columns[] = "$alias.$column AS {$alias}_{$column}";
    }
    
    private function getAlias($alias)
    {
        if (!isset($this->aliases[$alias])) {
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
