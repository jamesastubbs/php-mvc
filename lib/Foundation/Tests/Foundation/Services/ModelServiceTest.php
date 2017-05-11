<?php

use PHPMVC\Foundation\Service\DBService;
use PHPMVC\Foundation\Service\ModelService;
use PHPMVC\Foundation\Services;
use PHPUnit\Framework\TestCase;

class ModelServiceTest extends TestCase
{
    private $modelService = null;

    protected function setUp()
    {
        $this->modelService = new ModelService();
    }

    protected function createMockServicesObject()
    {
        $services = $this->createMock(Services::class);
        $services
            ->expects($this->once())
            ->method('getNameForServiceClass')
            ->with(DBService::class)
            ->will($this->returnValue('db'));

        $services
            ->method('get')
            ->with('db')
            ->will($this->returnValue(new DBService()));

        return $services;
    }

    public function testService()
    {
        $services = $this->createMockServicesObject();
        $this->modelService->setServices($services);
        $this->modelService->onServiceStart();
    }

    protected function tearDown()
    {
        unset($this->modelService);
    }
}
