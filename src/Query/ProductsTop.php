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
 * Best-selling products of a period (zadanie D13, karta "Dnes letí").
 *
 * Valid orders by order date (as BO "Best-selling products"); revenue is the line total without
 * tax converted to the default currency. Product + combination are one row; the name is taken
 * from the order line (what the customer bought), with the current catalog name as fallback.
 */
class ProductsTop implements QueryInterface
{
    /** @var int */
    private static $MAX_DAYS = 400;

    public function name()
    {
        return 'products.top';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $period = Period::resolve($params, self::$MAX_DAYS);
        $by = Params::enum($params, 'by', ['qty', 'revenue'], 'qty');
        $limit = Params::int($params, 'limit', 10, 1, 50);
        $scope = $context->shopScope($params);

        $rate = 'IFNULL(NULLIF(o.`conversion_rate`, 0), 1)';
        $sql = 'SELECT od.`product_id`, od.`product_attribute_id`,
                    MAX(od.`product_name`) AS product_name, MAX(od.`product_reference`) AS product_reference,
                    SUM(od.`product_quantity` - od.`product_quantity_refunded`) AS qty,
                    SUM(od.`total_price_tax_excl` / ' . $rate . ') AS revenue,
                    COUNT(DISTINCT o.`id_order`) AS orders
                FROM ' . ReadOnlyDb::t('order_detail') . ' od
                INNER JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = od.`id_order`
                WHERE o.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')
                  AND o.`valid` = 1
                  AND o.`date_add` >= \'' . pSQL($period['from']) . '\' AND o.`date_add` < \'' . pSQL($period['to']) . '\'
                GROUP BY od.`product_id`, od.`product_attribute_id`
                ORDER BY ' . ($by === 'revenue' ? 'revenue DESC, qty DESC' : 'qty DESC, revenue DESC') . ', od.`product_id` ASC
                LIMIT ' . (int) $limit;

        $items = [];
        foreach ($db->select($sql) as $row) {
            $items[] = [
                'id_product' => (int) $row['product_id'],
                'id_product_attribute' => (int) $row['product_attribute_id'],
                'name' => (string) $row['product_name'],
                'reference' => (string) $row['product_reference'],
                'qty' => (int) $row['qty'],
                'revenue' => round((float) $row['revenue'], 2),
                'orders' => (int) $row['orders'],
            ];
        }

        return [
            'shops' => $scope->describe(),
            'period' => $period,
            'currency' => $context->defaultCurrencyIso(),
            'by' => $by,
            'items' => $items,
        ];
    }
}
