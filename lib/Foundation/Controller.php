<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation;

use PHPMVC\Foundation\Application;

abstract class Controller
{
    const USRLOGIN_OK = 0;
    const USRLOGIN_LOGGED_IN = 1;
    const USRLOGIN_INVALID_CRED = 2;
    const USRLOGIN_INVALID_STATUS = 3;
    const USRLOGIN_ERROR = 4;
    
    public $title = null;
    protected $globalViewDataOn = true;
    protected $loginCheck = false;
	protected $user = null;
	protected $viewTemplate = true;
    protected static $db = null;
    protected static $rootDir = null;
    protected static $userClass = null;
    protected static $userSessionKey = null;
	
    final public static function setDB($db)
    {
        self::$db = $db;
    }
    
    final public static function setUserClass($userClass, $userSessionKey)
    {
        self::$userClass = $userClass;
        self::$userSessionKey = $userSessionKey;
    }
    
	public function index()
	{
		$this->view('index');
	}
	
    /**
     * @return  array   Data to be passed with each view call regardless of whether the controller presents the view with or without the template.
     */
    protected function globalViewData()
    {
        return null;
    }
    
    /**
     * Attempts a login with '$username' and '$password'.
     * The controller fetches the user with the username of '$username'.
     * If retrieved, the password is checked.
     * A status representing the success or type of error occured when logging in is returned.
     *
     * @param   string      $username   Username of the user attempting to login.
     * @param   string      $password   Password of the user attempting to login.
     *
     * @return  integer                 Status 
     */
    protected function loginUser($username, $password)
    {
        // user is already logged in,
        // so don't continue.
        if ($this->isLoggedIn()) {
			return self::USRLOGIN_LOGGED_IN;
		}
        
        // the selected user class must have the method below defined.
        $userClass = self::$userClass;
        $user = $userClass::findByLogin($username, $password);
        
        if ($user !== null) {
            // the selected user class must have the method below defined.
            if ($user->isOpen()) {
                // set the session key.
                // then store the key in the database under the user's record.
                $userSessionKey = self::$userSessionKey;
                $_SESSION[$userSessionKey] = uniqid($userSessionKey);
                
                $user->{$userSessionKey} = $_SESSION[$userSessionKey];
                
                if (!$user->update()) {
                    // problem with updating the user's session key in the database.
                    return self::USRLOGIN_ERROR;
                }
                
                // username found and password matches.
                return self::USRLOGIN_OK;
            }
            
            // user found, but status is not open.
            return self::USRLOGIN_INVALID_STATUS;
        }
        
        // either user was not found and/or password did not match.
        return self::USRLOGIN_INVALID_CRED;
    }
    
    protected function isLoggedIn()
	{
        if ($this->loginCheck === true) {
            return ($this->user !== null);
        }
        
        if (self::$userClass !== null && self::$userSessionKey !== null) {
            $sessionKey = self::$userSessionKey;
            
            if (isset($_SESSION[$sessionKey])) {
                $userClass = self::$userClass;
                $sessionID = $_SESSION[$sessionKey];
                
                $user = $userClass::findBySession($sessionID);
                
                if ($user !== null) {
                    $this->user = $user;
                    return true;
                } else {
                    unset($_SESSION[$sessionKey]);
                }
            }
        }
        
        return false;
	}
    
	public function viewMaintenance()
	{
		$templatePath = $this->getTemplatePath('maintenance');
        
		if (file_exists($templatePath . '/maintenance.php')) {
			require_once $templatePath . '/maintenance.php';
		} else {
			$this->viewError(503);
        }
		
		exit(1);
	}
	
	protected function view($view, $data = [], $viewPath = null)
	{
		$this->viewFunction($view, $data, $this->viewTemplate, $viewPath);
	}
    
	protected function viewWithoutTemplate($view, $data = [], $viewPath = null)
	{
		$this->viewFunction($view, $data, false, $viewPath);
	}
    
    protected function viewToString($view, $data = [], $viewPath = null)
    {
        $outputStr = '';
        $this->viewFunction($view, $data, $this->viewTemplate, $outputStr, $viewPath);
        
        return $outputStr;
    }
    
    protected function viewWithoutTemplateToString($view, $data = [], $viewPath = null)
    {
        $outputStr = '';
        $this->viewFunction($view, $data, false, $outputStr, $viewPath);
        
        return $outputStr;
    }
    
	protected function viewFunction($view, $data, $viewTemplate, &$outputStr = null, $viewPath = null)
	{
		if (!is_null($data)) {
            if ($viewTemplate) {
                $data['title'] = $this->title;
            }
            
            if ($this->globalViewDataOn) {
                if ($this->isLoggedIn() && !is_null($this->user)) {
				    $data['user'] = $this->user;
                }
                
                $globalViewData = $this->globalViewData();
                
                if (!!$globalViewData) {
                    $data = array_merge($globalViewData, $data);
                }
            }
		}
        
        $selfClass = get_called_class();
		$selfNameParts = explode('\\', $selfClass);
        $selfName = array_pop($selfNameParts);
        
        // default prefix set as the namespace of the calling class.
        if (strpos($view, ':') === false) {
            $selfPrefix = array_shift($selfNameParts);
            $view = $selfPrefix . ':' . $view;
        }
        
        $viewParts = explode(':', $view);
        $prefix = array_shift($viewParts);
        $view = array_pop($viewParts);
        
        $viewPath = $this->getViewPath($prefix);
        
        if (file_exists($viewPath)) {
            if ($outputStr !== null) {
                ob_start();
            }
            
			if ($viewTemplate) {
                $this->viewHeader($prefix, $data);
            }
            
            include realpath($viewPath . '/' . ucfirst(str_replace('Controller', '', $selfName)) . '/' . $view . '.php');
            
            if ($viewTemplate) {
				$this->viewFooter($prefix, $data);
            }
            
            if ($outputStr !== null) {
                $outputStr .= ob_get_clean();
            }
		} else {
            throw new \Exception("View for path: '$viewPath' not found.");
        }
    }
    
    protected function viewHeader($prefix, $data)
    {
        $path = $this->getTemplatePath($prefix, 'header');
        
        if ($path !== null) {
            $path = $path . '/header.php';
            
            include $path;
        }
    }
    
    protected function viewFooter($prefix, $data)
    {
        $path = $this->getTemplatePath($prefix, 'footer');
        
        if ($path !== null) {
            $path = $path . '/footer.php';
            
            include $path;
        }
    }
    
    // TODO: refactor status code presentation.
	function viewError($errorCode) {
		$text = '';
		switch ($errorCode) {
			case 100: $text = 'Continue'; break;
			case 101: $text = 'Switching Protocols'; break;
			case 200: $text = 'OK'; break;
			case 201: $text = 'Created'; break;
			case 202: $text = 'Accepted'; break;
			case 203: $text = 'Non-Authoritative Information'; break;
			case 204: $text = 'No Content'; break;
			case 205: $text = 'Reset Content'; break;
			case 206: $text = 'Partial Content'; break;
			case 300: $text = 'Multiple Choices'; break;
			case 301: $text = 'Moved Permanently'; break;
			case 302: $text = 'Moved Temporarily'; break;
			case 303: $text = 'See Other'; break;
			case 304: $text = 'Not Modified'; break;
			case 305: $text = 'Use Proxy'; break;
			case 400: $text = 'Bad Request'; break;
			case 401: $text = 'Unauthorized'; break;
			case 402: $text = 'Payment Required'; break;
			case 403: $text = 'Forbidden'; break;
			case 404: $text = 'Not Found'; break;
			case 405: $text = 'Method Not Allowed'; break;
			case 406: $text = 'Not Acceptable'; break;
			case 407: $text = 'Proxy Authentication Required'; break;
			case 408: $text = 'Request Time-out'; break;
			case 409: $text = 'Conflict'; break;
			case 410: $text = 'Gone'; break;
			case 411: $text = 'Length Required'; break;
			case 412: $text = 'Precondition Failed'; break;
			case 413: $text = 'Request Entity Too Large'; break;
			case 414: $text = 'Request-URI Too Large'; break;
			case 415: $text = 'Unsupported Media Type'; break;
			case 500: $text = 'Internal Server Error'; break;
			case 501: $text = 'Not Implemented'; break;
			case 502: $text = 'Bad Gateway'; break;
			case 503: $text = 'Service Unavailable'; break;
			case 504: $text = 'Gateway Time-out'; break;
			case 505: $text = 'HTTP Version not supported'; break;
			default: $text = 'Unknown HTTP Status Code'; break;
		}

		$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');

		$headerStr = "$errorCode $text";
        
		header("$protocol $headerStr");
		$data = array('title' => 'Error', 'code' => $errorCode, 'message' => $text);
		$templatePath = $this->getTemplatePath(null, 'error');
		require_once $templatePath . '/header.php';
		require_once $templatePath . '/error.php';
		require_once $templatePath . '/footer.php';
		exit(1);
	}
	
    private function getViewPath($prefix = null)
    {
        // get root directory from Application class.
        if (self::$rootDir === null) {
            self::$rootDir = Application::getConfigValue('ROOT');
        }
        
        // get the autoloader to resolve 
        global $loader;
        $prefixes = array_merge($loader->getPrefixes(), $loader->getPrefixesPsr4());
        
        foreach ($prefixes as $name => $dir) {
            $pos = strpos($name, '\\');
            
            if ($pos !== false) {
                $newName = substr_replace($name, '', $pos, 1);
                
                $prefixes[$newName] = $dir;
                unset($prefixes[$name]);
            }
        }
                
        // set the default prefix to the name of the hosting application.
        if ($prefix === null) {
            $prefix = Application::getConfigValue('NAME');
        }
        
        // if prefix does not exist in the prefixes array,
        // return a blank string as it will be impossible to determine the location of the view file without an explicit path being sent to here.
        if (!isset($prefixes[$prefix])) {
            return '';
        }
        
        $prefixDir = $prefixes[$prefix][0];
        
        $viewPath = realpath($prefixDir . '/View');
        
        return $viewPath;
    }
    
	private function getTemplatePath($prefix = null, $specificFile = null)
	{
        $templatePath = $this->getViewPath($prefix);
        
        if ($templatePath !== false) {
            $selfNameParts = explode('\\', get_called_class());
            $selfName = explode('Controller', array_pop($selfNameParts))[0];
            
            $searchingPath = "/$selfName/_template";
            
            if ($specificFile !== null) {
                $searchingPath .= "/$specificFile.php";
            } else {
                $searchingPath .= '/header.php';
            }
            
            if (file_exists($templatePath . $searchingPath)) {
                $templatePath .= "/$selfName/_template";
            } else {
                $templatePath .= '/_template';
            }
        } else {
            $templatePath = '';
        }
        
		return $templatePath;
	}
}