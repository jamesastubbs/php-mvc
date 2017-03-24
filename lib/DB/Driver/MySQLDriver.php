<?php

namespace PHPMVC\DB\Driver;

use \PHPMVC\DB\Driver\DBDriver;

class MySQLDriver extends DBDriver
{
    protected function getDSNFromConfig($config)
    {
        $charset = isset($config->DB_CHARSET) ? $config->DB_CHARSET : 'utf8';

        return "mysql:host={$config->DB_HOST};dbname={$config->DB_NAME};charset=$charset";
    }
}
