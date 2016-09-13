<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

namespace PHPMVC\DB\Driver;

use PHPMVC\DB\Driver\DBDriver;
use PHPMVC\Foundation\Application;

class MYSQLIDBDriver extends DBDriver
{
	public function __construct($config)
	{
		@$this->setupConnection($config);
        
        if ($this->connection->connect_error !== null) {
            throw new \Exception('MySQLi error: ' . mysqli_connect_error());
        }
	}
    
    private function setupConnection($config)
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        
        $this->connection = new \mysqli($config->DB_HOST, $config->DB_USER, $config->DB_PASS, $config->DB_NAME); 
        
        if ($this->connection->connect_error === null) {
            mysqli_set_charset($this->connection, $config->DB_CHARSET);
        }
    }

	public function getConnection()
	{
		return $this->connection;
	}

	public function query($statement, $values)
	{        
		$query = $this->connection->prepare($statement);

		if (!$query) {
            Application::log("MySQLi error: " . $this->connection->error . " - query: " . $this->debugQuery($statement, $values));
			
            /*
			if (filter_var(Application::getConfigValue('DEBUG'), FILTER_VALIDATE_BOOLEAN)) {
				$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				$backtraceStr = "";
				
				if (count($backtrace) > 1)
					$backtraceStr = $backtrace[1]['file'] . " on line " . $backtrace[1]['line'];
				
				die("<strong>MySQLi Error:</strong> " . $this->connection->error . "<br />" . PHP_EOL . "<br />" . PHP_EOL . "<strong>Backtrace</strong>: $backtraceStr<br />" . PHP_EOL . "<strong>Query:</strong> " . $this->debugQuery($statement, $values));
			}
            */
			
            throw new \Exception('Query error.');
		}
		
		//die(var_dump($values));
		
		$bindTypes = "";
		
		if (isset($values) && count($values)) {
			$bindTypes = "";
			
			$_values = array();
			
			for ($i = 0; $i < count($values); $i++) {
				$value = $values[$i];
				$type = "b";
				$valueType = gettype($value);
				
                if ($valueType == "string") {
					$type = "s";
				} else if ($valueType == "integer" || $valueType == "boolean") {
					$type = "i";
				} else if ($valueType == "double") {
					$type = "d";
                }
                
                $bindTypes .= $type;
                $_values[$i] = &$values[$i];
			}
            
			call_user_func_array(array($query, "bind_param"), array_merge(array($bindTypes), $_values));
		}
		
		if (!$query->execute()) {
			Application::log("MySQLi error: " . $query->error . " - query: " . $this->debugQuery($statement, $values), 2);
			
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN)) {
				$backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
				$backtraceStr = "";
				
				if (count($backtrace) > 1)
					$backtraceStr = $backtrace[1]['file'] . " on line " . $backtrace[1]['line'];
				
				die("<strong>MySQLi Error:</strong> " . $query->error . "<br />" . PHP_EOL . "<br />" . PHP_EOL . "<strong>Backtrace</strong>: $backtraceStr<br />" . PHP_EOL . "<strong>Query:</strong> " . $this->debugQuery($statement, $values));
			}
			
			return false;
		}
		
		$result = null;
		
		if ($query->error) {			
			Application::log("MySQLi error: " . $query->error . " - query: " . $this->debugQuery($statement, $values), 2);
			
			if (filter_var(DEBUG, FILTER_VALIDATE_BOOLEAN)) {
				throw new \Exception('MySQLi Error: ' . $query->error . ' | Query: '. $this->debugQuery($statement, $values));
			}
			
			return false;
		}
		
		switch (explode(" ", trim($statement))[0]) {
			case "SELECT": {
				$result = array();
				$queryResult = $query->get_result();
				while ($row = $queryResult->fetch_array(MYSQLI_ASSOC)) {
					array_push($result, $row);
				}
			}
			break;
			case "INSERT":
				$result = $this->connection->insert_id;
				break;
			case "UPDATE":
			case "DELETE":
				$result = ($query->affected_rows > 0);
				//die($this->debugQuery($statement, $values));
				break;
			default:
				$result = $query;
				break;
		}

		$query->close();
		
		return $result;
	}

	private function debugQuery($statement, $values)
	{
		for ($i = 0; $i < count($values); $i++) {
			$statement = preg_replace('/\?/', $values[$i], $statement, 1);
			if (!strlen($values[$i]))
				return "'" . $values[$i - 1] . "";
		}
		return $statement;
	}

	public function __deconstruct()
	{
		mysqli_close($this->connection);

		$this->connection = null;
	}
}
