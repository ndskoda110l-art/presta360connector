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

use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Shop\ShopScope;

/**
 * Orders waiting for shipment, grouped by carrier (zadanie: "Čo dnes zabaliť").
 *
 * Waiting = current state logable, not shipped, not delivered, not deleted, and either paid
 * or (optionally) cash on delivery. This matches BO dashactivity "pending orders"
 * (paid = 1 AND shipped = 0) and adds COD orders that ship before payment.
 */
class OrdersToShip implements QueryInterface
{
    /** Hard cap of rows read; above it the result is marked truncated. */
    private static $MAX_ROWS = 500;

    public function name()
    {
        return 'orders.to_ship';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $includeCod = Params::bool($params, 'include_unpaid_cod', true);
        $codModules = Params::identifierList($params, 'cod_modules', ['ps_cashondelivery', 'cashondelivery'], 20);
        $maxAgeDays = Params::int($params, 'max_age_days', 60, 1, 365);
        $limit = Params::int($params, 'limit', 50, 1, 50);
        $carrierId = Params::int($params, 'id_carrier', 0, 0, PHP_INT_MAX);
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);

        $waiting = 'os.`paid` = 1';
        if ($includeCod && !empty($codModules)) {
            $quoted = [];
            foreach ($codModules as $module) {
                $quoted[] = '\'' . pSQL($module) . '\'';
            }
            $waiting = '(' . $waiting . ' OR o.`module` IN (' . implode(',', $quoted) . '))';
        }

        $rate = 'IFNULL(NULLIF(o.`conversion_rate`, 0), 1)';
        $sql = 'SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`date_upd`, o.`current_state`,
                    osl.`name` AS state_name, os.`paid`,
                    o.`total_paid_tax_incl` / ' . $rate . ' AS value_tax_incl,
                    (o.`total_paid_tax_excl` - o.`total_shipping_tax_excl`) / ' . $rate . ' AS value_bo,
                    o.`payment`, o.`module`, o.`id_carrier`, c.`name` AS carrier_name,
                    o.`id_customer`, cu.`firstname`, cu.`lastname`
                FROM ' . ReadOnlyDb::t('orders') . ' o
                INNER JOIN ' . ReadOnlyDb::t('order_state') . ' os ON os.`id_order_state` = o.`current_state`
                LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $langId . '
                LEFT JOIN ' . ReadOnlyDb::t('carrier') . ' c ON c.`id_carrier` = o.`id_carrier`
                LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = o.`id_customer`
                WHERE o.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')
                  AND o.`date_add` >= \'' . pSQL(Period::daysAgo($maxAgeDays)) . '\'
                  AND os.`logable` = 1 AND os.`shipped` = 0 AND os.`delivery` = 0 AND os.`deleted` = 0
                  AND ' . $waiting
            . ($carrierId > 0 ? ' AND o.`id_carrier` = ' . (int) $carrierId : '') . '
                ORDER BY o.`date_add` ASC
                LIMIT ' . (self::$MAX_ROWS + 1);

        $rows = $db->select($sql);
        $truncated = count($rows) > self::$MAX_ROWS;
        if ($truncated) {
            $rows = array_slice($rows, 0, self::$MAX_ROWS);
        }

        $now = time();
        $codLookup = array_fill_keys($codModules, true);
        $carriers = [];
        $orders = [];
        $total = ['orders' => 0, 'value_tax_incl' => 0.0, 'value_bo' => 0.0, 'cod_unpaid' => 0];

        foreach ($rows as $row) {
            $carrier = (int) $row['id_carrier'];
            if (!isset($carriers[$carrier])) {
                $carriers[$carrier] = [
                    'id_carrier' => $carrier,
                    'carrier' => (string) $row['carrier_name'],
                    'orders' => 0,
                    'value_tax_incl' => 0.0,
                    'oldest_waiting_hours' => 0,
                ];
            }

            $stateChanged = strtotime((string) $row['date_upd']);
            $waitingHours = $stateChanged === false ? null : max(0, (int) floor(($now - $stateChanged) / 3600));
            $value = round((float) $row['value_tax_incl'], 2);
            $isCodUnpaid = !(bool) $row['paid'] && isset($codLookup[(string) $row['module']]);

            ++$carriers[$carrier]['orders'];
            $carriers[$carrier]['value_tax_incl'] = round($carriers[$carrier]['value_tax_incl'] + $value, 2);
            if ($waitingHours !== null && $waitingHours > $carriers[$carrier]['oldest_waiting_hours']) {
                $carriers[$carrier]['oldest_waiting_hours'] = $waitingHours;
            }

            ++$total['orders'];
            $total['value_tax_incl'] = round($total['value_tax_incl'] + $value, 2);
            $total['value_bo'] = round($total['value_bo'] + (float) $row['value_bo'], 2);
            if ($isCodUnpaid) {
                ++$total['cod_unpaid'];
            }

            if (count($orders) < $limit) {
                $orders[] = [
                    'id_order' => (int) $row['id_order'],
                    'reference' => (string) $row['reference'],
                    'date_add' => (string) $row['date_add'],
                    'state' => (string) $row['state_name'],
                    'id_state' => (int) $row['current_state'],
                    'hours_in_state' => $waitingHours,
                    'value_tax_incl' => $value,
                    'payment' => (string) $row['payment'],
                    'cod_unpaid' => $isCodUnpaid,
                    'id_carrier' => $carrier,
                    'carrier' => (string) $row['carrier_name'],
                    'id_customer' => (int) $row['id_customer'],
                    'customer' => trim($row['firstname'] . ' ' . $row['lastname']),
                ];
            }
        }

        $carriers = array_values($carriers);
        usort($carriers, function ($a, $b) {
            return $b['orders'] - $a['orders'];
        });

        return [
            'shops' => $scope->describe(),
            'currency' => $context->defaultCurrencyIso(),
            'since' => Period::daysAgo($maxAgeDays),
            'totals' => $total,
            'carriers' => $carriers,
            'orders' => $orders,
            'orders_listed' => count($orders),
            'truncated' => $truncated,
        ];
    }
}
