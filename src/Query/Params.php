<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Query;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Presta360\Connector\Api\InvalidParamsException;

/**
 * Strict parameter validation. Every value that reaches SQL passes through here.
 */
class Params
{
    /**
     * @return int
     *
     * @throws InvalidParamsException
     */
    public static function int(array $params, $key, $default, $min, $max)
    {
        if (!isset($params[$key])) {
            return (int) $default;
        }
        $value = $params[$key];
        if (is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value))) {
            $value = (int) $value;
            if ($value >= $min && $value <= $max) {
                return $value;
            }
        }

        throw new InvalidParamsException(sprintf('Parameter "%s" must be an integer between %d and %d.', $key, $min, $max));
    }

    /**
     * @return bool
     *
     * @throws InvalidParamsException
     */
    public static function bool(array $params, $key, $default)
    {
        if (!isset($params[$key])) {
            return (bool) $default;
        }
        $value = $params[$key];
        if (is_bool($value)) {
            return $value;
        }
        if ($value === 1 || $value === '1' || $value === 'true') {
            return true;
        }
        if ($value === 0 || $value === '0' || $value === 'false') {
            return false;
        }

        throw new InvalidParamsException(sprintf('Parameter "%s" must be a boolean.', $key));
    }

    /**
     * @return string
     *
     * @throws InvalidParamsException
     */
    public static function enum(array $params, $key, array $allowed, $default)
    {
        if (!isset($params[$key])) {
            return $default;
        }
        if (is_string($params[$key]) && in_array($params[$key], $allowed, true)) {
            return $params[$key];
        }

        throw new InvalidParamsException(sprintf('Parameter "%s" must be one of: %s.', $key, implode(', ', $allowed)));
    }

    /**
     * List of identifiers such as module names.
     *
     * @return string[]
     *
     * @throws InvalidParamsException
     */
    public static function identifierList(array $params, $key, array $default, $maxItems)
    {
        if (!isset($params[$key])) {
            return $default;
        }
        $value = $params[$key];
        if (is_array($value) && count($value) <= $maxItems) {
            $out = [];
            foreach ($value as $item) {
                if (!is_string($item) || !preg_match('/^[a-zA-Z0-9_]{1,64}$/', $item)) {
                    $out = null;
                    break;
                }
                $out[] = $item;
            }
            if ($out !== null) {
                return array_values(array_unique($out));
            }
        }

        throw new InvalidParamsException(sprintf('Parameter "%s" must be a list of at most %d identifiers.', $key, $maxItems));
    }

    /**
     * Shop selector: absent = shop resolved from the request, "all" = every shop, or a positive id.
     *
     * @return int|string|null
     *
     * @throws InvalidParamsException
     */
    public static function shop(array $params)
    {
        if (!isset($params['id_shop'])) {
            return null;
        }
        $value = $params['id_shop'];
        if ($value === 'all') {
            return 'all';
        }
        if ((is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0) {
            return (int) $value;
        }

        throw new InvalidParamsException('Parameter "id_shop" must be a positive integer or "all".');
    }

    /**
     * @param string $value
     *
     * @return bool true for "Y-m-d H:i:s" that is a real calendar date
     */
    public static function isDateTime($value)
    {
        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2}) (\d{2}):(\d{2}):(\d{2})$/', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60;
    }
}
