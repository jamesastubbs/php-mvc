<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\Foundation;

use PHPMVC\Foundation\Application;
use PHPMVC\Foundation\HTTP\JSONResponse;
use PHPMVC\Foundation\HTTP\Response;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

abstract class Controller implements ServiceableInterface
{
    const USRLOGIN_OK = 0;
    const USRLOGIN_LOGGED_IN = 1;
    const USRLOGIN_INVALID_CRED = 2;
    const USRLOGIN_INVALID_STATUS = 3;
    const USRLOGIN_ERROR = 4;

    /**
     * @var  string
     */
    public $title = null;

    /**
     * @var  string
     */
    protected $appTitle = '';

    /**
     * @var  string
     */
    protected $globalViewDataOn = true;

    /**
     * @var  boolean  'true' if the controller has checked to see if a user is logged in.
     */
    protected $loginCheck = false;

    /**
     * @var  boolean
     */
    protected $inDebug = false;

    /**
     * @var  string  Main namespace of the running application.
     */
    protected $name = null;

    /**
     * @var  Services
     */
    protected $services = null;

    /**
     * @var  UserInterface
     */
    protected $user = null;

    /**
     * @var  string
     */
    protected $userClass = null;

    /**
     * @var  string
     */
    protected $userSessionKey = null;

    /**
     * @var  boolean
     */
    protected $viewTemplate = true;

    /**
     * @var  RenderService
     */
    private $renderer = null;

    public function index()
    {
        return $this->view('index');
    }

    public function setServices(Services $services)
    {
        $this->services = $services;

        $configService = $services->get('app.config', true);
        $appConfig = $configService->get('app');
        $userConfig = $configService->get('user');

        $this->appTitle = $appConfig['title'];
        $this->inDebug = isset($appConfig['debug']) ? $appConfig['debug'] === true : false;
        $this->name = $appConfig['name'];
        $this->userClass = $userConfig['class'];
        $this->userSessionKey = $userConfig['sessionKey'];
    }

    /**
     * @return  array  Data to be passed with each view call regardless of whether the controller presents the view with or without the template.
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
        $userClass = $this->userClass;
        $user = $userClass::findByLogin($username, $password);

        if ($user !== null) {
            // the selected user class must have the method below defined.
            if ($user->isOpen()) {
                // set the session key.
                // then store the key in the database under the user's record.
                $userSessionKey = $this->userSessionKey;
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
            return $this->user !== null;
        }

        if ($this->userClass !== null && $this->userSessionKey !== null) {
            $sessionKey = $this->userSessionKey;

            if (isset($_SESSION[$sessionKey])) {
                $userClass = $this->userClass;
                $sessionID = $_SESSION[$sessionKey];

                $user = $userClass::findBySession($sessionID);

                if ($user !== null) {
                    $this->user = $user;

                    return true;
                }
            }
        }

        return false;
    }

    // TODO: refactor status code presentation.
    public function viewError($errorStatus)
    {
        $data = ['title' => 'Error', 'code' => $errorStatus, 'message' => ''];
        $templatePath = $this->getTemplatePath(null, 'error');

        ob_start();

        require_once $templatePath . '/header.php';
        require_once $templatePath . '/error.php';
        require_once $templatePath . '/footer.php';

        $body = ob_get_clean();

        return new Response($body, $errorStatus);
    }

    public function viewMaintenance()
    {
        $templatePath = $this->getTemplatePath('maintenance');

        if (file_exists($templatePath . '/maintenance.php')) {
            ob_start();

            require_once $templatePath . '/maintenance.php';

            $body = ob_get_clean();

            return new Response($body, Response::STATUS_SERVICE_UNAVAILABLE);
        }

        return $this->viewError(Response::STATUS_SERVICE_UNAVAILABLE);
    }

    protected function view($view, $data = [], $viewPath = null)
    {
        $outputStr = '';
        $this->viewFunction($view, $data, $this->viewTemplate, $outputStr, $viewPath);

        return new Response($outputStr);
    }

    protected function viewJSON($data, $status = 200)
    {
        return new JSONResponse($data, $status);
    }

    protected function viewWithoutTemplate($view, $data = [], $viewPath = null)
    {
        $outputStr = '';
        $this->viewFunction($view, $data, false, $outputStr, $viewPath);

        return new Response($outputStr);
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
        $data = null === $data ? [] : $data;

        if ($this->globalViewDataOn) {
            if ($this->isLoggedIn() && $this->user !== null) {
                $data['user'] = $this->user;
            }

            $globalViewData = $this->globalViewData();

            if ($globalViewData !== null) {
                $data = array_merge($globalViewData, $data);
            }
        }

        if (!isset($data['pageTitle'])) {
            $data['pageTitle'] = $this->title ?: '';
        }

        $data['services'] = $this->services;
        $data['title'] = $this->appTitle;

        $self = &$this;
        $selfClass = get_called_class();

        $extractPrefix = function (&$viewStr, $rebuildStr = true) {
            $viewParts = explode(':', $viewStr);
            $prefix = array_shift($viewParts);

            if ($rebuildStr) {
                $viewStr = array_pop($viewParts);
            }

            return $prefix;
        };

        // function to set the Controller class name.
        // we this, so that sub classed Controllers can use parent-owned views.
        $setSelfName = function (&$selfName, $selfClass, &$selfNameParts = null) {
            if ($selfClass !== false) {
                $selfNameParts = explode('\\', $selfClass);
                $selfName = array_pop($selfNameParts);

                // memory management.
                unset($selfNameParts);
            }
        };

        $includeFunction = function ($view, array $data) use ($extractPrefix, $self, $selfClass, $setSelfName) {
            // get the calling Controller class name.
            $selfName = '';

            // set the default calling class name.
            $setSelfName($selfName, $selfClass, $selfNameParts);

            // default prefix set as the namespace of the calling class.
            if (strpos($view, ':') === false) {
                $selfPrefix = array_shift($selfNameParts);
                $view = "$selfPrefix:$view";
            }

            // grab the prefix from the view string.
            $prefix = $extractPrefix($view);

            // throw an exception if the view string is blank.
            if ($view === '') {
                throw new \Exception('View name cannot be blank.');
            }

            // get the base view file path.
            $rootViewPath = $self->getViewPath($prefix);

            // throw an exception as the base view path cannot be found.
            if (!file_exists($rootViewPath)) {
                throw new \Exception("View for path: '$rootViewPath' not found.");
            }

            // set up initial view file path.
            $includePath = realpath("$rootViewPath/" . ucfirst(str_replace('Controller', '', $selfName)) . "/$view.php");

            // iterate until next available view file exists.
            // we start with the calling class and work our way throught the parent classes.
            // this enables the controller to inherit parent views.
            while ($selfClass !== false && !file_exists($includePath)) {
                $selfClass = get_parent_class($selfClass);
                $setSelfName($selfName, $selfClass);

                $includePath = realpath("$rootViewPath/" . ucfirst(str_replace('Controller', '', $selfName)) . "/$view.php");
            }

            // if '$selfClass' is false,
            // that means the calling controller or any of it's relatives do not hold the requested view file,
            // so throw an exception.
            if ($selfClass === false) {
                throw new \Exception("View not found for '$view'.");
            }

            // anonymous function keeps visibility to current variables and values to a minimum.
            $include = function () use ($includePath, $data) {
                include $includePath;
            };
            $include();
        };

        $data['include'] = function ($view, $viewData = []) use (&$data, &$includeFunction) {
            $viewData = array_merge($data, $viewData);

            $includeFunction($view, $viewData);
        };

        // if the calling controller is requiring a string output,
        // start the output buffer.
        if ($outputStr !== null) {
            ob_start();
        }

        // can be used by two separate 'if' statements,
        // if the template has been requested.
        $prefix = null;

        if ($viewTemplate) {
            // if there is no prefix in the '$view' string,
            // then get the calling class name and set that as the prefix.
            if (strpos($view, ':') === false) {
                // get the default calling class name.
                $selfName = '';
                $setSelfName($selfName, $selfClass, $selfNameParts);

                // rebuild the '$view' string with the new prefix value.
                $prefix = array_shift($selfNameParts);
                $view = "$prefix:$view";
            } else {
                // else, retrieve the prefix from the string.
                $prefix = $extractPrefix($view, false);
            }

            $this->viewHeader($prefix, $data);
        }

        // include the called view.
        $includeFunction($view, $data);

        if ($viewTemplate) {
            $this->viewFooter($prefix, $data);
        }

        // if we have started the output buffer,
        // stop it, grab the value and then clean up.
        if ($outputStr !== null) {
            $outputStr .= ob_get_clean();
        }
    }

    protected function viewHeader($prefix, $data)
    {
        $path = $this->getTemplatePath($prefix, 'header');

        if ($path !== null) {
            $path = "$path/header.php";

            include $path;
        }
    }

    protected function viewFooter($prefix, $data)
    {
        $path = $this->getTemplatePath($prefix, 'footer');

        if ($path !== null) {
            $path = "$path/footer.php";

            if ($this->services->has('debug')) {
                $data['debugService'] = $this->services->get('debug');
            }

            include $path;
        }
    }

    private function getViewPath($prefix = null)
    {
        // TODO: refactor this. Could potentially use the routing service?
        // get the autoloader to resolve 
        $loader = $this->services->get('app.config', true)->get('app.loader');

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
            $prefix = $this->name;
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

    protected function getRoot()
    {
        // get root directory from Application class.
        return $this->services->get('app.config')->get('app.root');
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
