<?php

namespace PHPMVC\Foundation\Exception;

use PHPMVC\Foundation\Exception\HTTPException;
use PHPMVC\Foundation\HTTP\Response;

class NotFoundException extends HTTPException
{
    public function __construct($message = null)
    {
        $message = $message ?: 'The requested page does not exist.';

        parent::__construct($message, Response::STATUS_NOT_FOUND, null);
    }
}
