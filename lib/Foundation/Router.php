<?php

namespace PHPMVC\Foundation;

use PHPMVC\Foundation\Application;

class Router
{
    private $name = null;
    private $namespaces = null;
    private $rootPath = null;
    private $routes = [];
    private $baseRoutes = [];
    
    public function __construct($name, $rootPath, array $namespaces)
    {
        $this->name = $name;
        $this->namespaces = $namespaces;
        $this->rootPath = $rootPath;

        $this->processRoutesFromPath("$rootPath/config/routes.json");
    }

    /**
     * Fetches the JSON content from the file located under '$path'.
     * The JSON data is then processed to populate the routes within this class instance to be used later on.
     *
     * @param  string  $path  Location of the JSON routes file.
     */
    private function processRoutesFromPath($path)
    {
        $routes = $this->getRoutesFromHTTPMethod(
            Application::getHTTPMethod(),
            $path
        );

        $this->processRoutes($routes);
    }

    /**
     * Processes the routes in '$routesPath' that fall under the HTTP method of '$method'.
     *
     * @param   string  $method      Received HTTP request method.
     * @param   string  $routesPath  Location of the routes file.
     *
     * @return  array                Collection of routes found in the routes file.
     * @throws  Exception            If there is an issue processing the routes file.
     */
    private function getRoutesFromHTTPMethod($method, $routesPath)
    {
        if (!file_exists($routesPath)) {
            throw new \Exception("Config file '$routesPath' not found.");
        }

        $jsonString = file_get_contents($routesPath);
        $routesArray = json_decode($jsonString, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error parsing JSON file '$routesPath' - " . json_last_error_msg());
        }

        $routesMethods = array_keys($routesArray);
        $filteredRoutesMethods = preg_grep("/$method|\*/", $routesMethods);
        $routes = [];

        foreach ($filteredRoutesMethods as $method) {
            $routes = array_merge($routes, $routesArray[$method]);
        }

        if (in_array('@import', $routesMethods)) {
            foreach ($routesArray['@import'] as $routePath) {
                $namespace = explode(':', $routePath)[0];

                if (!isset($this->namespaces[$namespace])) {
                    throw new \Exception("Namespace '$namespace' not found.");
                }

                $path = current($this->namespaces[$namespace]) . '/../config/routes.json';

                $importedRoutes = $this->getRoutesFromHTTPMethod($method, $path);
                $routes = array_merge($routes, $importedRoutes);
            }
        }

        return $routes;
    }

    private function processRoutes(array $routes)
    {
        foreach ($routes as $routePath => $route) {
            if (preg_match('/^@.+/', $route)) {
                switch (str_replace('@', '', $route)) {
                    case 'default':
                        $this->baseRoutes[] = explode(':', $routePath)[0];
                        break;
                    default:
                        break;
                }
                continue;
            }
            
            $routePathI = 0;
            $routePathReplacements = [];
            
            if (!preg_match_all('/^([^\:\:]+)::([A-Za-z0-9_]+)\(([^\)]+)?\)/', $route, $routeParts)) {
                throw new \Exception("Invalid route: '$route'");
            }
            
            array_shift($routeParts);
            
            // get controller, action and parameters values from the regex output.
            $controller = $routeParts[0][0];
            $action = $routeParts[1][0];
            $parametersString = $routeParts[2][0];
            $parameters = null;
            
            if ($parametersString !== '') {
                $parameters = [
                    '__order' => [],
                    '__regex' => []
                ];
                
                preg_match_all('/(?:^|, ?)(?:(-?(?:(?:[0-9]\d*))(?:\.\d+)?)|([A-Za-z0-9_]+)|(\'[^\'\\\]*(?:\\.[^\'\\\]*)*\'))/', $parametersString, $parametersParts);
                array_shift($parametersParts);
                
                $parametersCount = count($parametersParts[0]);
                
                for ($i = 0; $i < $parametersCount; $i++) {
                    $parameterName = null;
                    $parameterValue = null;
                    
                    if ($parametersParts[0][$i] !== '') {
                        // group 1 has a value, this is a number.
                        
                        $parameterName = "int_$i";
                        $parameterValue = strpos($parametersParts[0][$i], '.') !== false ? floatval($parametersParts[0][$i]) : intval($parametersParts[0][$i]);
                    } else if ($parametersParts[1][$i] !== '') {
                        // group 2 has a value, this is a variable referenced in the '$routePath'.
                        
                        $parameterName = $parametersParts[1][$i];
                    } else if ($parametersParts[2][$i] !== '') {
                        // group 3 has a value, this is a string.
                        
                        $parameterName = "str_$i";
                        $parameterValue = substr($parametersParts[2][$i], 1, -1);
                    }
                    
                    if ($parameterName !== null) {
                        $parameters['__order'][] = $parameterName;
                        $parameters[$parameterName] = $parameterValue;
                    }
                }
                
                // retrieve all route parts, so that we can build continue to build the route URL.
                $routePath = preg_replace_callback('/\{([A-Za-z0-9_]+)(?:\:(?:\ )?(?:\'([^\']*(?:\.[^\']*)*)\'))?\}/', function ($matches) use (&$parameters, &$routePathI, &$routePathReplacements) {
                    $variable = $matches[1];
                    $regex = '(' . (isset($matches[2]) ? $matches[2] : '[A-Za-z0-9_]+') . ')';
                    
                    if (!array_key_exists($variable, $parameters)) {
                        throw new \Exception("Route variable '$variable' could not be found.");
                    }
                    
                    $parameters['__regex'][$variable] = $regex;
                    
                    $routePathI++;
                    $key = "\$__$routePathI";
                    $routePathReplacements["\\$key"] = $regex;
                    
                    return $key;
                }, $routePath);
            }
            
            $routePath = '/^' . preg_quote($routePath, '/');
            
            foreach ($routePathReplacements as $key => $replacement) {
                $routePath = str_replace($key, $replacement, $routePath);
            }
            
            // append GET parameter regex.
            $routePath .= '(?:(?:\?\S[^ \?]+)|)$/';
            
            $this->routes[$routePath] = [
                'controller' => $controller,
                'action' => $action,
                'parameters' => $parameters
            ];
        }
    }
    
    public function matchRoute($url, &$controller, &$action, &$parameters)
    {
        $urlParts = explode('/', $url);
        
        $url = "/$url";
        $routesKeys = array_keys($this->routes);
        $route = null;
        
        foreach ($routesKeys as $routeKey) {
            if (preg_match_all($routeKey, $url, $urlMatches)) {
                array_shift($urlMatches);
                
                // store the found route.
                $route = $this->routes[$routeKey];
                break;
            }
        }
        
        if ($route !== null) {
            $controller = $route['controller'];
            $action = $route['action'];
            $parameters = [];
            
            $urlMatchesI = 0;
            $routeParameters = $route['parameters'];
            $routeParametersOrder = $routeParameters['__order'];
            $routeParametersCount = count($routeParametersOrder);
            
            for ($i = 0; $i < $routeParametersCount; $i++) {
                $routeParameterName = $routeParametersOrder[$i];
                $routeParameter = $routeParameters[$routeParameterName];
                
                // if the parameter is null,
                // add in the value from the received URL match.
                if ($routeParameter === null) {
                    $routeParameter = $urlMatches[$urlMatchesI][0];
                    $urlMatchesI++;
                }
                
                // add the parameter.
                $parameters[] = $routeParameter;
            }
            
            if ($routeParametersCount !== count($parameters)) {
                // we set these as null so that we proceed to continue to search for the called controller.
                $parameters = null;
                $route = null;
            }
        }
        
        // search for existing controller as last resort.
        if ($route === null) {
            $action = null;
            $controller = null;
            $parameters = null;
            
            $baseRoutes = array_merge([$this->name], $this->baseRoutes);
            
            $_controller = (isset($urlParts[0]) && $urlParts[0] !== '') ? $urlParts[0] : null;
            $_action = isset($urlParts[1]) ? $urlParts[1] : null;
            
            if ($_controller !== null) {
                foreach ($baseRoutes as $baseRoute) {
                    // setup controler class name so it is relative to the namespace.
                    $__controller = $baseRoute . '\\Controller\\' . ucfirst($_controller) . 'Controller';
                    $_parameters = [];
                    
                    $urlPartsCount = count($urlParts);
                    
                    // start at index 2 as the first two parts dictate the calling controller and the action.
                    for ($i = 2; $i < $urlPartsCount; $i++) {
                        $_parameters[] = $urlParts[$i];
                    }
                    
                    if (class_exists($__controller)) {
                        if ($_action === null) {
                            $_action = 'index';
                        }
                        
                        $continue = false;
                        $_parametersLength = count($_parameters);
                        
                        try {
                            $reflectionMethod = new \ReflectionMethod($__controller, $_action);
                            
                            if (!$reflectionMethod->isPublic()) {
                                $continue = true;
                            }
                            
                            $reflectionParameters = array_filter($reflectionMethod->getParameters(), function ($reflectionParameter) {
                                return !$reflectionParameter->isOptional();
                            });
                            
                            if (count($reflectionParameters) > $_parametersLength) {
                                $continue = true;
                            }
                        } catch (\Exception $e) {
                            $continue = true;
                        }
                        
                        if ($continue) {
                            continue;
                        }
                        
                        $action = $_action;
                        $controller = $__controller;
                        $parameters = $_parameters;
                        
                        break;
                    }
                }
            } else {
                $controller = Application::getConfigValue('NAME') . '\\Controller\\HomeController';
                $action = 'index';
            }
        }
        
        return $route !== null || $controller !== null;
    }
}
