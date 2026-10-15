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
 * Readable labels of product combinations ("M, Red"), shared by the stock and catalog queries.
 */
class Combinations
{
    /**
     * @param int[] $ids id_product_attribute values
     * @param int $langId
     *
     * @return array id_product_attribute => attribute names joined by ", "
     */
    public static function labels(ReadOnlyDb $db, array $ids, $langId)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return [];
        }
        $result = $db->select(
            'SELECT pac.`id_product_attribute`, GROUP_CONCAT(al.`name` ORDER BY al.`name` SEPARATOR \', \') AS label
             FROM ' . ReadOnlyDb::t('product_attribute_combination') . ' pac
             INNER JOIN ' . ReadOnlyDb::t('attribute_lang') . ' al ON al.`id_attribute` = pac.`id_attribute` AND al.`id_lang` = ' . (int) $langId . '
             WHERE pac.`id_product_attribute` IN (' . ShopScope::inList($ids) . ')
             GROUP BY pac.`id_product_attribute`'
        );
        $names = [];
        foreach ($result as $row) {
            $names[(int) $row['id_product_attribute']] = (string) $row['label'];
        }

        return $names;
    }
}
