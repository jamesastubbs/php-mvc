<?php

use org\bovigo\vfs\vfsStream;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Service\LoggerService;
use PHPMVC\Foundation\Services;
use Psr\Log\Test\LoggerInterfaceTest;

class LoggerServiceTest extends LoggerInterfaceTest
{
    /**
     * @var  LoggerService
     */
    protected $loggerService = null;

    protected $rootVFS = null;

    public function getLogger()
    {
        if ($this->loggerService === null) {
            $this->rootVFS = vfsStream::setup();
            vfsStream::newFile('test.log')->at($this->rootVFS)->setContent('');

            $services = new Services();
            $services->register('app.config', ConfigService::class);
            $services->get('app.config')->set('app', ['root' => $this->rootVFS->url()]);

            $services->register('app.logger', loggerService::class);
            $this->loggerService = $services->get('app.logger');
            $this->loggerService->setLogFile('test.log');
        }

        return $this->loggerService;
    }

    /**
     * This must return the log messages in order.
     *
     * The simple formatting of the messages is: "<LOG LEVEL> <MESSAGE>".
     *
     * Example ->error('Foo') would yield "error Foo".
     *
     * @return string[]
     */
    public function getLogs()
    {
        return file($this->getLogger()->getFullLogFile(), FILE_IGNORE_NEW_LINES);
    }

    protected function tearDown()
    {
        unset($this->loggerService);
    }
}
