<?php

namespace PHPMVC\Foundation\Model\Relationship;

use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\Relationship\Relationship;

class ToManyRelationship extends Relationship
{
    protected $storage = [];
    
    public function add(Model $model)
    {
        if (get_class($model) !== $this->modelClass) {
            throw new \Exception('The passed model with the class \'' . get_class($model) . "' does not match the specified relationahip class of '{$this->modelClass}'.");
        }
        
        $primaryKey = $this->primaryKey;
        
        if (!in_array($model->{$primaryKey}, $this->storage)) {
            $this->storage[] = $model->{$primaryKey};
        }
        
        Model::cacheModel($model);
        
        return $this;
    }
    
    public function get($modelIDs)
    {
        if (!is_array($modelIDs)) {
            $modelIDs = [$modelIDs];
        }
        
        $models = [];
        $modelClass = $this->modelClass;
        
        foreach ($modelIDs as $modelID) {
            if (!in_array($modelID, $this->storage)) {
                throw new \Exception("Model with ID of '$modelID' not found.");
            }
            
            $model = Model::getCachedModel($modelClass, $modelID);
            
            if ($model === null) {
                throw new \Exception("Model with ID of '$modelID' not found in cache.");
            }
            
            $models[] = $model;
        }
        
        return $models;
    }
    
    public function getFirst()
    {
        if (!isset($this->storage[0])) {
            throw new \Exception("No '{$this->modelClass}' models stored in relationship.");
        }
        
        $models = $this->get([$this->storage[0]]);
        
        return empty($models) ? null : $models[0];
    }
    
    public function getAll()
    {
        return $this->get($this->storage);
    }
    
    public function remove(Model $model)
    {
        if (get_class($model) !== $this->modelClass) {
            throw new \Exception('The passed model with the class \'' . get_class($model) . "' does not match the specified relationahip class of '{$this->modelClass}'.");
        }
        
        $storage = $this->storage;
        $count = count($storage);
        $result = false;
        
        for ($i = 0; $i < $count; $i++) {
            if ($storage[$i] === $model) {
                array_splice($this->storage, $i, 1);
                $result = true;
                break;
            }
        }
        
        return $result;
    }
    
    public function count()
    {
        return count($this->storage);
    }
    
    public function isEmpty()
    {
        return empty($this->storage);
    }
}
