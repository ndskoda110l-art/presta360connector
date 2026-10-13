<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Request
{
    /** @var string */
    private $method;

    /** @var string */
    private $body;

    /** @var array */
    private $server;

    /**
     * @param string $method
     * @param string $body
     * @param array $server
     */
    public function __construct($method, $body, array $server)
    {
        $this->method = strtoupper((string) $method);
        $this->body = (string) $body;
        $this->server = $server;
    }

    /**
     * @return self
     */
    public static function fromGlobals()
    {
        $method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $body = '';
        if ($method === 'POST') {
            // Read one byte more than allowed so oversized bodies are detected.
            $read = file_get_contents('php://input', false, null, 0, 65537);
            $body = is_string($read) ? $read : '';
        }

        return new self($method, $body, $_SERVER);
    }

    /**
     * @return string
     */
    public function getMethod()
    {
        return $this->method;
    }

    /**
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * @param string $name e.g. "X-P360-Signature"
     *
     * @return string|null
     */
    public function getHeader($name)
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) && is_string($this->server[$key]) ? $this->server[$key] : null;
    }
}
