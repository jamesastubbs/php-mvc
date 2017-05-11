<?php

namespace PHPMVC\Foundation\Model;

use PHPMVC\Foundation\Service\DBService;

class ModelProxy
{
    /**
     * @var  DBService
     */
    private $dbService = null;

    /**
     * @var  string
     */
    private $modelClass = null;

    /**
     * @param  DBService  $dbService   Service handling database queries.
     * @param  string     $modelClass  Class of the model to proxy.
     */
    public function __construct(DBService $dbService, $modelClass)
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new \Exception("The class of '$modelClass' is not a subclass of Model.");
        }

        $this->dbService = $dbService;
        $this->modelClass = $modelClass;
    }

    public function __call($name, array $arguments)
    {
        return call_user_func_array("{$this->modelClass}::{$name}", $arguments); 
    }
}
