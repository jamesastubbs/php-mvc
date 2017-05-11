<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Application;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

class HTTPService implements ServiceInterface, ServiceableInterface
{
    const METHOD_DELETE = 'DELETE';
    const METHOD_GET = 'GET';
    const METHOD_HEAD = 'HEAD';
    const METHOD_OPTIONS = 'OPTIONS';
    const METHOD_POST = 'POST';
    const METHOD_PUT = 'PUT';
    const METHOD_UNKNOWN = '_UNKNOWN';

    /**
     * @var  Services
     */
    private $services = null;

    /**
     * @return  string  Method name of the received HTTP request.
     */
    public function getRequestMethod()
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD']);
        $methodIsValid = in_array($method, [
            self::METHOD_DELETE,
            self::METHOD_GET,
            self::METHOD_HEAD,
            self::METHOD_OPTIONS,
            self::METHOD_POST,
            self::METHOD_PUT
        ]);

        if (!$methodIsValid) {
            $method = self::METHOD_UNKNOWN;
        }

        unset($methodIsValid);

        return $method;
    }

    public function getRequestBody()
    {
        $decodedBody = null;

        if ($this->getRequestMethod() === self::METHOD_GET) {
            return $_GET;
        }

        switch ($_SERVER['CONTENT_TYPE']) {
            case 'application/json':
                $decodedBody = json_decode(file_get_contents('php://input'), true);
                break;
            case 'application/x-www-form-urlencoded':
                $decodedBody = $_POST;
                break;
            default:
                break;
        }

        return $decodedBody;
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    protected function getBodyFromGET()
    {
        
    }
}
