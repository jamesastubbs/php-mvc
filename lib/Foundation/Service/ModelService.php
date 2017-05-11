<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Exception\ServiceDependencyException;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Service\DBService;
use PHPMVC\Foundation\Services;
use PHPMVC\Foundation\Model\Model;
use PHPMVC\Foundation\Model\ModelProxy;
use PHPMVC\Foundation\Model\ModelQueryBuilder;

class ModelService implements ServiceInterface, ServiceableInterface
{
    /**
     * @var  Services
     */
    private $services = null;

    /**
     * {@inheritdoc}
     */
    public function onServiceStart()
    {
        $dbService = $this->services->get($this->services->getNameForServiceClass(DBService::class));

        if ($dbService === null) {
            throw new ServiceDependencyException(
                'Cannot instantiate \'' . self::class . '\' without the service of type \'' . DBService::class . '\''
            );
        }

        Model::setDBService($dbService);
        ModelQueryBuilder::setDBService($dbService);
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    public function getModel($modelClass)
    {
        return new ModelProxy($this->services->get('db'), $modelClass);
    }
}
