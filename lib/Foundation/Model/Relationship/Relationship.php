<?php

namespace PHPMVC\Foundation\Model\Relationship;

abstract class Relationship
{
    protected $modelClass = null;
    protected $primaryKey = null;
    protected $storage = null;
    protected $unsavedStorage = null;

    public function __construct($modelClass)
    {
        $this->modelClass = $modelClass;
        $this->primaryKey = $modelClass::$primaryKey;
    }

    public function save()
    {
        $this->storage = $this->unsavedStorage;
    }
}
