<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

class DB
{
	/**
	 * @var DBDriver	driver object used to communicate with the SQL server.
	 */
	private $driver = null;
	
	/**
	 * Initalises new sub class instance of DBDriver. The sub class is defined within the ../config/config.php file under DB_DRIVER.
	 */
	public function __construct()
	{
		$dbDriverClass = strtoupper(DB_DRIVER) . "DBDriver";
		if (!class_exists($dbDriverClass))
			die("Unsupported MySQL server");
		
		$this->driver = new $dbDriverClass; 
	}
	
	/**
	 * Calls the driver's method to return the connection object.
	 * 
	 * @return mixed	driver's connection object.
	 */
	public function getConnection()
	{
		return $this->driver->getConnection();
	}
	
	/**
	 * Executes SQL query.
	 * 
	 * @param string 	$statement	the SQL statement.
	 * @param mixed 	...			optional objects which are bound to the prepared statement.
	 * 
	 * @return mixed				result from the SQL query.
	 */
	public function query($statement)
	{
		$values = array_slice(func_get_args(), 1);
		if (empty($values) || (!empty($values) && $values[0] == null))
			$values = null;
		else if (gettype($values[0]) == "array" && count($values) == 1)
			$values = $values[0];
		
		return $this->driver->query($statement, $values);
	}
	
	public function __deconstruct()
	{
		$this->driver = null;
	}
}

/**
 * @abstract
 */
abstract class DBDriver
{
	/**
	 * @var mixed	connection object used to communicate with the SQL server.
	 */
	protected $connection = null;
	
	/**
	 * @abstract
	 * Initalises driver object and creates connection using the definitions in the ../config/config.php file.
	 */
	abstract function __construct();
	
	/**
	 * @abstract
	 * Prepares and executes an SQL query.
	 */
	abstract function query($statement, $values);
	
	/**
	 * @return	
	 */
	public function getConnection()
	{
		return $this->connection;
	}
		
	public function __deconstruct()
	{
		$this->connection = null;
	}
}