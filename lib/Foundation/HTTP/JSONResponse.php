<?php

namespace PHPMVC\Foundation\HTTP;

use PHPMVC\Foundation\HTTP\Response;

class JSONResponse extends Response
{
    public function __construct($body = [], $status = 200)
    {
        parent::__construct($body, $status);

        $this->setHeader('Content-Type', 'application/json');
    }

    public function getBody()
    {
        return json_encode(
            parent::getBody(),
            $this->inDebug ? JSON_PRETTY_PRINT : 0
        );
    }
}
