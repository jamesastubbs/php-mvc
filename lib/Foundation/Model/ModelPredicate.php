<?php

namespace PHPMVC\Foundation\Model;

use \PHPMVC\Foundation\Model\ClassResolver;

final class ModelPredicate
{
	public $arguments = null;
    private $classes = null;
	private $str = null;
    
	public function __construct($str)
	{
        $arguments = array_slice(func_get_args(), 1);
		if (isset($arguments) && !empty($arguments)) {
			$this->arguments = $arguments;
		}
        
        $this->str = $str;
	}
    
    public function addClasses($classes)
    {
        if ($this->classes === null) {
            $this->classes = [];
        }
        
        $this->classes = array_merge($this->classes, $classes);
    }
    
    public function getFormattedQuery()
    {
        $str = $this->str;
        
        if (preg_match('/\ /', $str)) {
			$strComponents = explode(' ', $str);
			for ($i = 0; $i < count($strComponents); $i++) {
				$strComponent = $strComponents[$i];
                
                preg_match_all('/(?!$::)([A-Za-z0-9_:]+)\.(\w+)/', $strComponent, $matches);
                $matches = array_slice($matches, 1);
                
                if (count($matches) === 2 && !empty($matches[0])) {
                    $modelName = ClassResolver::resolve($matches[0][0]);
                    $columnName = $matches[1][0];
                                        
                    // set class with full path if added to classes.
                    if ($this->classes !== null && array_key_exists($modelName, $this->classes)) {
                        $modelName = $this->classes[$modelName];
                    }
                    
					$strComponents[$i] = $modelName::$tableName . ".$columnName";
				}
			}
            
			$str = implode(' ', $strComponents);
		}

        return $str;
    }
    
	public function isLimitOne() {
		return preg_match('/LIMIT 1/i', $this->format);
	}
}