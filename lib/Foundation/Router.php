<?php

namespace PHPMVC\Foundation;

class Router
{
    private $name = null;
    private $routes = [];
    private $baseRoutes = [];
    
    public function __construct($name, $routes)
    {
        $this->name = $name;
        
        if (is_array($routes)) {
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
                
                // start stripping apart route definition.
                $routeParts = explode('::', $route);
                $actionParts = explode('(', $routeParts[1]);
                
                // sanitise action string.
                $actionPartsCount = count($actionParts);
                if ($actionPartsCount > 1) {
                    $actionPartsCount--;
                }
                
                $lastActionPart = $actionParts[$actionPartsCount];
                $lastActionPart = rtrim($lastActionPart, ')');
                $actionParts[$actionPartsCount] = $lastActionPart;
                
                preg_match_all('/(?:^|, ?)([A-Za-z0-9_]+)/', $lastActionPart, $parameters);
                $parameters = array_fill_keys($parameters[1], null);
                $parameters['__order'] = [];
                
                // start building the route URL.
                $routeURL = '/^';
                
                // retrieve all route parts, so that we can build continue to build the route URL.
                preg_match_all('/\/[A-Za-z0-9_]+|\/\{[A-Za-z0-9_]+(?:(?:\:[ |]\'[\s\S]+\')|)\}/', $routePath, $routePathMatches);
                $routePathMatches = $routePathMatches[0];
                
                foreach ($routePathMatches as $routePathMatch) {
                    if (strpos($routePathMatch, '{') !== false) {
                        // is a parameter.
                        preg_match_all('/^\/\{([A-Za-z0-9_]+)(?:\:[ |]\'([\s\S]+)\'|)\}$/', $routePathMatch, $parts);
                        array_shift($parts);
                        
                        $parameterName = $parts[0][0];
                        $parameterRegex = $parts[1][0] !== '' ? $parts[1][0] : '([A-Za-z0-9_]+)';
                        
                        if (!array_key_exists($parameterName, $parameters)) {
                            throw new \Exception('Invalid route.');
                        }
                        
                        $parameters[$parameterName] = $parameterRegex;
                        array_push($parameters['__order'], $parameterName);
                        $routeURL .= '\/' . $parameterRegex;
                    } else {
                        // is a static part of the URL.
                        $routeURL .= '\\' . $routePathMatch;
                    }
                }
                
                // append GET parameter regex.
                $routeURL .= '(?:(?:\?\S[^ \?]+)|)$/';
                
                $this->routes[$routeURL] = [
                    'controller' => $routeParts[0],
                    'action' => $actionParts[0],
                    'parameters' => $parameters
                ];
            }
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
                $route = $this->routes[$routeKey];
                array_shift($urlMatches);
                break;
            }
        }
        
        if ($route !== null) {
            $controller = $route['controller'];
            $action = $route['action'];
            $routeParameters = $route['parameters'];
            $routeParametersOrder = $routeParameters['__order'];
            $routeParametersCount = count($routeParametersOrder);
            
            // only proceed if number of parameters in URL match route.
            if (count($urlMatches) === $routeParametersCount) {
                $parameters = [];
                
                for ($i = 0; $i < $routeParametersCount; $i++) {
                    $routeParameterName = $routeParametersOrder[$i];
                    $urlMatch = $urlMatches[$i][0];
                    $routeParameter = $routeParameters[$routeParameterName];
                    
                    if (preg_match("/$routeParameter/", $urlMatch)) {
                        array_push($parameters, $urlMatch);
                    }
                }
                
                if ($routeParametersCount !== count($parameters)) {
                    // we set this as null so that we proceed to continue to search for the called controller.
                    $route = null;
                }
            }
        }
        
        // search for existing controller as last resort.
        if ($route === null) {
            $baseRoutes = array_merge([$this->name], $this->baseRoutes);
            
            foreach ($baseRoutes as $baseRoute) {
                $controller = (isset($urlParts[0]) && $urlParts[0] !== '') ? $urlParts[0] : null;
                $action = isset($urlParts[1]) ? $urlParts[1] : null;
                
                $urlPartsCount = count($urlParts);
                $parameters = [];
                
                for($i = 2; $i < $urlPartsCount; $i++) {
                    array_push($parameters, $urlParts[$i]);
                }
                
                if ($controller === null) {
                    $controller = 'HomeController';
                } else {
                    $controller = ucfirst($controller) . 'Controller';
                }
                
                if ($action === null) {
                    $action = 'index';
                }
                
                // setup controler class name so it is relative to the namespace.
                $controller = $baseRoute . "\\Controller\\$controller";
                
                if (class_exists($controller)) {
                    break;
                }
            }
        }
    }
}