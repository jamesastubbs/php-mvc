<?php

namespace PHPMVC\Foundation\Exception;

use PHPMVC\Foundation\HTTP\Response;

class HTTPException extends \Exception
{
    public function __construct($message = null, $status = 500)
    {
        $text = Response::getTextForStatus($status);
        $message = $message ?: "{$status} - {$text}";

        parent::__construct($message, $status, null);
    }
}
