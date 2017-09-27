<?php

namespace PHPMVC\Foundation;

use PHPMVC\DB\DB;
use PHPMVC\Foundation\Exception\HTTPException;
use PHPMVC\Foundation\Exception\NotFoundException;
use PHPMVC\Foundation\HTTP\Response;
use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\ModelQueryBuilder;
use PHPMVC\Foundation\Router;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Services;

/**
 * Class  application
 * The main file hosting the PHP running instance.
 *
 * @package  PHPMVC\Foundation
 * @author   James Stubbs
 * @version  1.0
 */
class Application
{
    /**
     * @var  Response
     */
    protected $response = null;

    /**
     * @var  Services
     */
    protected $services = null;

    /**
     * @var  array  Last recorded error.
     */
    private $error = null;

    /**
     * @var  array  Last recorded error backtrace.
     */
    private $errorBacktrace = null;

    /**
     * @var  string
     */
    private $rootPath = null;

    /**
     * @param  array  $config
     */
    public function __construct($config)
    {
        $appConfig = $config['app'];
        $this->rootPath = $config['app']['root'];

        if (!function_exists('is_associative')) {
            include(dirname(__DIR__) . '/_inc/functions.php');
        }

        $this->setupServices($config);

        $inDebug = $this->services->has('debug');

        if (!$inDebug) {
            error_reporting(E_ALL & ~E_NOTICE);
            ini_set('display_errors', 'Off');
        }

        if (!$inDebug) {
            if (set_exception_handler([$this, 'handleException']) === false) {
                echo 'Could not set exception handler.';
                die(__FILE__ . ':' . __LINE__);
            }
        }

        // display 503: service unavailable if the application is in maintenance mode.
        if ($this->underMaintenance()) {
            throw new HTTPException('', Response::STATUS_SERVICE_UNAVAILABLE);
        }

        if (set_error_handler([$this, 'handleError']) === false) {
            throw new \Exception('Could not set error handler.');
        }

        register_shutdown_function([$this, 'shutdownFunction']);

        session_start();
    }

    public function getRootPath()
    {
        return $this->rootPath;
    }

    public function getServices()
    {
        return $this->services;
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
    
    public function handleException($exception)
    {
        $errorStatus = Response::STATUS_INTERNAL_SERVER_ERROR;

        if ($this->services->has('app.logger')) {
            $this->services->get('app.logger')->error($exception->getTraceAsString());
        } else {
            error_log($exception->getTraceAsString());
        }

        if ($exception instanceof HTTPException) {
            $errorStatus = $exception->getCode();
        }

        $controller = new \PHPMVC\Foundation\__DefaultController();
        $controller->setServices($this->services);

        $this->response = $controller->viewError($errorStatus);

        $this->shutdownFunction();
    }

    public function handleIncomingRequest()
    {
        // sanitise the incoming URL.
        $url = urldecode(ltrim(rtrim(isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/', '/'), '/'));
        $urlGETStart = strpos($url, '?');

        // strip out GET parameters if they exist within the request URI.
        if ($urlGETStart !== false) {
            $url = substr($url, 0, $urlGETStart);
        }

        $router = $this->services->get('app.router', true);

        if (!$router->matchRoute($url, $controllerClass, $action, $parameters)) {
            $message = 'Cannot match any route.';

            if ($controllerClass !== null && !class_exists($controllerClass)) {
                $message = "The controller with the class '{$controllerClass}' does not exist.";
            }

            throw new NotFoundException($message);
        }
        
        $controller = new $controllerClass();
        $controller->setServices($this->services);

        // setup user handling in Controller class.
        // TODO: refactor with the use of services.
        if (isset($config['user']['class']) && isset($config['user']['sessionKey'])) {
            Controller::setUserClass(
                $config['user']['class'],
                $config['user']['sessionKey']
            );
        }

        $reflection = new \ReflectionMethod($controller, $action);

        if (!$reflection->isPublic()) {
            throw new NotFoundException("The method '{$action}' within the class '{$controllerClass}' is not publically accessible.");
        }

        $response = call_user_func_array([$controller, $action], $parameters);

        if ($response === null) {
            throw new \Exception(
                'No ' . Response::class . " or subclass was returned from the method '$action' in the controller '$controllerClass'."
            );
        }

        if (!$response instanceof Response) {
            throw new \Exception(
               "The return value from the method '$action' in the controller '$controllerClass' which is of type "
               . get_class($response)
               . ' is not an instance of '
               . Response::class
               . '.'
            );
        }

        $this->response = $response;
        $this->services->get('app.renderer', true); // just call to make sure the rendering service has been implemented.
    }

    public function shutdownFunction()
    {
        $error = $this->error ?: error_get_last();
        $inDebug = $this->services->has('debug');
        $responseValid = $this->response instanceof Response;

        if ($error !== null) {
            if ($inDebug) {
                throw new \Exception("{$error['message']} in {$error['file']} on {$error['line']}");
            }

            if ($error['type'] !== E_WARNING) {
                $controller = new __DefaultController();
                $controller->setServices($this->services);
                $this->response = $controller->viewError(Response::STATUS_INTERNAL_SERVER_ERROR);
            }
        }

        if ($inDebug) {
            if ($responseValid) {
                $this->response->setInDebug(true);
            }

            $debugService = $this->services->get('debug');
            $debugService->finishProfiling();
        }

        if ($this->services->has('app.renderer') && $responseValid) {
            $this->services->get('app.renderer')->render($this->response);
        }
    }

    // TODO: convert function to a service.
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

    public function isWhitelisted()
    {
        $configService = $this->services->get('app.config');

        if ($configService->get('app.whitelist') !== true) {
            return false;
        }

        $whitelisted = false;

        $rootDir = $configService->get('app.root');
        $whiteListFile = "$rootDir/config/whitelist.txt";

        if (file_exists($whiteListFile)) {
            $whitelistFileHandler = fopen($whiteListFile, 'r');

            if ($whitelistFileHandler !== null) {
                $ips = explode(PHP_EOL, fread($whitelistFileHandler, filesize($whiteListFile)));

                foreach ($ips as $ip) {
                    if (preg_match('/^(?!#).+/', $ip) && preg_match("/$ip/", $_SERVER['REMOTE_ADDR'])) {
                        $whitelisted = true;
                        break;
                    }
                }
            }

            fclose($whitelistFileHandler);
        }

        return $whitelisted;
    }

    protected function setupServices($config)
    {
        $services = new Services();

        $services->register('app.config', ConfigService::class);
        $configService = $services->get('app.config');

        foreach ($config as $name => $value) {
            $configService->set($name, $value);
        }

        include($this->rootPath . '/config/services.php');

        $this->services = $services;
    }

    private function underMaintenance()
    {
        if ($this->services->get('app.config')->get('app.maintenance') === true) {
            return !$this->isWhitelisted();
        }

        return false;
    }

    public function __deconstruct()
    {
        Controller::setUserClass(null, null);
        unset($this->services);
    }
}
