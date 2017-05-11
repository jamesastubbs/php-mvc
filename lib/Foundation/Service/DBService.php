<?php

namespace PHPMVC\Foundation\Service;

use PHPMVC\Foundation\Exception\ConfigurationException;
use PHPMVC\Foundation\Interfaces\ServiceInterface;
use PHPMVC\Foundation\Interfaces\ServiceableInterface;
use PHPMVC\Foundation\Service\ConfigService;
use PHPMVC\Foundation\Service\DebugService;
use PHPMVC\Foundation\Services;

class DBService implements ServiceInterface, ServiceableInterface
{
    /**
     * @var  string  name of the database to use.
     */
    public $dbName = null;

    /**
     * @var  DebugService
     */
    protected $debugService = null;

    /**
     * @var  Services
     */
    protected $services = null;

    /**
     * @var  DBDriver  driver object used to communicate with the SQL server.
     */
    private $driver = null;

    /**
     * Calls the driver's method to return the connection object.
     * 
     * @return  mixed  driver's connection object.
     */
    public function getConnection()
    {
       return $this->driver->getConnection();
    }

    /**
     * {@inheritdoc}
     */
    public function onServiceStart()
    {
        $configService = $this->services->get(
            $this->services->getNameForServiceClass(ConfigService::class)
        );

        $config = $configService->get('db');
        $checkResult = $this->checkConfig($config);

        if ($checkResult !== true) {
            throw new ConfigurationException("Error initalising DBService - $checkResult.");
        }

        $this->dbName = $config['name'];
        $driver = ucfirst(strtolower($config['driver']));

        $dbDriverClass = "PHPMVC\\DB\\Driver\\{$driver}Driver";

        if (!class_exists($dbDriverClass)) {
            throw new ConfigurationException("Error initalising DBService - Unsupported DBDriver class: '$dbDriverClass'");
        }

        $this->driver = new $dbDriverClass($config);

        if ($configService->get('app.debug') === true) {
            $this->debugService = $this->services->get(
                $this->services->getNameForServiceClass(DebugService::class)
            );
        }
    }

    /**
     * Calls '$transactionsFunc' to process SQL queries in a middle of an SQL transaction.
     * If the transaction hasn't already been initiated, it is started at this point.
     *
     * @param   callable  $transactionsFunc  Function to process SQL queries.
     *
     * @return  DB                           Current DB object used for method chaining.
     */
    public function queue($transactionsFunc)
    {
        $this->driver->queue($transactionsFunc);

        return $this;
    }

    /**
     * Processes the SQL transaction.
     * If the transaction hasn't already been initiated, nothing happens.
     *
     * @return  DB  Current DB object used for method chaining.
     */
    public function process()
    {
        $this->driver->process();

        return $this;
    }

    /**
     * Executes SQL query.
     * 
     * @param   string  $statement  the SQL statement.
     * @param   mixed   ...         optional objects which are bound to the prepared statement.
     * 
     * @return  mixed               result from the SQL query.
     */
    public function query($statement)
    {
        $arguments = func_get_args();
        $values = [];

        if (count($arguments) > 1) {
            $values = array_slice($arguments, 1);
        }

        return $this->executeQuery($statement, $values);
    }

    public function queryWithArray($statement, array $values = [])
    {
        return $this->executeQuery($statement, $values);
    }

    public function setServices(Services $services)
    {
        $this->services = $services;
    }

    protected function checkConfig($config)
    {
        if ($config === null) {
            return 'The configuration \'db\' is not set.';
        }

        if (!is_array($config)) {
            return 'The configuration \'db\' is not an array.';
        }

        if (!isset($config['driver'])) {
            return 'The configuration \'db.driver\' is not set.';
        }

        if (!isset($config['host'])) {
            return 'The configuration \'db.host\' is not set.';
        }

        if (!isset($config['name'])) {
            return 'The configuration \'db.name\' is not set.';
        }

        if (!isset($config['username'])) {
            return 'The configuration \'db.username\' is not set.';
        }

        if (!isset($config['password'])) {
            return 'The configuration \'db.password\' is not set.';
        }

        return true;
    }

    protected function executeQuery($statement, array $parameters)
    {
        $error = false;
        $result = null;
        $start = 0;
        $finish = 0;

        try {
            $start = microtime(true);
            $result = $this->driver->executeSQL($statement, $parameters);
            $finish = microtime(true);
        } catch (\PDOException $e) {
            $finish = microtime(true);
            $error = true;
            throw $e;
        } finally {
            if ($this->debugService !== null) {
                $this->debugService->collect('DBService', [
                    'query' => $this->driver->getFullSQL($statement, $parameters),
                    'status' => $error ? 'failure' : 'success',
                    'time' => ($finish - $start) . ' seconds',
                    'values' => $parameters
                ]);
            }
        }

        return $result;
    }
}
