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

use Presta360\Connector\Capabilities\Probe;
use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Shop\ShopScope;

/**
 * Visitors active in the last N minutes (BO dashboard "online visitors" uses 30 minutes):
 * distinct guests with a connection started in the window. Requires statsdata.
 */
class TrafficOnline implements QueryInterface
{
    public function name()
    {
        return 'traffic.online';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $minutes = Params::int($params, 'minutes', 30, 5, 240);
        $scope = $context->shopScope($params);
        $probe = new Probe($db);
        $modules = $probe->modules(['statsdata']);
        $since = date('Y-m-d H:i:s', time() - $minutes * 60);

        $online = 0;
        $customers = 0;
        if ($modules['statsdata']) {
            $row = $db->row(
                'SELECT COUNT(DISTINCT c.`id_guest`) AS online,
                        COUNT(DISTINCT CASE WHEN g.`id_customer` > 0 THEN g.`id_customer` END) AS customers
                 FROM ' . ReadOnlyDb::t('connections') . ' c
                 LEFT JOIN ' . ReadOnlyDb::t('guest') . ' g ON g.`id_guest` = c.`id_guest`
                 WHERE c.`id_shop` IN (' . ShopScope::inList($scope->shopIds()) . ')
                   AND c.`date_add` >= \'' . pSQL($since) . '\''
            );
            $online = (int) $row['online'];
            $customers = (int) $row['customers'];
        }

        return [
            'shops' => $scope->describe(),
            'available' => $modules['statsdata'],
            'minutes' => $minutes,
            'since' => $since,
            'online' => $online,
            'logged_in_customers' => $customers,
        ];
    }
}
