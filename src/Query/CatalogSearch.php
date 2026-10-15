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
use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Shop\ShopScope;

/**
 * Product lookup for "do we have it and how much does it cost" (zadanie D22).
 *
 * Matches a part of the name, the start of the reference or supplier reference, an exact EAN-13 or
 * UPC, or the product id; references and barcodes of combinations match too. Returns stock from
 * stock_available (total and per combination), the base price without and with tax (tax rules of
 * the shop's default country, no discounts), whether the product is active, and units sold in the
 * last 30 days. Wholesale prices are never returned.
 */
class CatalogSearch implements QueryInterface
{
    /** @var int */
    private static $MAX_COMBINATIONS = 20;

    /** @var int */
    private static $SOLD_DAYS = 30;

    public function name()
    {
        return 'catalog.search';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $q = $this->query($params);
        $limit = Params::int($params, 'limit', 20, 1, 30);
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);
        $shopId = (int) $scope->primaryShopId();

        $like = pSQL(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q));
        $exact = pSQL($q);
        // Barcodes are compared only for real codes (8+ characters): PrestaShop 1.6 stores "0" when empty.
        $barcode = strlen($q) >= 8;
        $match = [
            'pl.`name` LIKE \'%' . $like . '%\'',
            'p.`reference` LIKE \'' . $like . '%\'',
            'p.`supplier_reference` LIKE \'' . $like . '%\'',
            'EXISTS (SELECT 1 FROM ' . ReadOnlyDb::t('product_attribute') . ' pa2 WHERE pa2.`id_product` = p.`id_product`'
                . ' AND (pa2.`reference` LIKE \'' . $like . '%\' OR pa2.`supplier_reference` LIKE \'' . $like . '%\''
                . ($barcode ? ' OR pa2.`ean13` = \'' . $exact . '\' OR pa2.`upc` = \'' . $exact . '\'' : '') . '))',
        ];
        if ($barcode) {
            $match[] = 'p.`ean13` = \'' . $exact . '\'';
            $match[] = 'p.`upc` = \'' . $exact . '\'';
        }
        if (ctype_digit($q)) {
            $match[] = 'p.`id_product` = ' . (int) $q;
        }
        // Exact codes first, then active products, then by name.
        $exactFirst = '(p.`reference` = \'' . $exact . '\''
            . ($barcode ? ' OR p.`ean13` = \'' . $exact . '\'' : '')
            . (ctype_digit($q) ? ' OR p.`id_product` = ' . (int) $q : '') . ')';

        $rows = $db->select('SELECT p.`id_product`, pl.`name`, p.`reference`, p.`ean13`, p.`is_virtual`,
                ps.`active`, ps.`price`, ps.`id_tax_rules_group`
            FROM ' . ReadOnlyDb::t('product') . ' p
            INNER JOIN ' . ReadOnlyDb::t('product_shop') . ' ps ON ps.`id_product` = p.`id_product` AND ps.`id_shop` = ' . $shopId . '
            LEFT JOIN ' . ReadOnlyDb::t('product_lang') . ' pl ON pl.`id_product` = p.`id_product` AND pl.`id_lang` = ' . (int) $langId . ' AND pl.`id_shop` = ' . $shopId . '
            WHERE (' . implode(' OR ', $match) . ')
            ORDER BY ' . $exactFirst . ' DESC, ps.`active` DESC, pl.`name` ASC, p.`id_product` ASC
            LIMIT ' . ($limit + 1));
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        if (empty($rows)) {
            return ['shops' => $scope->describe(), 'q' => $q, 'currency' => $context->defaultCurrencyIso(), 'products' => [], 'more' => false];
        }

        $ids = [];
        $taxGroups = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id_product'];
            $taxGroups[] = (int) $row['id_tax_rules_group'];
        }
        $idList = ShopScope::inList($ids);
        $rates = $this->taxRates($db, $taxGroups);

        // Stock: the id_product_attribute = 0 row holds the product total.
        $stock = [];
        foreach ($db->select('SELECT sa.`id_product`, sa.`id_product_attribute`, sa.`quantity`
            FROM ' . ReadOnlyDb::t('stock_available') . ' sa
            WHERE sa.`id_product` IN (' . $idList . ') AND ' . $scope->stockCondition('sa')) as $row) {
            $stock[(int) $row['id_product'] . '-' . (int) $row['id_product_attribute']] = (int) $row['quantity'];
        }

        $since = Period::daysAgo(self::$SOLD_DAYS);
        $sold = [];
        foreach ($db->select('SELECT od.`product_id`, od.`product_attribute_id`, SUM(od.`product_quantity`) AS qty
            FROM ' . ReadOnlyDb::t('order_detail') . ' od
            INNER JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = od.`id_order`
            WHERE od.`product_id` IN (' . $idList . ')
              AND o.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')
              AND o.`valid` = 1 AND o.`date_add` >= \'' . pSQL($since) . '\'
            GROUP BY od.`product_id`, od.`product_attribute_id`') as $row) {
            $sold[(int) $row['product_id'] . '-' . (int) $row['product_attribute_id']] = (int) $row['qty'];
        }

        $combinationRows = $db->select('SELECT pa.`id_product_attribute`, pa.`id_product`, pa.`reference`, pa.`ean13`, pas.`price`
            FROM ' . ReadOnlyDb::t('product_attribute') . ' pa
            INNER JOIN ' . ReadOnlyDb::t('product_attribute_shop') . ' pas ON pas.`id_product_attribute` = pa.`id_product_attribute` AND pas.`id_shop` = ' . $shopId . '
            WHERE pa.`id_product` IN (' . $idList . ')
            ORDER BY pa.`id_product` ASC, pa.`id_product_attribute` ASC
            LIMIT 2000');
        $attributeIds = [];
        foreach ($combinationRows as $row) {
            $attributeIds[] = (int) $row['id_product_attribute'];
        }
        $labels = Combinations::labels($db, $attributeIds, $langId);

        $products = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product'];
            $rate = isset($rates[(int) $row['id_tax_rules_group']]) ? $rates[(int) $row['id_tax_rules_group']] : 0.0;
            $base = (float) $row['price'];
            $products[$id] = [
                'id_product' => $id,
                'name' => (string) $row['name'],
                'reference' => (string) $row['reference'],
                'ean13' => $this->code($row['ean13']),
                'active' => (bool) $row['active'],
                'virtual' => (bool) $row['is_virtual'],
                'price_tax_excl' => round($base, 2),
                'price_tax_incl' => round($base * (1 + $rate / 100), 2),
                'tax_rate' => $rate,
                'quantity' => isset($stock[$id . '-0']) ? $stock[$id . '-0'] : 0,
                'sold_30d' => 0,
                'combinations' => [],
                'combinations_total' => 0,
            ];
        }
        foreach ($sold as $key => $qty) {
            $id = (int) $key;
            if (isset($products[$id])) {
                $products[$id]['sold_30d'] += $qty;
            }
        }
        foreach ($combinationRows as $row) {
            $id = (int) $row['id_product'];
            $attributeId = (int) $row['id_product_attribute'];
            if (!isset($products[$id])) {
                continue;
            }
            ++$products[$id]['combinations_total'];
            if (count($products[$id]['combinations']) >= self::$MAX_COMBINATIONS) {
                continue;
            }
            $price = $products[$id]['price_tax_excl'] + (float) $row['price'];
            $products[$id]['combinations'][] = [
                'id_product_attribute' => $attributeId,
                'name' => isset($labels[$attributeId]) ? $labels[$attributeId] : '',
                'reference' => (string) $row['reference'],
                'ean13' => $this->code($row['ean13']),
                'quantity' => isset($stock[$id . '-' . $attributeId]) ? $stock[$id . '-' . $attributeId] : 0,
                'price_tax_incl' => round($price * (1 + $products[$id]['tax_rate'] / 100), 2),
                'sold_30d' => isset($sold[$id . '-' . $attributeId]) ? $sold[$id . '-' . $attributeId] : 0,
            ];
        }
        foreach ($products as $id => $product) {
            $products[$id]['combinations_truncated'] = $product['combinations_total'] > count($product['combinations']);
        }

        return [
            'shops' => $scope->describe(),
            'q' => $q,
            'currency' => $context->defaultCurrencyIso(),
            'sold_days' => self::$SOLD_DAYS,
            'products' => array_values($products),
            'more' => $more,
            'untrusted_fields' => ['products.name', 'products.combinations.name'],
        ];
    }

    /**
     * Barcodes are stored as "0" or "" when missing (PrestaShop 1.6 uses "0").
     *
     * @param string|null $value
     *
     * @return string
     */
    private function code($value)
    {
        $value = trim((string) $value);

        return $value === '0' ? '' : $value;
    }

    /**
     * @return string
     *
     * @throws InvalidParamsException
     */
    private function query(array $params)
    {
        $q = isset($params['q']) ? $params['q'] : null;
        // A product id may be a single digit; text needs at least 2 characters.
        if (!is_string($q) || preg_match('/[\x00-\x1F\x7F]/', $q)
            || !(preg_match('/^.{2,64}$/u', trim($q)) || preg_match('/^[0-9]{1,10}$/', trim($q)))) {
            throw new InvalidParamsException('Parameter "q" must be 2-64 printable characters or a product id.');
        }

        return trim($q);
    }

    /**
     * Tax rate (%) of each tax rules group for the shop's default country; 0 when taxes are off.
     *
     * @param int[] $groups
     *
     * @return array id_tax_rules_group => rate
     */
    private function taxRates(ReadOnlyDb $db, array $groups)
    {
        $groups = array_values(array_unique(array_filter($groups)));
        $country = (int) \Configuration::get('PS_COUNTRY_DEFAULT');
        if (empty($groups) || $country <= 0 || !\Configuration::get('PS_TAX')) {
            return [];
        }
        $rates = [];
        foreach ($db->select('SELECT tr.`id_tax_rules_group`, MAX(t.`rate`) AS rate
            FROM ' . ReadOnlyDb::t('tax_rule') . ' tr
            INNER JOIN ' . ReadOnlyDb::t('tax') . ' t ON t.`id_tax` = tr.`id_tax` AND t.`active` = 1 AND t.`deleted` = 0
            WHERE tr.`id_tax_rules_group` IN (' . ShopScope::inList($groups) . ')
              AND tr.`id_country` = ' . $country . ' AND tr.`id_state` = 0
            GROUP BY tr.`id_tax_rules_group`') as $row) {
            $rates[(int) $row['id_tax_rules_group']] = (float) $row['rate'];
        }

        return $rates;
    }
}
