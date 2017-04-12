<?php

namespace PHPMVC\Foundation\Model\Relationship;

use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\Relationship\Relationship;

class ToOneRelationship extends Relationship
{
    public function get()
    {
        $model = null;
        $modelID = $this->unsavedStorage;

        if ($modelID !== null) {
            $modelClass = $this->modelClass;

            $model = Model::getCachedModel($modelClass, $modelID);
        }

        return $model;
    }
    
    public function set(Model $model = null)
    {
        $primaryKey = $this->primaryKey;

        $this->unsavedStorage = $model->{$primaryKey};
    }
}
