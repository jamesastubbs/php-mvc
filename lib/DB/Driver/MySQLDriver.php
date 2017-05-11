<?php

namespace PHPMVC\DB\Driver;

use \PHPMVC\DB\Driver\DBDriver;

class MysqlDriver extends DBDriver
{
    protected function getDSNFromConfig($config)
    {
        $charset = isset($config['charset']) ? $config['charset'] : 'utf8';
        $port = isset($config['port']) ? $config['port'] : 3306;

        return "mysql:host={$config['host']};port={$port};dbname={$config['name']};charset={$charset}";
    }
}
