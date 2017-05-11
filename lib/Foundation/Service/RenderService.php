<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\HTTP\Response;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

class RenderService implements ServiceInterface, ServiceableInterface
{
    /**
     * @var  Services
     */
    protected $services;

    public function render(Response $response)
    {
        header($response->getStatusHeader());

        foreach ($response->getHeaders() as $name => $value) {
            header("{$name}: {$value}");

            if ($name === 'Location') {
                $renderBody = false;
            }
        }

        echo $response->getBody();
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }
}
