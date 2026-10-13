<?php

/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Capabilities;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Presta360\Connector\Db\ReadOnlyDb;

/**
 * Detects what this particular database offers. Upgraded shops do not always match the
 * schema of their version, so optional columns are introspected instead of keyed on _PS_VERSION_.
 */
class Probe
{
    /** Optional columns used by queries: "table.column" (unprefixed). */
    private static $OPTIONAL_COLUMNS = 'product_shop.low_stock_threshold,stock_available.physical_quantity,stock_available.reserved_quantity,stock_available.location,orders.note,order_detail.total_refunded_tax_incl,customer_message.id_product,product.product_type';

    /** Tables whose size and collation are reported. */
    private static $REPORTED_TABLES = 'orders,order_detail,cart,customer,customer_thread,stock_available,connections';

    /** @var ReadOnlyDb */
    private $db;

    /** @var array|null */
    private $columns;

    public function __construct(ReadOnlyDb $db)
    {
        $this->db = $db;
    }

    /**
     * @param string $table unprefixed
     * @param string $column
     *
     * @return bool
     */
    public function hasColumn($table, $column)
    {
        $columns = $this->optionalColumns();

        return !empty($columns[$table . '.' . $column]);
    }

    /**
     * @return array "table.column" => bool
     */
    public function optionalColumns()
    {
        if ($this->columns !== null) {
            return $this->columns;
        }

        $wanted = explode(',', self::$OPTIONAL_COLUMNS);
        $tables = [];
        $names = [];
        foreach ($wanted as $item) {
            list($table, $column) = explode('.', $item);
            $tables[] = '\'' . pSQL(_DB_PREFIX_ . $table) . '\'';
            $names[] = '\'' . pSQL($column) . '\'';
        }

        $rows = $this->db->select(
            'SELECT `TABLE_NAME` AS t, `COLUMN_NAME` AS c FROM `information_schema`.`COLUMNS`
             WHERE `TABLE_SCHEMA` = DATABASE()
               AND `TABLE_NAME` IN (' . implode(',', array_unique($tables)) . ')
               AND `COLUMN_NAME` IN (' . implode(',', array_unique($names)) . ')'
        );

        $found = [];
        foreach ($rows as $row) {
            $found[substr((string) $row['t'], strlen(_DB_PREFIX_)) . '.' . (string) $row['c']] = true;
        }

        $this->columns = [];
        foreach ($wanted as $item) {
            $this->columns[$item] = isset($found[$item]);
        }

        return $this->columns;
    }

    /**
     * @return array table => ['rows_estimate' => int, 'collation' => string]
     */
    public function tables()
    {
        $names = [];
        foreach (explode(',', self::$REPORTED_TABLES) as $table) {
            $names[] = '\'' . pSQL(_DB_PREFIX_ . $table) . '\'';
        }

        $rows = $this->db->select(
            'SELECT `TABLE_NAME` AS t, `TABLE_ROWS` AS r, `TABLE_COLLATION` AS c FROM `information_schema`.`TABLES`
             WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` IN (' . implode(',', $names) . ')'
        );

        $out = [];
        foreach ($rows as $row) {
            $out[substr((string) $row['t'], strlen(_DB_PREFIX_))] = [
                'rows_estimate' => (int) $row['r'],
                'collation' => (string) $row['c'],
            ];
        }

        return $out;
    }

    /**
     * @param string[] $names
     *
     * @return array name => bool (installed and active)
     */
    public function modules(array $names)
    {
        $quoted = [];
        foreach ($names as $name) {
            $quoted[] = '\'' . pSQL($name) . '\'';
        }
        $rows = $this->db->select(
            'SELECT `name`, `active` FROM ' . ReadOnlyDb::t('module') . ' WHERE `name` IN (' . implode(',', $quoted) . ')'
        );

        $out = array_fill_keys($names, false);
        foreach ($rows as $row) {
            $out[(string) $row['name']] = (bool) $row['active'];
        }

        return $out;
    }

    /**
     * Visits are recorded by the statsdata module into `connections`.
     *
     * @return string|null local datetime of the latest recorded visit
     */
    public function lastConnection()
    {
        return $this->db->value('SELECT MAX(`date_add`) FROM ' . ReadOnlyDb::t('connections'));
    }

    /**
     * Share of order lines in the last $days days that carry a purchase price (needed for margin).
     *
     * @param string $since local datetime
     *
     * @return float|null 0..1, null when there were no orders
     */
    public function purchasePriceCoverage($since)
    {
        $row = $this->db->row(
            'SELECT COUNT(*) AS lines_total, SUM(CASE WHEN od.`purchase_supplier_price` > 0 THEN 1 ELSE 0 END) AS lines_priced
             FROM ' . ReadOnlyDb::t('order_detail') . ' od
             INNER JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = od.`id_order`
             WHERE o.`date_add` >= \'' . pSQL($since) . '\''
        );
        if ($row === null || (int) $row['lines_total'] === 0) {
            return null;
        }

        return round((int) $row['lines_priced'] / (int) $row['lines_total'], 3);
    }
}
