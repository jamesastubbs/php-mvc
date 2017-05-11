<?php

use PHPMVC\Foundation\Exception\ConfigurationException;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Service\DBService;
use PHPMVC\Foundation\Services;
use PHPUnit\Framework\TestCase;

class DBServiceTest extends TestCase
{
    public function testServiceInitialisation()
    {
        $configService = new ConfigService();

        $services = $this->createMock(Services::class);
        $services
            ->method('getNameForServiceClass')
            ->with(ConfigService::class)
            ->will($this->returnValue('config'));

        $services
            ->method('get')
            ->with('config')
            ->will($this->returnValue($configService));

        $properties = [
            'driver',
            'host',
            'name',
            'username',
            'password'
        ];
        $propertiesCount = count($properties);
        $dbConfig = [];

        for ($i = 0; $i < $propertiesCount; $i++) {
            $property = $properties[$i];
            $dbConfig[$property] = 'mysql';
            $exception = null;

            $configService->set('db', $dbConfig);

            try {
                $dbService = new DBService();
                $dbService->setServices($services);
                $dbService->onServiceStart();
            } catch (\Exception $e) {
                $exception = $e;
            }

            if ($i === ($propertiesCount - 1)) {
                $this->assertEquals(get_class($exception), PDOException::class);
            } else {
                $this->assertEquals(get_class($exception), ConfigurationException::class);
            }
        }
    }
}
