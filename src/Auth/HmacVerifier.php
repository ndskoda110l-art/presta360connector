<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Auth;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Verifies X-P360-Signature: v1=hex(HMAC-SHA256(secret, ts . "." . sha256(rawBody))).
 * The timestamp is taken from the signed body and must be within WINDOW seconds of now.
 */
class HmacVerifier
{
    const WINDOW = 300;

    /** @var string[] */
    private $secrets = [];

    /**
     * @param array $secrets accepted secrets (current, and previous during rotation)
     */
    public function __construct(array $secrets)
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $this->secrets[] = $secret;
            }
        }
    }

    /**
     * @param string $rawBody
     * @param string|null $header
     * @param mixed $ts
     * @param int $now
     *
     * @return bool
     */
    public function verify($rawBody, $header, $ts, $now)
    {
        if (empty($this->secrets) || !is_string($header)) {
            return false;
        }
        if (!preg_match('/^v1=([0-9a-f]{64})$/', trim($header), $match)) {
            return false;
        }
        if (!is_int($ts) && !(is_string($ts) && ctype_digit($ts))) {
            return false;
        }
        $ts = (int) $ts;
        if (abs($now - $ts) > self::WINDOW) {
            return false;
        }

        $base = $ts . '.' . hash('sha256', (string) $rawBody);
        foreach ($this->secrets as $secret) {
            if (hash_equals(hash_hmac('sha256', $base, $secret), $match[1])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds the header value; used by tests and by the Presta360 service.
     *
     * @param string $secret
     * @param int $ts
     * @param string $rawBody
     *
     * @return string
     */
    public static function sign($secret, $ts, $rawBody)
    {
        return 'v1=' . hash_hmac('sha256', (int) $ts . '.' . hash('sha256', (string) $rawBody), $secret);
    }
}
