<?php

use PHPMVC\Foundation\Application;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Services;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    protected function setUp()
    {
        $this->app = $this
            ->getMockBuilder(Application::class)
            ->disableOriginalConstructor()
            ->disableOriginalClone()
            ->disableArgumentCloning()
            ->disallowMockingUnknownTypes()
            ->getMock();

        $configService = new ConfigService();

        $services = $this
            ->createMock(Services::class);

        $services
            ->method('get')
            ->with('app.config')
            ->will($this->returnValue($configService));

        $this->app
            ->method('getServices')
            ->will($this->returnValue($services));
    }

    public function testWhitelist()
    {
        $this->app->getServices()->get('app.config')->set('app.debug', true);
    }

    protected function tearDown()
    {
        unset($this->app);
    }
}
