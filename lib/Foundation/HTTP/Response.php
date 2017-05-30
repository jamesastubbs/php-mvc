<?php

namespace PHPMVC\Foundation\HTTP;

/**
 * Class Response
 * @package  PHPMVC\Foundation\HTTP
 *
 * Object representation of an HTTP response.
 * Stores headers, status, protocol information,
 * all needed to send back to the client.
 */
class Response
{
    const STATUS_UNKNOWN = 0;
    const STATUS_CONTINUE = 100;
    const STATUS_SWITCHING_PROTOCOLS = 101;
    const STATUS_OK = 200;
    const STATUS_CREATED = 201;
    const STATUS_ACCEPTED = 202;
    const STATUS_NON_AUTHORATIVE_INFORMATION = 203;
    const STATUS_NO_CONTENT = 204;
    const STATUS_RESET_CONTENT = 205;
    const STATUS_PARTIAL_CONTENT = 206;
    const STATUS_MULTIPLE_CHOICES = 300;
    const STATUS_MOVED_PERMANENTLY = 301;
    const STATUS_MOVED_TEMPORARILY = 302;
    const STATUS_SEE_OTHER = 303;
    const STATUS_NOT_MODIFIED = 304;
    const STATUS_USE_PROXY = 305;
    const STATUS_BAD_REQUEST = 400;
    const STATUS_UNAUTHORIZED = 401;
    const STATUS_PAYMENT_REQUIRED = 402;
    const STATUS_FORBIDDEN = 403;
    const STATUS_NOT_FOUND = 404;
    const STATUS_METHOD_NOT_ALLOWED = 405;
    const STATUS_NOT_ACCEPTABLE = 406;
    const STATUS_PROXY_AUTHENTICATION_REQUIRED = 407;
    const STATUS_REQUEST_TIME_OUT = 408;
    const STATUS_CONFLICT = 409;
    const STATUS_GONE = 410;
    const STATUS_LENGTH_REQUIRED = 411;
    const STATUS_PRECONDITION_FAILED = 412;
    const STATUS_REQUEST_ENTITY_TOO_LARGE = 413;
    const STATUS_REQUEST_URI_TOO_LARGE = 414;
    const STATUS_UNSUPPORTED_MEDIA_TYPE = 415;
    const STATUS_INTERNAL_SERVER_ERROR = 500;
    const STATUS_NOT_IMPLEMENTED = 501;
    const STATUS_BAD_GATEWAY = 502;
    const STATUS_SERVICE_UNAVAILABLE = 503;
    const STATUS_GATEWAY_TIME_OUT = 504;
    const STATUS_HTTP_VERSION_NOT_SUPPORTED = 505;

    /**
     * @var  string
     */
    protected $body;

    /**
     * @var  array
     */
    protected $headers = [];

    /**
     * @var  boolean|false
     */
    protected $inDebug = false;

    /**
     * @var  string
     */
    protected $protocol = 'HTTP/1.0';

    /**
     * @var  int
     */
    protected $status;

    /**
     * @var  boolean
     */
    protected $streamed = false;

    /**
     * @param  string  $body    Data of the HTTP response body.
     * @param  int     $status  HTTP status code of the response.
     */
    public function __construct($body = '', $status = self::STATUS_OK)
    {
        $this->body = $body;
        $this->status = $status;
    }

    /**
     * @return  string  Stored data of the HTTP response body.
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @return  array  Collection of headers to be set for this response.
     */
    public function getHeaders()
    {
        return $this->headers;
    }

    /**
     * @return  string  The HTTP protocol set for the response.
     */
    public function getProtocol()
    {
        return $this->protocol;
    }

    /**
     * @return  int  The HTTP status of the response.
     */
    public function getStatus()
    {
        return $this->status;
    }

    public function isStreamed()
    {
        return $this->streamed;
    }

    /**
     * @para  string  $body  Data of the HTTP response body.
     */
    public function setBody($body)
    {
        $this->body = $body;
    }

    /**
     * Marks the response in debug or non-debug mode.
     * This may have an impact on the way the response is presented back to the client.
     *
     * @param  boolean  $inDebug
     */
    public function setInDebug($inDebug)
    {
        $this->inDebug = $inDebug;
    }

    public function setProtocol($protocol)
    {
        $this->protocol = $protocol;
    }

    /**
     * @param  int  $status  HTTP status code of the response.
     */
    public function setStatus($status)
    {
        $this->status = $status;
    }

    /**
     * @param  string  $name   The header name to set.
     * @param  string  $value  The data of the header that is being set.
     */
    public function setHeader($name, $value)
    {
        $this->headers[$name] = $value;
    }

    /**
     * @return  string  The compiled status header. Takes the current status and adds the formatted message along with the protocol.
     */
    public function getStatusHeader()
    {
        $text = self::getTextForStatus($this->status, $this->protocol);

        return "{$this->protocol} {$this->status} {$text}";
    }

    public function setIsStreamed($streamed)
    {
        $this->streamed = $streamed;
    }

    public static function getTextForStatus($status)
    {
        $headerStatuses = self::getStatusHeaders();

        return isset($headerStatuses[$status]) ? $headerStatuses[$status] : 'Unknown HTTP Status Code';
    }

    public static function getStatusHeaders()
    {
        return [
            self::STATUS_CONTINUE => 'Continue',
            self::STATUS_SWITCHING_PROTOCOLS => 'Switching Protocols',
            self::STATUS_OK => 'OK',
            self::STATUS_CREATED => 'Created',
            self::STATUS_ACCEPTED => 'Accepted',
            self::STATUS_NON_AUTHORATIVE_INFORMATION => 'Non-Authoritative Information',
            self::STATUS_NO_CONTENT => 'No Content',
            self::STATUS_RESET_CONTENT => 'Reset Content',
            self::STATUS_PARTIAL_CONTENT => 'Partial Content',
            self::STATUS_MULTIPLE_CHOICES => 'Multiple Choices',
            self::STATUS_MOVED_PERMANENTLY => 'Moved Permanently',
            self::STATUS_MOVED_TEMPORARILY => 'Moved Temporarily',
            self::STATUS_SEE_OTHER => 'See Other',
            self::STATUS_NOT_MODIFIED => 'Not Modified',
            self::STATUS_USE_PROXY => 'Use Proxy',
            self::STATUS_BAD_REQUEST => 'Bad Request',
            self::STATUS_UNAUTHORIZED => 'Unauthorized',
            self::STATUS_PAYMENT_REQUIRED => 'Payment Required',
            self::STATUS_FORBIDDEN => 'Forbidden',
            self::STATUS_NOT_FOUND => 'Not Found',
            self::STATUS_METHOD_NOT_ALLOWED => 'Method Not Allowed',
            self::STATUS_NOT_ACCEPTABLE => 'Not Acceptable',
            self::STATUS_PROXY_AUTHENTICATION_REQUIRED => 'Proxy Authentication Required',
            self::STATUS_REQUEST_TIME_OUT => 'Request Time-out',
            self::STATUS_CONFLICT => 'Conflict',
            self::STATUS_GONE => 'Gone',
            self::STATUS_LENGTH_REQUIRED => 'Length Required',
            self::STATUS_PRECONDITION_FAILED => 'Precondition Failed',
            self::STATUS_REQUEST_ENTITY_TOO_LARGE => 'Request Entity Too Large',
            self::STATUS_REQUEST_URI_TOO_LARGE => 'Request-URI Too Large',
            self::STATUS_UNSUPPORTED_MEDIA_TYPE => 'Unsupported Media Type',
            self::STATUS_INTERNAL_SERVER_ERROR => 'Internal Server Error',
            self::STATUS_NOT_IMPLEMENTED => 'Not Implemented',
            self::STATUS_BAD_GATEWAY => 'Bad Gateway',
            self::STATUS_SERVICE_UNAVAILABLE => 'Service Unavailable',
            self::STATUS_GATEWAY_TIME_OUT => 'Gateway Time-out',
            self::STATUS_HTTP_VERSION_NOT_SUPPORTED => 'HTTP Version not supported'
        ];
    }
}
