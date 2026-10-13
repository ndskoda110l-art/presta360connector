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
 * Visits for a period, as BO statistics count them (AdminStatsController::getVisits):
 * rows of `connections` (one per guest session, 30 min deduplication, bots filtered by statsdata)
 * and distinct guests. Visits exist only when the statsdata module is active.
 * Conversion is computed by the service from orders.summary for the same period.
 */
class TrafficSummary implements QueryInterface
{
    private static $MAX_DAYS = 400;

    public function name()
    {
        return 'traffic.summary';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $period = Period::resolve($params, self::$MAX_DAYS);
        $groupBy = Params::enum($params, 'group_by', ['none', 'day', 'hour'], 'none');
        $scope = $context->shopScope($params);
        $probe = new Probe($db);
        $modules = $probe->modules(['statsdata']);

        $result = [
            'period' => $period,
            'shops' => $scope->describe(),
            'available' => $modules['statsdata'],
            'last_recorded' => $probe->lastConnection(),
            'totals' => ['visits' => 0, 'unique_visitors' => 0],
            'rows' => [],
            'definitions' => [
                'visits' => 'guest sessions recorded by statsdata (30 min deduplication, bots excluded)',
                'unique_visitors' => 'distinct guests in the period',
            ],
        ];
        if (!$modules['statsdata']) {
            return $result;
        }

        $where = ' FROM ' . ReadOnlyDb::t('connections') . ' c
            WHERE c.`id_shop` IN (' . ShopScope::inList($scope->shopIds()) . ')
              AND c.`date_add` >= \'' . pSQL($period['from']) . '\' AND c.`date_add` < \'' . pSQL($period['to']) . '\'';

        $totals = $db->row('SELECT COUNT(*) AS visits, COUNT(DISTINCT c.`id_guest`) AS unique_visitors' . $where);
        $result['totals'] = [
            'visits' => (int) $totals['visits'],
            'unique_visitors' => (int) $totals['unique_visitors'],
        ];

        if ($groupBy !== 'none') {
            $bucket = $groupBy === 'day' ? 'DATE(c.`date_add`)' : 'HOUR(c.`date_add`)';
            $rows = $db->select(
                'SELECT ' . $bucket . ' AS bucket, COUNT(*) AS visits, COUNT(DISTINCT c.`id_guest`) AS unique_visitors'
                . $where . ' GROUP BY bucket ORDER BY bucket'
            );
            foreach ($rows as $row) {
                $result['rows'][] = [
                    'bucket' => $groupBy === 'hour' ? (int) $row['bucket'] : (string) $row['bucket'],
                    'visits' => (int) $row['visits'],
                    'unique_visitors' => (int) $row['unique_visitors'],
                ];
            }
        }

        return $result;
    }
}
