<?php

use PHPMVC\Foundation\Service\ConfigService;
use PHPUnit\Framework\TestCase;

class ConfigServiceTest extends TestCase
{
    /**
     * @var  Application
     */
    private $app = null;

    /**
     * @var  ConfigService
     */
    private $configService = null;

    protected function setUp()
    {
        $this->configService = new ConfigService();
    }

    public function testService()
    {
        $this->configService->set('astring', 'This should be a string');
        $this->assertEquals($this->configService->get('astring'), 'This should be a string');
        $this->assertNull($this->configService->get('doesntexist'));

        $anArray = [
            'property1' => 1,
            'value2' => '2',
            'array3' => [
                'anotherproperty' => true
            ]
        ];

        $this->configService->set('anarray', $anArray);
        $this->assertEquals($this->configService->get('anarray'), $anArray);
        $this->assertEquals($this->configService->get('anarray.property1'), $anArray['property1']);
        $this->assertEquals($this->configService->get('anarray.value2'), $anArray['value2']);
        $this->assertEquals($this->configService->get('anarray.array3'), $anArray['array3']);
        $this->assertEquals($this->configService->get('anarray.array3.anotherproperty'), $anArray['array3']['anotherproperty']);
    }

    protected function tearDown()
    {
        unset($this->app);
    }
}
