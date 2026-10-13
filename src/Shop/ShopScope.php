<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Shop;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Presta360\Connector\Api\InvalidParamsException;
use Presta360\Connector\Db\ReadOnlyDb;

/**
 * Which shops a query covers, mirroring Shop::addSqlRestriction():
 * orders/carts widen to the whole shop group when the group shares orders,
 * stock rows live on (id_shop = 0, id_shop_group = G) when the group shares stock.
 * Read with plain SQL so it behaves the same on 1.6 to 9.x.
 */
class ShopScope
{
    /** @var array id_shop => row */
    private $shops;

    /** @var int[] */
    private $selected;

    /** @var bool */
    private $all;

    private function __construct(array $shops, array $selected, $all)
    {
        $this->shops = $shops;
        $this->selected = $selected;
        $this->all = $all;
    }

    /**
     * @param ReadOnlyDb $db
     * @param int|string|null $selector null = shop of the request, "all", or id_shop
     * @param int $requestShopId shop resolved by PrestaShop from the request host
     *
     * @return self
     *
     * @throws InvalidParamsException
     */
    public static function resolve(ReadOnlyDb $db, $selector, $requestShopId)
    {
        $shops = self::loadShops($db);

        if ($selector === 'all') {
            return new self($shops, array_keys($shops), true);
        }

        if ($selector === null) {
            $selector = (int) $requestShopId;
            if (!isset($shops[$selector])) {
                $selector = (int) \Configuration::get('PS_SHOP_DEFAULT');
            }
        }

        if (!isset($shops[(int) $selector])) {
            throw new InvalidParamsException('Unknown "id_shop".');
        }

        return new self($shops, [(int) $selector], false);
    }

    /**
     * @return array id_shop => ['id_shop', 'id_shop_group', 'name', 'active', 'share_customer', 'share_order', 'share_stock']
     */
    public static function loadShops(ReadOnlyDb $db)
    {
        $rows = $db->select(
            'SELECT s.`id_shop`, s.`id_shop_group`, s.`name`, s.`active`,
                    sg.`share_customer`, sg.`share_order`, sg.`share_stock`
             FROM ' . ReadOnlyDb::t('shop') . ' s
             INNER JOIN ' . ReadOnlyDb::t('shop_group') . ' sg ON sg.`id_shop_group` = s.`id_shop_group`
             WHERE s.`deleted` = 0
             ORDER BY s.`id_shop` ASC'
        );

        $shops = [];
        foreach ($rows as $row) {
            $shops[(int) $row['id_shop']] = [
                'id_shop' => (int) $row['id_shop'],
                'id_shop_group' => (int) $row['id_shop_group'],
                'name' => (string) $row['name'],
                'active' => (bool) $row['active'],
                'share_customer' => (bool) $row['share_customer'],
                'share_order' => (bool) $row['share_order'],
                'share_stock' => (bool) $row['share_stock'],
            ];
        }

        return $shops;
    }

    /**
     * @return bool
     */
    public function isAll()
    {
        return $this->all;
    }

    /**
     * @return int[] shops explicitly selected
     */
    public function shopIds()
    {
        return $this->selected;
    }

    /**
     * @return int first selected shop (stock and catalog queries work per shop)
     */
    public function primaryShopId()
    {
        return (int) $this->selected[0];
    }

    /**
     * @return int
     */
    public function primaryShopGroupId()
    {
        return $this->shops[$this->primaryShopId()]['id_shop_group'];
    }

    /**
     * @return int[] shops whose orders and carts belong to the selection
     */
    public function orderShopIds()
    {
        $ids = [];
        foreach ($this->selected as $id) {
            $group = $this->shops[$id]['id_shop_group'];
            if ($this->shops[$id]['share_order']) {
                foreach ($this->shops as $shop) {
                    if ($shop['id_shop_group'] === $group) {
                        $ids[] = $shop['id_shop'];
                    }
                }
            } else {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param int[] $ids
     *
     * @return string "1,2,3" for IN (...)
     */
    public static function inList(array $ids)
    {
        return implode(',', array_map('intval', $ids));
    }

    /**
     * @param string $alias alias of the stock_available table
     *
     * @return string SQL condition for the primary shop
     */
    public function stockCondition($alias)
    {
        $id = $this->primaryShopId();
        if ($this->shops[$id]['share_stock']) {
            return $alias . '.`id_shop` = 0 AND ' . $alias . '.`id_shop_group` = ' . (int) $this->shops[$id]['id_shop_group'];
        }

        return $alias . '.`id_shop` = ' . (int) $id . ' AND ' . $alias . '.`id_shop_group` = 0';
    }

    /**
     * @return array description for responses
     */
    public function describe()
    {
        return ['all' => $this->all, 'id_shop' => $this->selected, 'order_shops' => $this->orderShopIds()];
    }
}
