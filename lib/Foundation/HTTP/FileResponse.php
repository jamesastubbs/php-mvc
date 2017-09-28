<?php

namespace PHPMVC\Foundation\HTTP;

use PHPMVC\Foundation\HTTP\Response;

class FileResponse extends Response
{
    /**
     * @var  string
     */
    protected $filePath = '';

    public function __construct($filePath, $status = 200)
    {
        $this->setFilePath($filePath);

        parent::__construct('', $status);
    }

    public function getFilePath()
    {
        return $this->filePath;
    }

    public function setFilePath($filePath)
    {
        if (!is_string($filePath) || $filePath === '') {
            throw new \Exception('File path must be a string and cannot be blank.');
        }

        if (!file_exists($filePath)) {
            throw new \Exception("File '$filePath' does not exist.");
        }

        $this->setHeader('Last-Modified', gmdate('D, d M Y H:i:s', filemtime($filePath)) . ' GMT');
        $this->setHeader('Content-Type', mime_content_type($filePath));
        $this->setHeader('Content-Length', filesize($filePath));

        $this->filePath = $filePath;
    }
}
