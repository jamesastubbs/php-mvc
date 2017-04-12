<?php

namespace PHPMVC\Foundation\Model\Relationship;

use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\Relationship\Relationship;

class ToManyRelationship extends Relationship
{
    protected $storage = [];
    protected $unsavedStorage = [];

    public function add(Model $model)
    {
        if (get_class($model) !== $this->modelClass) {
            throw new \Exception('The passed model with the class \'' . get_class($model) . "' does not match the specified relationahip class of '{$this->modelClass}'.");
        }
        
        $primaryKey = $this->primaryKey;
        
        if (!in_array($model->{$primaryKey}, $this->unsavedStorage)) {
            $this->unsavedStorage[] = $model->{$primaryKey};
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
            if (!in_array($modelID, $this->unsavedStorage)) {
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
        if (!isset($this->unsavedStorage[0])) {
            throw new \Exception("No '{$this->modelClass}' models stored in relationship.");
        }
        
        $models = $this->get([$this->unsavedStorage[0]]);
        
        return empty($models) ? null : $models[0];
    }
    
    public function getAll()
    {
        return $this->get($this->unsavedStorage);
    }
    
    public function remove(Model $model)
    {
        if (get_class($model) !== $this->modelClass) {
            throw new \Exception('The passed model with the class \'' . get_class($model) . "' does not match the specified relationahip class of '{$this->modelClass}'.");
        }

        $primaryKey = $this->primaryKey;
        $result = false;
        $storage = $this->unsavedStorage;
        $count = count($storage);

        for ($i = 0; $i < $count; $i++) {
            if ($storage[$i] === $model->{$primaryKey}) {
                array_splice($this->unsavedStorage, $i, 1);
                $result = true;
                break;
            }
        }

        return $result;
    }
    
    public function count()
    {
        return count($this->unsavedStorage);
    }
    
    public function isEmpty()
    {
        return empty($this->unsavedStorage);
    }

    public function getPending()
    {
        $modelClass = $this->modelClass;
        $primaryKey = $this->primaryKey;
        $toAdd = [];
        $toAddKeys = array_merge([], $this->unsavedStorage);
        $toRemove = [];

        $toAddKeysCount = count($toAddKeys);

        foreach ($this->storage as $modelKey) {
            $found = false;

            for ($i = 0; $i < $toAddKeysCount; $i++) {
                $addingKey = $toAddKeys[$i];

                if ($addingKey === $modelKey) {
                    $found = true;

                    array_splice($toAddKeys, $i, 1);
                    $toAddKeysCount--;

                    break;
                }
            }

            if (!$found) {
                $toRemove[] = Model::getCachedModel($modelClass, $modelKey);
            }
        }

        foreach ($toAddKeys as $key) {
            $toAdd[] =  Model::getCachedModel($modelClass, $key);
        }

        return [
            'toAdd' => $toAdd,
            'toRemove' => $toRemove
        ];
    }

    public function save()
    {
        $this->storage = array_merge([], $this->unsavedStorage);
    }
}
