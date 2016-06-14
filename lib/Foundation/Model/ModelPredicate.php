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
    
    public function getFormattedQuery($test = null)
    {
        $pattern = '/(?:\$::)((?:[A-Za-z0-9]+:)?[A-Za-z0-9]+)(?:(?:\ |$|.(?:([A-Za-z0-9_]+))?))/';
        $str = preg_replace_callback($pattern, function ($matches) use ($test) {
            $matchStr = '';
            $matches = array_slice($matches, 1);
            $matchesCount = count($matches);
            
            if ($matchesCount !== 0) {
                $modelName = $matches[0];
                $columnName = isset($matches[1]) ? '.' . $matches[1] : ' ';
                
                if (strpos($modelName, ':') !== false) {
                    $modelName = ClassResolver::resolve($modelName);
                }
                
                $matchStr = $modelName::$tableName . $columnName;
            }
            
            return $matchStr;
        }, $this->str);
        
        return $str;
    }
    
	public function isLimitOne() {
		return preg_match('/LIMIT 1/i', $this->format);
	}
}