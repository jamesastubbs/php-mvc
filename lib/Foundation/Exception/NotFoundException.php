<?php

namespace PHPMVC\Foundation\Exception;

class NotFoundException extends \Exception
{
    public function __construct($message = null)
    {
        $message = $message ?: 'The requested page does not exist.';
        
        parent::__construct($message, 404, null);
    }
}
