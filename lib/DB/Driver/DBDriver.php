<?php

namespace PHPMVC\DB\Driver;

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
     * @param   Array   Configuration object containing values to set up the database connection with.
	 */
	abstract function __construct($config);
	
	/**
	 * @abstract
	 * Prepares and executes an SQL query.
	 */
	abstract function query($statement, $values);
	
	public function getConnection()
	{
		return $this->connection;
	}
	    
	public function __deconstruct()
	{
		$this->connection = null;
	}
}