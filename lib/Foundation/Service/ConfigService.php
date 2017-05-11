<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;

class ConfigService implements ServiceInterface
{
    /**
     * @var  array
     */
    protected $config = [];

    /**
     * @var  Services
     */
    protected $services = null;

    public function get($name)
    {
        $names = explode('.', $name);
        $value = $this->config;

        foreach ($names as $name) {
            $value = isset($value[$name]) ? $value[$name] : null;

            if (!is_array($value)) {
                break;
            }
        }

        return $value;
    }

    public function set($name, $value)
    {
        $this->config[$name] = $value;
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }
}
