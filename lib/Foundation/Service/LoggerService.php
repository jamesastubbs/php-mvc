<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Services;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;

class LoggerService extends AbstractLogger implements ServiceInterface, ServiceableInterface
{
    protected $logFile = null;

    /**
     * @var  string
     */
    protected $rootDir = null;

    /**
     * @var  Services
     */
    protected $services = null;

    public function __construct()
    {
        $this->setLogFile('var/log/app.log');
    }

    /**
     * {inheritdoc}
     */
    public function log($level, $message, array $context = array())
    {
        if (
            !in_array($level, [
                LogLevel::ALERT,
                LogLevel::CRITICAL,
                LogLevel::DEBUG,
                LogLevel::EMERGENCY,
                LogLevel::ERROR,
                LogLevel::INFO,
                LogLevel::NOTICE,
                LogLevel::WARNING
            ])
        ) {
            throw new InvalidArgumentException("Invalid log level '$level'.");
        }

        // $message = '[' . date('Y-m-d H:i:s') . '] {core}.{level} ' . $message;
        $message = "{level} $message";
        
        $context['level'] = $level;

        if (!isset($context['core'])) {
            $context['core'] = 'php';
        }

        // build a replacement array with braces around the context keys
        $replace = array();

        foreach ($context as $key => $val) {
            // check that the value can be casted to string
            if (!is_array($val) && (!is_object($val) || method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        // interpolate replacement values into the message and return
        $message = strtr($message, $replace);

        $fullFilePath = $this->getFullLogFile();

        // create the log file and directories if they do not exist.
        if (!file_exists($fullFilePath)) {
            $logDir = dirname($fullFilePath);

            if (!file_exists($logDir)) {
                $varDir = dirname($logDir);

                if (!file_exists($varDir)) {
                    mkdir($varDir);
                }

                mkdir($logDir);
            }
        
            touch($fullFilePath);
        }

        file_put_contents(
            $fullFilePath,
            $message . PHP_EOL,
            FILE_APPEND
        );
    }

    public function getFullLogFile()
    {
        return "{$this->rootDir}/{$this->logFile}";
    }

    public function setLogFile($logFile)
    {
        $this->logFile = $logFile;
    }

    /**
     * Sets the service container.
     *
     * @param  Services  $services
     */
    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    /**
     * Called when the Services container has started this service for the first time.
     */
    public function onServiceStart()
    {
        $this->rootDir = $this
            ->services
            ->get('app.config', true)
            ->get('app.root');
    }
}
