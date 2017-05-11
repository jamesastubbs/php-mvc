<?php

namespace PHPMVC\Foundation;

use PHPMVC\Foundation\Exception\ConfigurationException;
use PHPMVC\Foundation\Exception\ServiceDependencyException;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Service\ConfigService;

/**
 * Class  Services
 * A container and management object for services.
 *
 * @package  PHPMVC\Foundation
 */
class Services
{
    /**
     * @var  ServiceInterface[]
     */
    private $services = [];

    /**
     * @param   string   $name              Name of the service to return.
     * @param   boolean  $isRequire         'true' if the desired service is required.
     *
     * @return  Service                     The service instance with the name of '$name'. 'null' if it does not exist.
     * @throws  ServiceDependencyException  Thrown if '$isRequired' is 'true' and desired service cannot be found.
     */
    public function get($name, $isRequired = false)
    {
        if (!$this->has($name)) {
            if ($isRequired) {
                throw new ServiceDependencyException(
                    "The required service '$name' was not registered."
                );
            }

            return null;
        }

        $service = $this->services[$name];

        if (is_string($service)) {
            // the string value is the class name of the service.
            // means it has not been used and therefore needs instantiating.
            $service = new $service();

            if ($service instanceof ServiceableInterface) {
                $service->setServices($this);
            }

            // call this after setting the services,
            // as the service setup may depend on this happening before.
            if (method_exists($service, 'onServiceStart')) {
                $service->onServiceStart();
            }

            $this->services[$name] = $service;
        }

        return $service;
    }

    /**
     * Searches through registered services to find the one with the class name of '$class'.
     * The name of the found service that it has been registered with is returned.
     *
     * @param   string  $class  Class name of service to fetch name of.
     *
     * @return  string          Name of the found service, 'null' if not found.
     */
    public function getNameForServiceClass($class)
    {
        $name = null;

        foreach ($this->services as $serviceName => $serviceOrClass) {
            if (!is_string($serviceOrClass)) {
                $serviceOrClass = get_class($serviceOrClass);
            }

            if ($class === $serviceOrClass) {
                $name = $serviceName;
                break;
            }
        }

        return $name;
    }

    /**
     * @param   string  $name  Name of the service to check.
     *
     * @return  boolean        'true' if the service exists under the name of '$name'.
     */
    public function has($name)
    {
        return isset($this->services[$name]);
    }

    public function import($namespace)
    {
        $fileName = 'services';
        $namespaces = $this->get('app.config', true)->get('app.loader')->getPrefixesPsr4();
        $namespace = $namespace;

        if (preg_match('/([A-Za-z0-9_-]+)\:([A-Za-z0-9_-]+)/', $namespace, $matches) === 1) {
            $namespace = $matches[1];
            $fileName = $matches[2];
        }

        if (!isset($namespaces[$namespace . '\\'])) {
            throw new \Exception("Attempted to import services for the namespace '$namespace' which does not exist.");
        }

        $servicesConfigPath = dirname($namespaces[$namespace . '\\'][0]) . "/config/$fileName.php";

        if (!file_exists($servicesConfigPath)) {
            throw new ConfigurationException("The services configuration file '$servicesConfigPath' could not be found.");
        }

        $services = $this;

        $internalImport = function ($incudePath, $services) {
            include $incudePath;
        };
        $internalImport($servicesConfigPath, $this);
    }

    /**
     * @param   string   $name              Name of the service to register.
     * @param   string   $class             Class name of the service to register.
     * @param   boolean  $startImmediately  'true' to instantiate newly registered service.
     *
     * @throws  ReflectionException         If '$class' is not a defined class.
     */
    public function register($name, $class, $startImmediately = false)
    {
        $reflection = new \ReflectionClass($class);

        if (!$reflection->implementsInterface(ServiceInterface::class)) {
            throw new \Exception('Registering service must implement the interface \'' . ServiceInterface::class . '\'.');
        }

        // do not immediately create new instance of service.
        // just store the class ready for to be instantiated later when needed.
        $this->services[$name] = $class;

        if ($startImmediately) {
            $this->get($name);
        }
    }
}
