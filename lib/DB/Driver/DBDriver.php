<?php

namespace PHPMVC\DB\Driver;

use \PHPMVC\Foundation\Application;

abstract class DBDriver
{
    /**
     * @var  mixed  connection object used to communicate with the SQL server.
     */
    protected $connection = null;

    /**
     * @var  boolean
     */
    protected $transactionStarted = false;

    /**
     * @abstract
     * Initalises driver object and creates connection using the definitions in the ../config/config.php file.
     * @param   Array   Configuration object containing values to set up the database connection with.
     */
    public function __construct($config)
    {
        $this->connection = new \PDO(
            $this->getDSNFromConfig($config),
            $config['username'],
            $config['password'],
            [
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]
        );
    }

    abstract protected function getDSNFromConfig($config);

    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * Calls '$transactionsFunc' to process SQL queries in a middle of an SQL transaction.
     * If the transaction hasn't already been initiated, it is started at this point.
     *
     * @param   callable  $transactionsFunc  Function to process SQL queries.
     *
     * @return  DBDriver                    Current DBDriver object for used method chaining.
     */
    public function queue($transactionsFunc)
    {
        if (!$this->transactionStarted) {
            $this->connection->beginTransaction();

            $this->transactionStarted = true;
        }

        $transactionsFunc();

        return $this;
    }

    /**
     * Processes the SQL transaction.
     * If the transaction hasn't already been initiated, nothing happens.
     *
     * @return  DBDriver  Current DBDriver object used for method chaining.
     */
    public function process()
    {
        if ($this->transactionStarted) {
            $this->connection->commit();

            $this->transactionStarted = false;
        }

        return $this;
    }

    public function executeSQL($statement, array $values = null)
    {
        $params = [];

        if ($values !== null && !empty($values)) {
            $index = 0;
            $keyIndex = 0;

            $statement = preg_replace_callback('/(?<!\\\\)\?/', function ($matches) use (&$values, &$index, &$keyIndex, &$params) {
                $firstKey = true;
                $keyStr = '';
                $valueObjs = $values[$index];

                unset($values[$index]);

                $index++;

                if (!is_array($valueObjs)) {
                    $valueObjs = [$valueObjs];
                }

                foreach ($valueObjs as $value) {
                    if ($firstKey) {
                        $firstKey = false;
                    } else {
                        $keyStr .= ', ';
                    }

                    $key = ":p_$keyIndex";
                    $params[$key] = $value;

                    $keyStr .= $key;

                    $keyIndex++;
                }

                return $keyStr;
            }, $statement);

            if (!empty($values)) {
                $params = array_merge($params, $values);
            }
        }

        $query = $this->connection->prepare($statement);

        foreach ($params as $key => $value) {
            $query->bindValue($key, $value);
        }

        $values = $params;

        $query->execute();
        $result = null;

        switch (explode(' ', trim($statement))[0]) {
            case 'SELECT':
                $result = $query->fetchAll(\PDO::FETCH_ASSOC);
                break;
            case 'INSERT':
                $result = $this->connection->lastInsertId();

                if ($result === '0') {
                    $result = true;
                }
                break;
            case 'UPDATE':
                // no break.
            case 'DELETE':
                $result = $query->rowCount() !== 0;
                break;
            default:
                break;
        }

        return $result;
    }

    public function getFullSQL($statement, array $values = null)
    {
        $sql = $statement;

        if ($values !== null && !empty($values)) {
            $sql = preg_replace_callback('/\:p_[0-9]+/', function ($matches) use ($values) {
                $value = $values[$matches[0]];

                if (is_string($value)) {
                    $value = "'$value'";
                }

                return strval($value);
            }, $sql);
        }

        return $sql;
    }

    public function __deconstruct()
    {
        unset($this->connection);
    }
}
