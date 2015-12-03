<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.0
 */

require 'config/config.php';
require 'lib/application.class.php';
require 'lib/controller.class.php';
require 'lib/db.class.php';
require 'lib/model.class.php';

error_reporting(E_ALL);
ini_set("display_errors", 1);
date_default_timezone_set("Europe/London");

define('URL', 'http://' . explode(NAME, $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])[0] . NAME);

foreach (scandir('lib/_dbdrivers') as $file) { //require all files located in the '_dbdrivers' directory in 'lib'
	if (strcmp($file, ".") && strcmp($file, ".."))
		require 'lib/_dbdrivers/' . $file;
}

foreach (scandir('lib/_inc') as $file) { //require all files located in the '_inc' directory in 'lib'
	if (strcmp($file, ".") && strcmp($file, ".."))
		require 'lib/_inc/' . $file;
}

foreach (scandir('application/model') as $file) { //require all files located in the 'model' directory in 'application'
	if (strcmp($file, ".") && strcmp($file, ".."))
		require 'application/model/' . $file;
}

foreach (scandir('application/controller') as $file) { //require all files located in the 'model' directory in 'application'
	if (strcmp($file, ".") && strcmp($file, ".."))
		require 'application/controller/' . $file;
}

new Application();