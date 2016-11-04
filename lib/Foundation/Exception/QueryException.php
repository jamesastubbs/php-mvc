<?php

namespace PHPMVC\Foundation\Exception;

class QueryException extends \Exception
{
    private $sql = null;
    private $parameters = [];
    
    public function setSQLWithParameters($sql, $parameters = null)
    {
        $this->sql = $sql;
        $this->parameters = $parameters;
    }
    
    public function getSQL()
    {
        return $this->sql;
    }
    
    public function getParameters()
    {
        return $this->parameters;
    }
}
