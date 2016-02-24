<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

abstract class Controller
{
	public $title = null;
	private $user = null;
	protected $viewTemplate = true;
	
	public function index()
	{
		$this->view('index');
	}
	
	function viewMaintenance()
	{
		$templatePath = $this->templatePath("maintenance");
		if (file_exists($templatePath . "/maintenance.php")) {
			require_once $templatePath . '/maintenance.php';
		} else
			$this->viewError(503);
		
		exit(1);
	}
	
	protected function view($view, $data = array())
	{
		$this->viewFunction($view, $data, $this->viewTemplate);
	}

	protected function viewWithoutTemplate($view, $data = array())
	{
		$this->viewFunction($view, $data, false);
	}
    
    protected function viewToString($view, $data = array())
    {        
        $outputStr = "";
        $this->viewFunction($view, $data, $this->viewTemplate, $outputStr);
        
        return $outputStr;
    }
    
    protected function viewWithoutTemplateToString($view, $data = array())
    {
        $outputStr = "";
        $this->viewFunction($view, $data, false, $outputStr);
        
        return $outputStr;
    }
    
	private function viewFunction($view, $data, $viewTemplate, &$outputStr = null)
	{
		if (!is_null($data)) {
			if (!is_null($this->user))
				$data['userSession'] = true;
			$data['title'] = $this->title;
		}
		
		$selfName = get_called_class();
		$selfName = lcfirst($selfName);
		$viewPath = './application/view/' . strtolower(explode('Controller', get_class($this))[0]) . '/' . $view . '.php';
		if (file_exists($viewPath)) {
			$path = $this->templatePath();
			
            if ($outputStr !== null)
                ob_start();
            
			if ($viewTemplate)
                require_once $path . '/header.php';
            
            require_once $viewPath;
			
            if ($viewTemplate)
				require_once $path . '/footer.php';
            
            if ($outputStr !== null)
                $outputStr .= ob_get_clean();
		}
    }
        
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

		$headerStr = $errorCode . ' ' . $text;

		header($protocol . ' ' . $headerStr);
		$data = array('title' => 'Error', 'code' => $errorCode, 'message' => $text);
		$templatePath = $this->templatePath("error");
		require_once $templatePath . '/header.php';
		require_once $templatePath . '/error.php';
		require_once $templatePath . '/footer.php';
		exit(1);
	}
	
	/*
	protected function loadModel($modelName)
	{
		require './application/model/' . strtolower($modelName) . '.php';
		//return new $modelName($this->db);
	}
	*/
	
	private function templatePath($specificFile = null)
	{
		$pathPart = '_template';
		
		$selfName = get_class($this);
		$selfName = lcfirst($selfName);
		
		$tempPath = preg_replace('/Controller/', '', $selfName) . "/template";
		$appPath = "./application/view/";
		
		if (file_exists($appPath . $tempPath . "/")) {
			if (file_exists($appPath . $tempPath . "/header.php") && file_exists($appPath . $tempPath . "/footer.php")) {
				if (!is_null($specificFile) || (is_null($specificFile) && file_exists($appPath . $tempPath . "/$specificFile.php"))) {
					if (file_exists($appPath . $tempPath . "/$specificFile.php")) {
						$pathPart = $tempPath;
					}
				} else
					$pathPart = $tempPath;
			}
		}
		//die("./application/view/" . $pathPart);
		return "./application/view/" . $pathPart;
	}
}