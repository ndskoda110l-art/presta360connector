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
 * Sales for a period, optionally grouped (zadanie kap. 5).
 *
 * Two bases are returned side by side:
 * - by order date (date_add): orders and amounts customers paid ("manager" mode);
 * - by invoice date (invoice_date): revenue as BO statistics compute it ("bo" mode, default
 *   in answers) = (total_paid_tax_excl - total_shipping_tax_excl) / conversion_rate.
 * Only valid orders (current state logable) count, except for group_by=state on the order-date basis.
 * All amounts are converted to the default currency.
 */
class OrdersSummary implements QueryInterface
{
    private static $MAX_DAYS = 400;

    public function name()
    {
        return 'orders.summary';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $period = Period::resolve($params, self::$MAX_DAYS);
        $groupBy = Params::enum($params, 'group_by', ['none', 'day', 'hour', 'payment', 'carrier', 'state', 'shop'], 'none');
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);

        $shops = ShopScope::inList($scope->orderShopIds());
        $from = '\'' . pSQL($period['from']) . '\'';
        $to = '\'' . pSQL($period['to']) . '\'';
        $rate = 'IFNULL(NULLIF(o.`conversion_rate`, 0), 1)';

        list($bucketA, $joinA, $groupA) = $this->bucket($groupBy, 'o.`date_add`', $langId);
        $sqlA = 'SELECT ' . $bucketA . '
                COUNT(*) AS orders,
                SUM(o.`total_paid_tax_incl` / ' . $rate . ') AS paid_tax_incl,
                SUM(o.`total_paid_tax_excl` / ' . $rate . ') AS paid_tax_excl,
                SUM(o.`total_shipping_tax_incl` / ' . $rate . ') AS shipping_tax_incl,
                SUM(o.`total_discounts_tax_incl` / ' . $rate . ') AS discounts_tax_incl
            FROM ' . ReadOnlyDb::t('orders') . ' o' . $joinA . '
            WHERE o.`id_shop` IN (' . $shops . ')
              AND o.`date_add` >= ' . $from . ' AND o.`date_add` < ' . $to
            . ($groupBy === 'state' ? '' : ' AND o.`valid` = 1')
            . $groupA;

        list($bucketB, $joinB, $groupB) = $this->bucket($groupBy, 'o.`invoice_date`', $langId);
        $sqlB = 'SELECT ' . $bucketB . '
                COUNT(*) AS invoiced_orders,
                SUM((o.`total_paid_tax_excl` - o.`total_shipping_tax_excl`) / ' . $rate . ') AS revenue_bo
            FROM ' . ReadOnlyDb::t('orders') . ' o' . $joinB . '
            WHERE o.`id_shop` IN (' . $shops . ')
              AND o.`invoice_date` >= ' . $from . ' AND o.`invoice_date` < ' . $to . '
              AND o.`valid` = 1'
            . $groupB;

        $merged = [];
        foreach ($db->select($sqlA) as $row) {
            $key = $groupBy === 'none' ? '_' : (string) $row['bucket'];
            $merged[$key] = $this->emptyRow($row, $groupBy);
            $merged[$key]['orders'] = (int) $row['orders'];
            $merged[$key]['paid_tax_incl'] = $this->money($row['paid_tax_incl']);
            $merged[$key]['paid_tax_excl'] = $this->money($row['paid_tax_excl']);
            $merged[$key]['shipping_tax_incl'] = $this->money($row['shipping_tax_incl']);
            $merged[$key]['discounts_tax_incl'] = $this->money($row['discounts_tax_incl']);
        }
        foreach ($db->select($sqlB) as $row) {
            $key = $groupBy === 'none' ? '_' : (string) $row['bucket'];
            if (!isset($merged[$key])) {
                $merged[$key] = $this->emptyRow($row, $groupBy);
            }
            $merged[$key]['invoiced_orders'] = (int) $row['invoiced_orders'];
            $merged[$key]['revenue_bo'] = $this->money($row['revenue_bo']);
        }

        $totals = $this->emptyRow([], 'none');
        foreach ($merged as $row) {
            foreach (['orders', 'invoiced_orders'] as $field) {
                $totals[$field] += $row[$field];
            }
            foreach (['paid_tax_incl', 'paid_tax_excl', 'shipping_tax_incl', 'discounts_tax_incl', 'revenue_bo'] as $field) {
                $totals[$field] = round($totals[$field] + $row[$field], 2);
            }
        }

        $rows = [];
        if ($groupBy !== 'none') {
            $rows = array_values($merged);
            if ($groupBy === 'day' || $groupBy === 'hour') {
                usort($rows, function ($a, $b) {
                    return strnatcmp((string) $a['bucket'], (string) $b['bucket']);
                });
            } else {
                usort($rows, function ($a, $b) {
                    if ($a['revenue_bo'] == $b['revenue_bo']) {
                        return $b['orders'] - $a['orders'];
                    }

                    return $a['revenue_bo'] < $b['revenue_bo'] ? 1 : -1;
                });
            }
        }

        return [
            'period' => $period,
            'shops' => $scope->describe(),
            'currency' => $context->defaultCurrencyIso(),
            'group_by' => $groupBy,
            'totals' => $totals,
            'rows' => $rows,
            'definitions' => [
                'orders' => 'valid orders by order date',
                'paid_tax_incl' => 'total paid incl. tax by order date (manager mode)',
                'revenue_bo' => 'total paid excl. tax minus shipping excl. tax, by invoice date (BO statistics mode)',
                'invoiced_orders' => 'valid orders by invoice date',
            ],
        ];
    }

    /**
     * @return array [select prefix, joins, group by]
     */
    private function bucket($groupBy, $dateColumn, $langId)
    {
        switch ($groupBy) {
            case 'day':
                return ['DATE(' . $dateColumn . ') AS bucket, ', '', ' GROUP BY bucket'];
            case 'hour':
                return ['HOUR(' . $dateColumn . ') AS bucket, ', '', ' GROUP BY bucket'];
            case 'payment':
                return ['o.`module` AS bucket, MAX(o.`payment`) AS label, ', '', ' GROUP BY bucket'];
            case 'carrier':
                return [
                    'o.`id_carrier` AS bucket, MAX(c.`name`) AS label, ',
                    ' LEFT JOIN ' . ReadOnlyDb::t('carrier') . ' c ON c.`id_carrier` = o.`id_carrier`',
                    ' GROUP BY bucket',
                ];
            case 'state':
                return [
                    'o.`current_state` AS bucket, MAX(osl.`name`) AS label, ',
                    ' LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $langId,
                    ' GROUP BY bucket',
                ];
            case 'shop':
                return ['o.`id_shop` AS bucket, ', '', ' GROUP BY bucket'];
            default:
                return ['', '', ''];
        }
    }

    private function emptyRow(array $row, $groupBy)
    {
        $out = [];
        if ($groupBy !== 'none') {
            $out['bucket'] = in_array($groupBy, ['hour', 'carrier', 'state', 'shop'], true) ? (int) $row['bucket'] : (string) $row['bucket'];
            if (in_array($groupBy, ['payment', 'carrier', 'state'], true)) {
                $out['label'] = isset($row['label']) ? (string) $row['label'] : '';
            }
        }

        return $out + [
            'orders' => 0,
            'paid_tax_incl' => 0.0,
            'paid_tax_excl' => 0.0,
            'shipping_tax_incl' => 0.0,
            'discounts_tax_incl' => 0.0,
            'invoiced_orders' => 0,
            'revenue_bo' => 0.0,
        ];
    }

    private function money($value)
    {
        return round((float) $value, 2);
    }
}
