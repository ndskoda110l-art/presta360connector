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

/**
 * The complete list of queries this module can run. There is no way to run any other SQL.
 */
class Registry
{
    /** @var array name => class */
    private static $queries = [
        'ping' => 'Presta360\\Connector\\Query\\Ping',
        'orders.summary' => 'Presta360\\Connector\\Query\\OrdersSummary',
        'orders.to_ship' => 'Presta360\\Connector\\Query\\OrdersToShip',
        'stock.low' => 'Presta360\\Connector\\Query\\StockLow',
        'service.inbox' => 'Presta360\\Connector\\Query\\ServiceInbox',
    ];

    /**
     * @param string $name
     *
     * @return QueryInterface|null
     */
    public static function get($name)
    {
        if (!is_string($name) || !isset(self::$queries[$name])) {
            return null;
        }
        $class = self::$queries[$name];

        return new $class();
    }

    /**
     * @return string[]
     */
    public static function names()
    {
        return array_keys(self::$queries);
    }
}
