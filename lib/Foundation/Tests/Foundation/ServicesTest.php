<?php

use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Services;
use PHPUnit\Framework\TestCase;

class ServicesTest extends TestCase
{
    /**
     * @var  Services
     */
    private $services = null;

    protected function setUp()
    {
        $this->services = new Services();
    }

    public function testServices()
    {
        $service = $this->createMock(ServiceInterface::class);
        $this->services->register('test', $service);

        $this->assertTrue($this->services->has('test'));
        $this->assertNotTrue($this->services->has('noservice'));
        $this->assertEquals($this->services->get('test'), $service);
        $this->assertEquals($this->services->getNameForServiceClass(get_class($service)), 'test');
    }

    protected function tearDown()
    {
        unset($this->services);
    }
}
