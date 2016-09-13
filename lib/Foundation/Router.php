<?php

namespace PHPMVC\Foundation;

class Router
{
    private $name = null;
    private $routes = [];
    private $baseRoutes = [];
    
    public function __construct($name, array $routes)
    {
        $this->name = $name;
        
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
                $routePath = preg_replace_callback('/\{([A-Za-z0-9_]+)(?:\:[ |](?:\'([^\'\\\]*(?:\\.[^\'\\\]*)*)\'))?\}/', function ($matches) use (&$parameters, &$routePathI, &$routePathReplacements) {
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
            
            $routeParameters = $route['parameters'];
            $routeParametersOrder = $routeParameters['__order'];
            $routeParametersCount = count($routeParametersOrder);
            
            for ($i = 0; $i < $routeParametersCount; $i++) {
                $routeParameterName = $routeParametersOrder[$i];
                $routeParameter = $routeParameters[$routeParameterName];
                
                // if the parameter is null,
                // add in the value from the received URL match.
                if ($routeParameter === null) {
                    $routeParameter = $urlMatches[$i][0];
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
                        
                        try {
                            $reflectionMethod = new \ReflectionMethod($__controller, $_action);
                            
                            if (!$reflectionMethod->isPublic()) {
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
                $controller = \PHPMVC\Foundation\Application::getConfigValue('NAME') . '\\Controller\\HomeController';
                $action = 'index';
            }
        }
        
        return $route !== null || $controller !== null;
    }
}
