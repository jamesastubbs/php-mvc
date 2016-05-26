<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation;

use PHPMVC\DB\DB;
use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Router;

class Application
{
    private $action = null;
	private $controller = null;
    private $db = null;
	private $parameters = array();
    private $router = null;
    private static $config = null;
    private static $configPath = null;
    
	public function __construct($__config)
	{
        session_start();
        
        self::$config = $__config;
        self::$configPath = $__config['ROOT'] . '/config';
        $routesPath = self::$configPath . '/routes.json';
        
        if (file_exists($routesPath)) {
            $jsonString = file_get_contents($routesPath);
            $routes = json_decode($jsonString, true);
            $this->router = new Router($__config['NAME'], $routes);
        }
        
		$this->splitURL();
		$maintenanceMode = $this->underMaintenance();
		
		if (!class_exists($this->controller)) {
            var_dump($this->controller);
            die(__FILE__ . ':' . __LINE__);
			throw new \Exception('404, not found');
            //$this->controller = new HomeController();
			//if (!$maintenanceMode && $specifiedController)
			//	$this->controller->viewError(404);
		} else {
            $this->controller = new $this->controller();
		}
		
        // display 503: maintenance if the application is in maintenance mode.
		if ($maintenanceMode) {
			$this->controller->viewMaintenance();
			exit(0);
		}
        
        // setup user handling in Controller class.
        if (array_key_exists('USR_CLASS', $__config) && array_key_exists('USR_SESSION_KEY', $__config)) {
            Controller::setUserClass($__config['USR_CLASS'], $__config['USR_SESSION_KEY']);
        }
        
		// setup database connection if config is set.
        if (array_key_exists('DB_DRIVER', self::$config)) {
            $this->db = new DB(self::$config);
            Controller::setDB($this->db);
            Model::setDB($this->db);
        }
        
        $reflection = new \ReflectionMethod($this->controller, $this->action);
        if ($reflection->isPublic()) {
            call_user_func_array([$this->controller, $this->action], $this->parameters);
        } else {
            $this->controller->viewError(404);
		}
	}
	
	public static function log($logText, $backtraceLevel = 0)
	{
		$logFile = self::$configPath . '/log.txt';
        
        if (!file_exists($logFile)) {
            touch($logFile);
        }
        
        if (!is_writable($logFile)) {
            chmod($logFile, 0775);
        }
        
        
		$logFileContents = file_get_contents($logFile);
		
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtraceStr = json_encode($backtrace, JSON_PRETTY_PRINT);
        
		$logFileContents .= '(' . date('Y-m-d H:i:s') . ") (Client: {$_SERVER['REMOTE_ADDR']}) ($backtraceStr) $logText\n";
		file_put_contents($logFile, $logFileContents);
	}
	
    public static function getConfigValue($key)
    {
        $allowedKeys = [
            'DEBUG',
            'MAINTENANCE',
            'NAME',
            'ROOT',
            'TITLE'
        ];
        
        if (in_array($key, $allowedKeys)) {
            return self::$config[$key];
        }
                
        return null;
    }
    
	private function splitURL()
	{
		$url = isset($_GET['url']) ? $_GET['url'] : '/';
		$url = rtrim($url, '/');
		$url = filter_var($url, FILTER_SANITIZE_URL);
		$this->router->matchRoute($url, $controller, $action, $parameters);
        
        $this->controller = $controller;
        $this->action = $action;
        $this->parameters = $parameters;
	}
	
	private function underMaintenance()
	{
		$maintenanceMode = filter_var(self::$config->MAINTENANCE, FILTER_VALIDATE_BOOLEAN);
		
		if ($maintenanceMode) {
			$whitelistTxtFile = @fopen("./config/whitelist.txt", 'r'); 
			$allowed = false;
			
			if (filter_var(WHITELIST, FILTER_VALIDATE_BOOLEAN) && $whitelistTxtFile !== null) {
				$array = explode('\n', fread($whitelistTxtFile, filesize('./config/whitelist.txt')));
				foreach ($array as $ip) {
					if (preg_match('/^(?!#).+/', $ip) && preg_match('/$ip/', $_SERVER['REMOTE_ADDR'])) {
						$allowed = true;
						break;
					}
				}
			}
			
			return !$allowed;
		}
		
		return false;
	}
		
	public function __deconstruct()
	{
		$this->db = null;
        Model::setDB(null);
        Controller::setDB(null);
        Controller::setUserClass(null, null);
	}
}