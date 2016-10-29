<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation;

use PHPMVC\DB\DB;
use PHPMVC\Foundation\Exception\NotFoundException;
use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\ModelQueryBuilder;
use PHPMVC\Foundation\Router;

class Application
{
    private $action = null;
	private $controller = null;
    private $db = null;
    private $error = null;
    private $errorBacktrace = null;
	private $parameters = array();
    private $router = null;
    private static $config = null;
    private static $configPath = null;
    
	public function __construct($__config)
	{
        self::$config = $__config;
        self::$configPath = $__config['ROOT'] . '/config';
        
        // display 503: maintenance if the application is in maintenance mode.
		if ($this->underMaintenance()) {
			(new __DefaultController())->viewMaintenance();
			exit(0);
		}
        
        if (!$__config['DEBUG']) {
            error_reporting(E_ALL & ~E_NOTICE);
            
            ini_set('display_errors', 'Off');
            
            if (!set_exception_handler([$this, 'handleException']) === false) {
                echo 'Could not set exception handler.';
                die(__FILE__ . ':' . __LINE__);
            }
            
            if (!set_error_handler([$this, 'handleError']) === false) {
                throw new \Exception('Could not set error handler.');
            }
            
            register_shutdown_function([$this, 'shutdownFunction']);
        }
    
        session_start();
        
        $this->router = new Router($__config['NAME'], $__config['ROOT'], $__config['LOADER']->getPrefixes());
        
		if (!$this->matchRoute()) {
            $message = 'Cannot match any route.';
            
            if ($this->controller !== null && !class_exists($this->controller)) {
                $message = "The controller with the class '{$this->controller}' does not exist.";
            }
            
			throw new NotFoundException($message);
		}
        
        $this->controller = new $this->controller();
		
        // setup user handling in Controller class.
        if (isset($__config['USR_CLASS']) && isset($__config['USR_SESSION_KEY'])) {
            Controller::setUserClass(
                $__config['USR_CLASS'],
                $__config['USR_SESSION_KEY']
            );
        }
        
		// setup database connection if config is set.
        if (isset(self::$config['DB_DRIVER'])) {
            $this->db = new DB(self::$config);
            Controller::setDB($this->db);
            Model::setDB($this->db);
            ModelQueryBuilder::setDB($this->db);
        }
        
        $reflection = new \ReflectionMethod($this->controller, $this->action);
        if ($reflection->isPublic()) {
            call_user_func_array([$this->controller, $this->action], $this->parameters);
        } else {
            throw new NotFoundException("The method '{$this->action}' within the class '{$this->controller}' is not publically accessible.");
		}
	}
	
    public function handleError($errorNo, $errorStr, $errorFile, $errorLine, $errorContext)
    {
        $this->error = [
            'file' => $errorFile,
            'line' => $errorLine,
            'message' => $errorStr,
            'type' => E_ERROR
        ];
        
        $errorBacktrace = debug_backtrace();
        $errorBacktrace = array_splice($errorBacktrace, 0, 1);
        $this->errorBacktrace = $errorBacktrace;
    }
    
    public function handleException(\Exception $exception)
    {
        $controller = new \PHPMVC\Foundation\__DefaultController();
        $exceptionClass = get_class($exception);
        
        if ($exceptionClass === NotFoundException::class) {
            $controller->viewError(404);
        } else if ($exceptionClass === NotFoundException::class) {
            $controller->viewError(500);
        }
    }
    
    public function shutdownFunction()
    {
        $error = $this->error ?: error_get_last();
        
        if ($error !== null) {
            $controller = new \PHPMVC\Foundation\__DefaultController();
            $controller->viewError(500);
        }
    }
    
    public static function mail($callback)
    {
        if (!isset(self::$config['MAIL_HOST'])) {
            throw new \Exception('Cannot send any mail as the settings have not been configured.');
        }
        
        if (!class_exists('\\PHPMailer')) {
            $classPath = self::getConfigValue('ROOT') . '/vendor/phpmailer/phpmailer/PHPMailerAutoload.php';
            
            if (!file_exists($classPath)) {
                throw new \Exception('PHPMailer composer package is not installed. Please run \'composer require phpmailer/phpmailer\' from the root application directory.');
            }
            
            require_once $classPath;
        }
        
        $mailer = new \PHPMailer();
        $mailer->isSMTP();
        $mailer->Host = self::$config['MAIL_HOST'];
        $mailer->Port =  self::$config['MAIL_PORT'];
        $mailer->Username =  self::$config['MAIL_USER'];
        $mailer->Password =  self::$config['MAIL_PASS'];
        $mailer->SMTPSecure =  self::$config['MAIL_ENCRYPT'];
        $mailer->SMTPAuth = self::$config['MAIL_AUTH'];
        
        $callback($mailer);
        
        $result = $mailer->send();
        
        return $result;
    }
    
	public static function log($logText, $backtraceLevel = 0)
	{
		$logFile = self::$configPath . '/log.txt';
        
        if (!file_exists($logFile)) {
            touch($logFile);
        }
        
        if (!is_writable($logFile)) {
            chmod($logFile, 0770);
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
            'TITLE',
            'WHITELIST'
        ];
        
        if (in_array($key, $allowedKeys)) {
            return self::$config[$key];
        }
                
        return null;
    }
    
    public static function isWhitelisted()
    {
        $whitelisted = false;
        
        $rootDir = self::getConfigValue('ROOT');
        $whiteListFile = "$rootDir/config/whitelist.txt";
        $whitelistFileHandler = @fopen($whiteListFile, 'r');
        
        if ($whitelistFileHandler !== null) {
            $ips = explode(PHP_EOL, fread($whitelistFileHandler, filesize($whiteListFile)));
            
            foreach ($ips as $ip) {
                if (preg_match('/^(?!#).+/', $ip) && preg_match("/$ip/", $_SERVER['REMOTE_ADDR'])) {
                    $whitelisted = true;
                    break;
                }
            }
        }
        
        @fclose($whitelistFileHandler);
        
        return $whitelisted;
    }
    
	private function matchRoute()
	{
        $url = isset($_GET['url']) ? $_GET['url'] : '/';
        $url = rtrim($url, '/');
        $url = filter_var($url, FILTER_SANITIZE_URL);
        
        $result = $this->router->matchRoute($url, $controller, $action, $parameters);
        
        $this->controller = $controller;
        $this->action = $action;
        $this->parameters = $parameters ?: [];
        
        return $result;
    }
	
	private function underMaintenance()
	{
        if (self::getConfigValue('MAINTENANCE') === true) {
            $whitelisted = self::getConfigValue('WHITELIST') && self::isWhitelisted();
            
			return !$whitelisted;
		}
		
		return false;
	}
		
	public function __deconstruct()
	{
		$this->db = null;
        Controller::setDB(null);
        Controller::setUserClass(null, null);
        Model::setDB(null);
        ModelQueryBuilder::setDB(null);
	}
}
