<?php

/**
 * @package	PHP MVC Framework
 * @author 	James Stubbs
 * @version 1.1
 */


date_default_timezone_set("Europe/London");

require 'config/config.php';
$config = new ArrayObject($config, ArrayObject::ARRAY_AS_PROPS);

define('DEBUG', $config->DEBUG);
define('MAINTENANCE', $config->MAINTENANCE);
define('TITLE', $config->TITLE);
define('URL', 'http://' . explode($config->NAME, $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'])[0] . $config->NAME);
define('WHITELIST', $config->WHITELIST);

if (DEBUG) {
    error_reporting(E_ALL);
    ini_set("display_errors", 1);
    ini_set('xdebug.var_display_max_depth', -1);
    ini_set('xdebug.var_display_max_children', -1);
    ini_set('xdebug.var_display_max_data', -1);
}

require 'lib/application.class.php';
require 'lib/controller.class.php';
require 'lib/db.class.php';
require 'lib/model.class.php';

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

$application = new Application($config);