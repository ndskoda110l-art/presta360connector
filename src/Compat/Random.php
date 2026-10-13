<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Compat;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Cryptographically secure random bytes on PHP 5.6 (OpenSSL) and 7.0+ (random_bytes).
 */
class Random
{
    /**
     * @param int $bytes
     *
     * @return string hex string of 2 * $bytes characters
     *
     * @throws \RuntimeException when no secure source is available
     */
    public static function hex($bytes)
    {
        $bytes = (int) $bytes;
        if (function_exists('random_bytes')) {
            return bin2hex(random_bytes($bytes));
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            $strong = false;
            $random = openssl_random_pseudo_bytes($bytes, $strong);
            if ($random !== false && $strong === true) {
                return bin2hex($random);
            }
        }

        throw new \RuntimeException('No secure random source is available (enable OpenSSL or use PHP 7+).');
    }
}
