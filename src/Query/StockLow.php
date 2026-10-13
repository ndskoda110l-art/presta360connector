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
 * Active products at or below a stock threshold, with sales velocity (zadanie: "Čo dochádza").
 *
 * Stock comes only from stock_available (product_attribute.quantity was dropped in 8.0).
 * For products with combinations the product-level row (id_product_attribute = 0) is skipped
 * to avoid double counting. Works per shop; with id_shop = "all" the default shop is used.
 */
class StockLow implements QueryInterface
{
    public function name()
    {
        return 'stock.low';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $threshold = Params::int($params, 'threshold', 5, 0, 100000);
        $useProductThreshold = Params::bool($params, 'use_product_threshold', true);
        $soldDays = Params::int($params, 'sold_days', 14, 1, 90);
        $onlySold = Params::bool($params, 'only_sold', false);
        $includeVirtual = Params::bool($params, 'include_virtual', false);
        $limit = Params::int($params, 'limit', 50, 1, 100);
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);
        $probe = new Probe($db);

        $shopId = $scope->primaryShopId();
        $since = Period::daysAgo($soldDays);

        $thresholdExpr = (string) (int) $threshold;
        $productThreshold = $useProductThreshold && $probe->hasColumn('product_shop', 'low_stock_threshold');
        if ($productThreshold) {
            $thresholdExpr = 'IFNULL(ps.`low_stock_threshold`, ' . (int) $threshold . ')';
        }

        $sold = 'COALESCE(v.`qty`, 0)';
        $sql = 'SELECT sa.`id_product`, sa.`id_product_attribute`, sa.`quantity`,
                    ' . $thresholdExpr . ' AS threshold,
                    pl.`name`,
                    COALESCE(NULLIF(pa.`reference`, \'\'), p.`reference`) AS reference,
                    ' . $sold . ' AS sold
                FROM ' . ReadOnlyDb::t('stock_available') . ' sa
                INNER JOIN ' . ReadOnlyDb::t('product') . ' p ON p.`id_product` = sa.`id_product`
                INNER JOIN ' . ReadOnlyDb::t('product_shop') . ' ps ON ps.`id_product` = sa.`id_product` AND ps.`id_shop` = ' . (int) $shopId . ' AND ps.`active` = 1
                LEFT JOIN ' . ReadOnlyDb::t('product_lang') . ' pl ON pl.`id_product` = sa.`id_product` AND pl.`id_lang` = ' . (int) $langId . ' AND pl.`id_shop` = ' . (int) $shopId . '
                LEFT JOIN ' . ReadOnlyDb::t('product_attribute') . ' pa ON pa.`id_product_attribute` = sa.`id_product_attribute`
                LEFT JOIN (
                    SELECT od.`product_id`, od.`product_attribute_id`, SUM(od.`product_quantity`) AS qty
                    FROM ' . ReadOnlyDb::t('order_detail') . ' od
                    INNER JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = od.`id_order`
                    WHERE o.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')
                      AND o.`valid` = 1 AND o.`date_add` >= \'' . pSQL($since) . '\'
                    GROUP BY od.`product_id`, od.`product_attribute_id`
                ) v ON v.`product_id` = sa.`id_product` AND v.`product_attribute_id` = sa.`id_product_attribute`
                WHERE ' . $scope->stockCondition('sa') . '
                  AND (sa.`id_product_attribute` > 0 OR ps.`cache_default_attribute` = 0)
                  AND sa.`quantity` <= ' . $thresholdExpr
            . ($includeVirtual ? '' : ' AND p.`is_virtual` = 0')
            . ($onlySold ? ' AND v.`qty` > 0' : '') . '
                ORDER BY (' . $sold . ' > 0) DESC, sa.`quantity` / GREATEST(' . $sold . ', 1) ASC, sa.`quantity` ASC, sa.`id_product` ASC
                LIMIT ' . ($limit + 1);

        $rows = $db->select($sql);
        $truncated = count($rows) > $limit;
        if ($truncated) {
            $rows = array_slice($rows, 0, $limit);
        }

        $combinationNames = $this->combinationNames($db, $rows, $langId);

        $items = [];
        $outOfStock = 0;
        foreach ($rows as $row) {
            $quantity = (int) $row['quantity'];
            $soldQty = (int) $row['sold'];
            $attributeId = (int) $row['id_product_attribute'];
            if ($quantity <= 0) {
                ++$outOfStock;
            }
            $items[] = [
                'id_product' => (int) $row['id_product'],
                'id_product_attribute' => $attributeId,
                'name' => (string) $row['name'],
                'combination' => isset($combinationNames[$attributeId]) ? $combinationNames[$attributeId] : '',
                'reference' => (string) $row['reference'],
                'quantity' => $quantity,
                'threshold' => (int) $row['threshold'],
                'sold' => $soldQty,
                'days_of_stock' => $soldQty > 0 ? round(max(0, $quantity) / ($soldQty / $soldDays), 1) : null,
            ];
        }

        return [
            'id_shop' => $shopId,
            'sold_period' => ['days' => $soldDays, 'since' => $since],
            'threshold' => ['default' => $threshold, 'per_product' => $productThreshold],
            'items' => $items,
            'items_listed' => count($items),
            'out_of_stock_listed' => $outOfStock,
            'truncated' => $truncated,
        ];
    }

    /**
     * @return array id_product_attribute => "Size: M, Color: Red"-like label (attribute names only)
     */
    private function combinationNames(ReadOnlyDb $db, array $rows, $langId)
    {
        $ids = [];
        foreach ($rows as $row) {
            if ((int) $row['id_product_attribute'] > 0) {
                $ids[] = (int) $row['id_product_attribute'];
            }
        }
        if (empty($ids)) {
            return [];
        }

        $result = $db->select(
            'SELECT pac.`id_product_attribute`, GROUP_CONCAT(al.`name` ORDER BY al.`name` SEPARATOR \', \') AS label
             FROM ' . ReadOnlyDb::t('product_attribute_combination') . ' pac
             INNER JOIN ' . ReadOnlyDb::t('attribute_lang') . ' al ON al.`id_attribute` = pac.`id_attribute` AND al.`id_lang` = ' . (int) $langId . '
             WHERE pac.`id_product_attribute` IN (' . ShopScope::inList(array_unique($ids)) . ')
             GROUP BY pac.`id_product_attribute`'
        );

        $names = [];
        foreach ($result as $row) {
            $names[(int) $row['id_product_attribute']] = (string) $row['label'];
        }

        return $names;
    }
}
