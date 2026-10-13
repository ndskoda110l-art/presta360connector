<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Thrown for invalid request parameters. The message is returned to the caller,
 * so it must describe only the parameter, never shop data or internals.
 */
class InvalidParamsException extends \Exception
{
}
