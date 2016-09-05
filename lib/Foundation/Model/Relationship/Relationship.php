<?php

namespace PHPMVC\Foundation\Model\Relationship;

abstract class Relationship
{
    protected $modelClass = null;
    protected $primaryKey = null;
    protected $storage = null;
    
    public function __construct($modelClass)
    {
        $this->modelClass = $modelClass;
        $this->primaryKey = $modelClass::$primaryKey;
    }
}
