<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\DB;

class DB
{
    /**
     * @var  DBDriver  driver object used to communicate with the SQL server.
     */
    private $driver = null;

    /**
     * @var  string  name of the database to use.
     */
    public $dbName = null;
    
    /**
     * Initalises new sub class instance of DBDriver.
     * The sub class is defined within the ../config/config.php file under DB_DRIVER.
     */
    public function __construct($config)
    {
        $this->dbName = $config->DB_NAME;
        $dbDriverClass = "PHPMVC\\DB\\Driver\\{$config->DB_DRIVER}Driver";

        if (!class_exists($dbDriverClass)) {
            throw new \Exception("Unsupported DB Driver '$dbDriverClass'");
        }

        $this->driver = new $dbDriverClass($config);
    }

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

        return $this->driver->query($statement, $values);
    }

    public function queryWithArray($statement, array $values = null)
    {
        if ($values === null) {
            $values = [];
        }

        return call_user_func_array([$this, 'query'], array_merge([$statement], $values));
    }

    public function __deconstruct()
    {
        $this->dbName = null;
        $this->driver = null;
    }
}
