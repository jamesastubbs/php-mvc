<?php

namespace PHPMVC\Foundation\HTTP;

use PHPMVC\Foundation\HTTP\Response;

class RedirectResponse extends Response
{
    private $url;

    public function __construct($url = '', $status = 301)
    {
        $this->url = $url;

        parent::__construct('', $status);
    }

    public function getHeaders()
    {
        $headers = array_merge(
            parent::getHeaders(),
            ['Location' => $this->url]
        );

        return $headers;
    }
}
