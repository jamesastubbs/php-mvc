<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

class PDODBDriver extends DBDriver
{
	public function __construct()
	{
		$this->connection = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET, DB_USER, DB_PASS, array(PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION));
	}

	public function query($statement, $values)
	{
		$query = $this->connection->prepare($statement);

		if (!$query) {
			//throw new Exception("Mysqli Error: " . $query->error . " - query: " . $this->debugQuery($statement, $values));
			//die("Mysqli Error: <br /><strong>" . $this->connection->error . "</strong><br /> - " . $this->debugQuery($statement, $values));
		}

		if (count($values)) {
			$bindTypes = array();
			$tempNumber = 1;
			$preparedStatement = "";
			$i = 0;
			while ($i < strlen($statement)) {
				if ($statement[$i] == "?") {
					//echo $i . "<br />";
					$numberWord = convertNumber($tempNumber);
					while (true) {
						if (preg_match("/(" . $numberWord . ")/", $statement)) {
							$tempNumber++;
							continue;
						}
						$bindType = ":" . $numberWord;
						$preparedStatement .= $bindType;
						array_push($bindTypes, $bindType);
						//echo "added $bindType - " . var_export($bindTypes) . "<br />";
						$tempNumber++;
						break;
					}
				} else {
					$preparedStatement .= $statement[$i];
				}
				$i++;
			}
			//echo (var_export($bindTypes) . " count:" . count($bindTypes)) . "<br />";
			//preg_match('/\:[A-Za-z0-9]+/', $preparedStatement, $bindTypes, 0);
			//die(var_export($bindTypes) . " count:" . count($bindTypes));
			$query = null;
			$query = $this->connection->prepare($preparedStatement);
			
			for ($i = 0; $i < count($bindTypes); $i++) {
				
				try {
					
				$query->bindParam($bindTypes[$i], $values[$i]);
				
				} catch (Exception $e) {
					die(var_export($bindTypes) . "<br /><br />" . $query->queryString . "<br /><br />" . $preparedStatement);
				}
			}
		}

		if (!$query->execute()) {
			//throw new Exception("Mysqli Error: " . $query->error . " - " . $this->debugQuery($statement, $values));
		}

		$result = null;

		switch (explode(" ", trim($statement))[0]) {
			case "SELECT":
				$result = $query->fetchAll(PDO::FETCH_ASSOC);
				break;
			case "INSERT":
				$result = $this->connection->lastInsertId();
				break;
			case "UPDATE":
			case "DELETE":
				$result = ($query->rowCount() > 0);
				break;
			default:
				$result = $query;
				break;
		}
		
		return $result;
	}

	private function debugQuery($statement, $values)
	{
		for ($i = 0; $i < count($values); $i++) {
			$statement = preg_replace('/\:[A-Za-z0-9]+/', $values[$i], $statement, 1);
			
			if (!strlen($values[$i]))
				return "'" . $values[$i - 1] . "";
		}
		
		return "query: " . $statement;
	}
}