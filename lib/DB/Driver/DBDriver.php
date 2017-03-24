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
    protected $inDebug = false;

    /**
     * @abstract
     * Initalises driver object and creates connection using the definitions in the ../config/config.php file.
     * @param   Array   Configuration object containing values to set up the database connection with.
     */
    public function __construct($config)
    {
        if (!isset($config->DB_USER)) {
            throw new \Exception('DB username not set in config.');
        }

        if (!isset($config->DB_PASS)) {
            throw new \Exception('DB password not set in config.');
        }

        if (!isset($config->DB_HOST)) {
            throw new \Exception('DB host not set in config.');
        }

        if (!isset($config->DB_NAME)) {
            throw new \Exception('DB name not set in config.');
        }

        $this->connection = new \PDO(
            $this->getDSNFromConfig($config),
            $config->DB_USER,
            $config->DB_PASS,
            [
                \PDO::ATTR_EMULATE_PREPARES => false,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION
            ]
        );

        $this->inDebug = Application::getConfigValue('DEBUG');
    }

    abstract protected function getDSNFromConfig($config);

    public function query($statement, array $values = null)
    {
        $result = null;
        $error = true;

        try {
            $result = $this->executeSQL($statement, $values);
            $error = false;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            if ($this->inDebug) {
                $this->logQuery($this->getFullSQL($statement, $values), $error ? 'ERROR' : 'DEBUG');
            }
        }

        return $result;
    }

    public function getConnection()
    {
        return $this->connection;
    }

    protected function executeSQL($statement, array $values = null)
    {
        $params = [];

        if ($values !== null && !empty($values)) {
            $i = 0;

            $statement = preg_replace_callback('/(?<!\\\)(?:\\\\)*\?/', function ($matches) use ($values, &$i, &$params) {
                $key = ":p_$i";
                $value = $values[$i++];

                $params[$key] = $value;

                return $key;
            }, $statement);
        }

        $query = $this->connection->prepare($statement);

        foreach ($params as $key => $value) {
            $query->bindValue($key, $value);
        }

        if (!$query->execute()) {
            return false;
        }

        $result = null;

        switch (explode(' ', trim($statement))[0]) {
            case 'SELECT':
                $result = $query->fetchAll(\PDO::FETCH_ASSOC);
                break;
            case 'INSERT':
                $result = $this->connection->lastInsertId();
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

    private function getFullSQL($statement, array $values = null)
    {
        $sql = $statement;

        if ($values !== null && !empty($values)) {
            $i = 0;

            $sql = preg_replace_callback('/(?<!\\\)(?:\\\\)*\?/', function ($matches) use ($values, &$i) {
                $value = $values[$i++];

                if (is_string($value)) {
                    $value = "'$value'";
                }

                return strval($value);
            }, $sql);
        }

        return $sql;
    }

    protected function logQuery($query, $prefix = 'DEBUG')
    {
        $logDir = Application::getConfigValue('ROOT') . '/log';
        $logPath = "$logDir/db.log";

        if (!file_exists($logDir)) {
            mkdir($logDir);
        }

        if (file_exists($logPath)) {
            touch($logPath);
        }

        file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '][' . ($prefix) . '] ' . $query . PHP_EOL, FILE_APPEND);
    }

    public function __deconstruct()
    {
        $this->connection = null;
    }
}
