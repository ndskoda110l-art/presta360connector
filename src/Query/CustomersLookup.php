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
 * Customers (zadanie, príloha B: customers.lookup).
 *
 * - "id_customer": one profile with KPIs, addresses and the latest orders;
 * - "q": search by name, company, e-mail or id; without "q" the newest customers.
 *
 * KPIs count valid orders only; amounts in the default currency. Personal data is passed through
 * in transit only (zadanie D6); never the password hash or secure key.
 */
class CustomersLookup implements QueryInterface
{
    /** @var int */
    private static $ORDERS_IN_PROFILE = 10;

    /** @var int */
    private static $MAX_ADDRESSES = 5;

    public function name()
    {
        return 'customers.lookup';
    }

    public function run(array $params, QueryContext $context)
    {
        $scope = $context->shopScope($params);
        $idCustomer = Params::int($params, 'id_customer', 0, 0, PHP_INT_MAX);
        if ($idCustomer > 0) {
            return $this->profile($context, $scope, $idCustomer, $context->langId($params));
        }

        return $this->search($context, $scope, $params);
    }

    /**
     * @return string SQL fragments with the order KPIs of customer alias "c"
     */
    private function kpiColumns($shops)
    {
        $rate = 'IFNULL(NULLIF(o.`conversion_rate`, 0), 1)';
        $base = ' FROM ' . ReadOnlyDb::t('orders') . ' o WHERE o.`id_customer` = c.`id_customer` AND o.`valid` = 1 AND o.`id_shop` IN (' . $shops . ')';

        return '(SELECT COUNT(*)' . $base . ') AS orders,
            (SELECT SUM(o.`total_paid_tax_incl` / ' . $rate . ')' . $base . ') AS spent,
            (SELECT MAX(o.`date_add`)' . $base . ') AS last_order,
            (SELECT MIN(o.`date_add`)' . $base . ') AS first_order';
    }

    private function search(QueryContext $context, ShopScope $scope, array $params)
    {
        $db = $context->db();
        $limit = Params::int($params, 'limit', 20, 1, 50);
        $shops = ShopScope::inList($scope->orderShopIds());
        $where = ['c.`deleted` = 0', 'c.`id_shop` IN (' . $shops . ')'];

        $q = null;
        if (isset($params['q']) && $params['q'] !== '') {
            $q = $params['q'];
            if (!is_string($q) || preg_match('/[\x00-\x1F\x7F]/', $q) || !preg_match('/^.{2,64}$/u', trim($q))) {
                throw new InvalidParamsException('Parameter "q" must be 2-64 printable characters.');
            }
            $q = trim($q);
            $like = pSQL(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q));
            $or = [
                'c.`email` LIKE \'%' . $like . '%\'',
                'c.`lastname` LIKE \'' . $like . '%\'',
                'c.`firstname` LIKE \'' . $like . '%\'',
                'CONCAT(c.`firstname`, \' \', c.`lastname`) LIKE \'%' . $like . '%\'',
                'c.`company` LIKE \'%' . $like . '%\'',
            ];
            if (ctype_digit($q)) {
                $or[] = 'c.`id_customer` = ' . (int) $q;
            }
            $where[] = '(' . implode(' OR ', $or) . ')';
        }
        $whereSql = ' FROM ' . ReadOnlyDb::t('customer') . ' c WHERE ' . implode(' AND ', $where);

        $total = (int) $db->value('SELECT COUNT(*)' . $whereSql);
        $rows = $db->select('SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`, c.`date_add`,
                c.`is_guest`, c.`active`, c.`newsletter`, ' . $this->kpiColumns($shops)
            . $whereSql . '
            ORDER BY c.`id_customer` DESC
            LIMIT ' . (int) $limit);

        $customers = [];
        foreach ($rows as $row) {
            $customers[] = $this->summary($row);
        }

        return [
            'shops' => $scope->describe(),
            'currency' => $context->defaultCurrencyIso(),
            'query' => $q,
            'total' => $total,
            'customers' => $customers,
            'untrusted_fields' => ['customers.name', 'customers.email', 'customers.company'],
        ];
    }

    private function summary(array $row)
    {
        $orders = (int) $row['orders'];
        $spent = round((float) $row['spent'], 2);

        return [
            'id_customer' => (int) $row['id_customer'],
            'name' => trim($row['firstname'] . ' ' . $row['lastname']),
            'email' => (string) $row['email'],
            'company' => (string) $row['company'],
            'since' => (string) $row['date_add'],
            'guest' => (bool) $row['is_guest'],
            'active' => (bool) $row['active'],
            'newsletter' => (bool) $row['newsletter'],
            'orders' => $orders,
            'spent' => $spent,
            'average' => $orders > 0 ? round($spent / $orders, 2) : 0.0,
            'first_order' => (string) $row['first_order'],
            'last_order' => (string) $row['last_order'],
        ];
    }

    private function profile(QueryContext $context, ShopScope $scope, $idCustomer, $langId)
    {
        $db = $context->db();
        $shops = ShopScope::inList($scope->orderShopIds());
        $row = $db->row('SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`, c.`date_add`,
                c.`is_guest`, c.`active`, c.`newsletter`, gl.`name` AS group_name, ' . $this->kpiColumns($shops) . '
            FROM ' . ReadOnlyDb::t('customer') . ' c
            LEFT JOIN ' . ReadOnlyDb::t('group_lang') . ' gl ON gl.`id_group` = c.`id_default_group` AND gl.`id_lang` = ' . (int) $langId . '
            WHERE c.`id_customer` = ' . (int) $idCustomer . ' AND c.`deleted` = 0 AND c.`id_shop` IN (' . $shops . ')');
        if ($row === null) {
            return ['shops' => $scope->describe(), 'found' => false];
        }

        $addresses = [];
        foreach ($db->select('SELECT a.`firstname`, a.`lastname`, a.`company`, a.`vat_number`, a.`address1`, a.`address2`,
                a.`postcode`, a.`city`, a.`phone`, a.`phone_mobile`, cl.`name` AS country
            FROM ' . ReadOnlyDb::t('address') . ' a
            LEFT JOIN ' . ReadOnlyDb::t('country_lang') . ' cl ON cl.`id_country` = a.`id_country` AND cl.`id_lang` = ' . (int) $langId . '
            WHERE a.`id_customer` = ' . (int) $idCustomer . ' AND a.`deleted` = 0
            ORDER BY a.`date_upd` DESC
            LIMIT ' . self::$MAX_ADDRESSES) as $a) {
            $addresses[] = [
                'name' => trim($a['firstname'] . ' ' . $a['lastname']),
                'company' => (string) $a['company'],
                'vat_number' => (string) $a['vat_number'],
                'street' => trim($a['address1'] . ' ' . $a['address2']),
                'postcode' => (string) $a['postcode'],
                'city' => (string) $a['city'],
                'country' => (string) $a['country'],
                'phone' => (string) ($a['phone_mobile'] !== '' ? $a['phone_mobile'] : $a['phone']),
            ];
        }

        $orders = [];
        foreach ($db->select('SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`total_paid_tax_incl`, o.`valid`,
                cur.`iso_code` AS currency, osl.`name` AS state_name, os.`color` AS state_color
            FROM ' . ReadOnlyDb::t('orders') . ' o
            LEFT JOIN ' . ReadOnlyDb::t('order_state') . ' os ON os.`id_order_state` = o.`current_state`
            LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $langId . '
            LEFT JOIN ' . ReadOnlyDb::t('currency') . ' cur ON cur.`id_currency` = o.`id_currency`
            WHERE o.`id_customer` = ' . (int) $idCustomer . ' AND o.`id_shop` IN (' . $shops . ')
            ORDER BY o.`id_order` DESC
            LIMIT ' . self::$ORDERS_IN_PROFILE) as $o) {
            $orders[] = [
                'id_order' => (int) $o['id_order'],
                'reference' => (string) $o['reference'],
                'date_add' => (string) $o['date_add'],
                'state' => (string) $o['state_name'],
                'state_color' => (string) $o['state_color'],
                'valid' => (bool) $o['valid'],
                'total_paid_tax_incl' => round((float) $o['total_paid_tax_incl'], 2),
                'currency' => (string) $o['currency'],
            ];
        }

        return [
            'shops' => $scope->describe(),
            'found' => true,
            'currency' => $context->defaultCurrencyIso(),
            'customer' => $this->summary($row) + ['group' => (string) $row['group_name']],
            'addresses' => $addresses,
            'orders' => $orders,
            'untrusted_fields' => ['customer', 'addresses'],
        ];
    }
}
