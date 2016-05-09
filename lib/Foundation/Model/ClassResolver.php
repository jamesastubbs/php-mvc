<?php

namespace PHPMVC\Foundation\Model;

class ClassResolver
{
    /**
     * Returns the full class path calculated from '$className'.
     * If '$className' is structured in the format of 'AcmeBundle:AcmeModel',
     * this will return 'AcmeBundle\Model\AcmeModel'.
     * @param   string      $className
     * @param   string      $modelName
     * @return  string
     */
    public static function resolve($className, &$modelName = '')
    {
        // TODO: document.        
        if (preg_match('/([^\:]+)\:(\w+)/', $className, $matches)) {
            array_shift($matches);
            
            $modelName = $matches[1];
            $className = $matches[0] . '\\Model\\' . $modelName;
        }
        
        return $className;
    }
}