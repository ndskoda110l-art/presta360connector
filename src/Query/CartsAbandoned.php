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
 * Abandoned carts (zadanie, príloha B: carts.abandoned; same rule as BO dashactivity): carts with
 * products, last changed between "to_hours" and "from_hours" ago, that never became an order and
 * whose customer did not order anything after the cart was last changed (zadanie D17, pilot 4cars).
 *
 * The value is an estimate without tax: base product price + combination impact, no discounts,
 * specific prices or shipping (zadanie D17). Customer data in transit only (zadanie D6).
 */
class CartsAbandoned implements QueryInterface
{
    /** @var int */
    private static $MAX_HOURS = 720;

    public function name()
    {
        return 'carts.abandoned';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $fromHours = Params::int($params, 'from_hours', 1, 0, self::$MAX_HOURS - 1);
        $toHours = Params::int($params, 'to_hours', 168, $fromHours + 1, self::$MAX_HOURS);
        $onlyKnown = Params::bool($params, 'only_known', false);
        $limit = Params::int($params, 'limit', 20, 1, 50);
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);

        $now = time();
        $newest = date('Y-m-d H:i:s', $now - $fromHours * 3600);
        $oldest = date('Y-m-d H:i:s', $now - $toHours * 3600);
        $where = ' FROM ' . ReadOnlyDb::t('cart') . ' ca
            LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = ca.`id_customer`
            WHERE ca.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')
              AND ca.`date_upd` >= \'' . pSQL($oldest) . '\' AND ca.`date_upd` < \'' . pSQL($newest) . '\'
              AND NOT EXISTS (SELECT 1 FROM ' . ReadOnlyDb::t('orders') . ' o WHERE o.`id_cart` = ca.`id_cart`)
              AND NOT EXISTS (SELECT 1 FROM ' . ReadOnlyDb::t('orders') . ' o2 WHERE ca.`id_customer` > 0 AND o2.`id_customer` = ca.`id_customer` AND o2.`date_add` >= ca.`date_upd`)
              AND EXISTS (SELECT 1 FROM ' . ReadOnlyDb::t('cart_product') . ' cp0 WHERE cp0.`id_cart` = ca.`id_cart`)'
            . ($onlyKnown ? ' AND ca.`id_customer` > 0' : '');

        $value = '(SELECT SUM(cp.`quantity` * (ps.`price` + IFNULL(pas.`price`, 0)))
            FROM ' . ReadOnlyDb::t('cart_product') . ' cp
            INNER JOIN ' . ReadOnlyDb::t('product_shop') . ' ps ON ps.`id_product` = cp.`id_product` AND ps.`id_shop` = cp.`id_shop`
            LEFT JOIN ' . ReadOnlyDb::t('product_attribute_shop') . ' pas ON pas.`id_product_attribute` = cp.`id_product_attribute` AND pas.`id_shop` = cp.`id_shop`
            WHERE cp.`id_cart` = ca.`id_cart`)';

        // MySQL 5.6: no aggregate over a correlated subquery, so aggregate a derived table.
        $totals = $db->row('SELECT COUNT(*) AS carts, SUM(t.known) AS known, SUM(t.value) AS value
            FROM (SELECT (ca.`id_customer` > 0) AS known, ' . $value . ' AS value' . $where . ') t');

        $rows = $db->select('SELECT ca.`id_cart`, ca.`id_customer`, ca.`date_add`, ca.`date_upd`,
                cu.`firstname`, cu.`lastname`, cu.`email`, cu.`company`, cu.`is_guest`,
                (SELECT SUM(cp.`quantity`) FROM ' . ReadOnlyDb::t('cart_product') . ' cp WHERE cp.`id_cart` = ca.`id_cart`) AS qty,
                ' . $value . ' AS value'
            . $where . '
            ORDER BY ca.`date_upd` DESC
            LIMIT ' . (int) $limit);

        $carts = [];
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_cart'];
            $ids[] = $id;
            $updated = strtotime((string) $row['date_upd']);
            $carts[$id] = [
                'id_cart' => $id,
                'id_customer' => (int) $row['id_customer'],
                'customer' => trim($row['firstname'] . ' ' . $row['lastname']),
                'email' => (string) $row['email'],
                'company' => (string) $row['company'],
                'guest' => (bool) $row['is_guest'],
                'updated' => (string) $row['date_upd'],
                'hours_ago' => $updated === false ? null : max(0, (int) floor(($now - $updated) / 3600)),
                'quantity' => (int) $row['qty'],
                'value_estimate' => round((float) $row['value'], 2),
                'products' => [],
            ];
        }

        if (!empty($ids)) {
            foreach ($db->select('SELECT cp.`id_cart`, cp.`quantity`, pl.`name`
                FROM ' . ReadOnlyDb::t('cart_product') . ' cp
                LEFT JOIN ' . ReadOnlyDb::t('product_lang') . ' pl ON pl.`id_product` = cp.`id_product` AND pl.`id_lang` = ' . (int) $langId . ' AND pl.`id_shop` = cp.`id_shop`
                WHERE cp.`id_cart` IN (' . implode(',', array_map('intval', $ids)) . ')
                ORDER BY cp.`id_cart`, cp.`quantity` DESC
                LIMIT 500') as $p) {
                $cart = (int) $p['id_cart'];
                if (isset($carts[$cart]) && count($carts[$cart]['products']) < 5) {
                    $carts[$cart]['products'][] = ['name' => (string) $p['name'], 'quantity' => (int) $p['quantity']];
                }
            }
        }

        return [
            'shops' => $scope->describe(),
            'currency' => $context->defaultCurrencyIso(),
            'window' => ['from_hours' => $fromHours, 'to_hours' => $toHours, 'oldest' => $oldest, 'newest' => $newest],
            'totals' => [
                'carts' => (int) $totals['carts'],
                'known_customers' => (int) $totals['known'],
                'value_estimate' => round((float) $totals['value'], 2),
            ],
            'carts' => array_values($carts),
            'value_note' => 'estimate: base price + combination impact, without tax, discounts or shipping',
            'untrusted_fields' => ['carts.customer', 'carts.email', 'carts.company'],
        ];
    }
}
