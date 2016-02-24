<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

session_start();

class Application
{
    private $action = null;
    private $config = null;
	private $controller = null;
    private $db = null;
	private $parameters = array();
	
	public function __construct($__config)
	{
        $this->config = $__config;
		$this->splitUrl();
		$maintenanceMode = $this->underMaintenance();
		$specifiedController = (strlen($this->controller) > 0);
		
		if (!file_exists('./application/controller/' . $this->controller . 'Controller.php')) {
			$this->controller = new HomeController();
			if (!$maintenanceMode && $specifiedController)
				$this->controller->viewError(404);
		} else {
			$this->controller .= 'Controller';
			$this->controller = new $this->controller();
		}
		
		if ($maintenanceMode) {
			$this->controller->viewMaintenance();
			exit(0);
		}
		
		$methodParameters = '';
		if (method_exists($this->controller, $this->action) && !($this->action == "viewMaintenance" || $this->action == "viewError")) {
			if (count($this->parameters) > 0) {
				$methodParameters = $this->parameters[0];
				if (count($this->parameters) > 1) {
					$methodParameters = $this->parameters;
				}
			}
		} else {
			if (strlen($this->action) > 0)
				$this->controller->viewError(404);
			$this->action = 'index';
		}
		
		if ($this->controller) {
			$this->db = new DB($this->config);
            Model::setDB($this->db);
			$reflection = new ReflectionMethod($this->controller, $this->action);
			if ($reflection->isPublic())
				$this->controller->{$this->action}($methodParameters);
			else
				$this->controller->viewError(404);
		}
	}
	
	public static function log($logText, $backtraceLevel = 0)
	{
		$logFile = "config/log.txt";
		$logFileContents = file_get_contents($logFile);
		
		$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
		$backtraceStr = "";
		
		if (count($backtrace) > $backtraceLevel) {
			$backtraceStr = $backtrace[$backtraceLevel]['file'] . " on line " . $backtrace[$backtraceLevel]['line'];
		}
		
		$logFileContents .= "(" . date("Y-m-d H:i:s") . ") (Client: " . $_SERVER['REMOTE_ADDR'] . ") ($backtraceStr) $logText\n";
		file_put_contents($logFile, $logFileContents);
	}
	
	private function splitUrl()
	{
		if (isset($_GET['url'])) {
			$url = rtrim($_GET['url'], '/');
			$url = filter_var($url, FILTER_SANITIZE_URL);
			$url = explode('/', $url);
			$this->controller = (isset($url[0]) ? $url[0] : null);
			$this->action = (isset($url[1]) ? $url[1] : null);
			if($this->controller && $this->action) {
				for($i = 2; $i < count($url); $i++) {
					array_push($this->parameters, $url[$i]);
				}
			}
		}
	}
	
	private function underMaintenance()
	{
		$maintenanceMode = filter_var($this->config->MAINTENANCE, FILTER_VALIDATE_BOOLEAN);
		
		if ($maintenanceMode) {
			$whitelistTxtFile = @fopen("./config/whitelist.txt", 'r'); 
			$allowed = false;
			
			if (filter_var(WHITELIST, FILTER_VALIDATE_BOOLEAN) && $whitelistTxtFile) {
				$array = explode("\n", fread($whitelistTxtFile, filesize("./config/whitelist.txt")));
				foreach ($array as $ip) {
					if (preg_match("/^(?!#).+/", $ip) && preg_match("/$ip/", $_SERVER['REMOTE_ADDR'])) {
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
        Model::setDB($this->db);
        Model::setDB($this->db);
	}
}