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
 * Orders of a period, newest first, with an optional status group and a search over the order
 * reference / id and the customer's name or e-mail (zadanie, príloha B: orders.list).
 *
 * Paging by "before_id" (orders older than that id). Customer names and e-mails are personal
 * data passed through in transit only (zadanie D6).
 *
 * Module 0.8.0 (zadanie D21) adds statuses built only on PrestaShop flags and the shop settings:
 * awaiting_payment (unpaid, not shipped, not cancelled, not a ship-before-payment module, older
 * than awaiting_hours), stuck (waiting to ship and in its state longer than stuck_hours) and
 * payment_error (current state PS_OS_ERROR, with the customer's later valid order if any).
 */
class OrdersList implements QueryInterface
{
    /** @var int */
    private static $MAX_DAYS = 400;

    /** @var string[] */
    private static $STATUSES = ['all', 'unpaid', 'to_ship', 'shipped', 'cancelled', 'awaiting_payment', 'stuck', 'payment_error'];

    public function name()
    {
        return 'orders.list';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $period = Period::resolve($params + ['period' => 'last_30_days'], self::$MAX_DAYS);
        $status = Params::enum($params, 'status', self::$STATUSES, 'all');
        $limit = Params::int($params, 'limit', 20, 1, 50);
        $beforeId = Params::int($params, 'before_id', 0, 0, PHP_INT_MAX);
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);
        $search = $this->search($params);
        $stuckHours = Params::int($params, 'stuck_hours', 48, 1, 24 * 30);
        $awaitingHours = Params::int($params, 'awaiting_hours', 72, 1, 24 * 90);
        $shippedStates = Params::idList($params, 'shipped_states', 20);
        $codModules = Params::identifierList($params, 'cod_modules', ['ps_cashondelivery', 'cashondelivery'], 20);
        $quoted = [];
        foreach ($codModules as $module) {
            $quoted[] = '\'' . pSQL($module) . '\'';
        }
        $codList = empty($quoted) ? '\'\'' : implode(',', $quoted);
        $shippedList = empty($shippedStates) ? '0' : implode(',', $shippedStates);

        $where = [
            'o.`id_shop` IN (' . ShopScope::inList($scope->orderShopIds()) . ')',
            'o.`date_add` >= \'' . pSQL($period['from']) . '\'',
            'o.`date_add` < \'' . pSQL($period['to']) . '\'',
        ];
        $errorState = (int) \Configuration::get('PS_OS_ERROR');
        $cancelled = array_values(array_filter([
            (int) \Configuration::get('PS_OS_CANCELED'),
            $errorState,
        ]));
        $cancelledList = empty($cancelled) ? '0' : implode(',', $cancelled);
        // Open = still to be handled: not shipped, delivered, cancelled, failed or refunded.
        $refund = (int) \Configuration::get('PS_OS_REFUND');
        $open = 'os.`shipped` = 0 AND os.`delivery` = 0 AND o.`current_state` NOT IN (' . $cancelledList . ',' . $shippedList . ($refund > 0 ? ',' . $refund : '') . ')';
        $toShip = '((os.`logable` = 1 AND os.`paid` = 1) OR o.`module` IN (' . $codList . ')) AND ' . $open;
        switch ($status) {
            case 'unpaid':
                $where[] = 'os.`paid` = 0 AND ' . $open;
                break;
            case 'to_ship':
                $where[] = $toShip;
                break;
            case 'stuck':
                $where[] = $toShip . ' AND o.`date_upd` <= ' . $this->hoursAgo($stuckHours);
                break;
            case 'awaiting_payment':
                // Never paid: no paid state in the order history (a refunded or partly refunded
                // order was paid once, whatever its current state is called).
                $where[] = 'os.`paid` = 0 AND ' . $open . ' AND o.`module` NOT IN (' . $codList . ')'
                    . ' AND o.`date_add` <= ' . $this->hoursAgo($awaitingHours)
                    . ' AND NOT EXISTS (SELECT 1 FROM ' . ReadOnlyDb::t('order_history') . ' oh'
                    . ' INNER JOIN ' . ReadOnlyDb::t('order_state') . ' ohs ON ohs.`id_order_state` = oh.`id_order_state`'
                    . ' WHERE oh.`id_order` = o.`id_order` AND ohs.`paid` = 1)';
                break;
            case 'payment_error':
                $where[] = 'o.`current_state` = ' . (int) $errorState;
                break;
            case 'shipped':
                $where[] = '(os.`shipped` = 1 OR os.`delivery` = 1 OR o.`current_state` IN (' . $shippedList . '))';
                break;
            case 'cancelled':
                $where[] = 'o.`current_state` IN (' . $cancelledList . ')';
                break;
        }
        if ($search !== null) {
            $where[] = $search;
        }

        $joins = ' FROM ' . ReadOnlyDb::t('orders') . ' o
            LEFT JOIN ' . ReadOnlyDb::t('order_state') . ' os ON os.`id_order_state` = o.`current_state`
            LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = o.`id_customer`';
        $condition = ' WHERE ' . implode(' AND ', $where);
        $from = $joins . $condition;

        $rate = 'IFNULL(NULLIF(o.`conversion_rate`, 0), 1)';
        // A later valid order of the same customer: the failed payment was probably retried.
        $laterOrder = '(SELECT MIN(o2.`id_order`) FROM ' . ReadOnlyDb::t('orders') . ' o2'
            . ' WHERE o2.`id_customer` = o.`id_customer` AND o.`id_customer` > 0 AND o2.`id_order` > o.`id_order` AND o2.`valid` = 1)';
        $sums = $db->row('SELECT COUNT(*) AS n, SUM(o.`total_paid_tax_incl` / ' . $rate . ') AS value'
            . ($status === 'payment_error' ? ', SUM(CASE WHEN ' . $laterOrder . ' IS NULL THEN 1 ELSE 0 END) AS not_recovered' : '')
            . $from);
        $total = (int) $sums['n'];

        $rows = $db->select('SELECT o.`id_order`, o.`reference`, o.`date_add`, o.`date_upd`, o.`current_state`, o.`payment`,
                o.`total_paid_tax_incl`, o.`total_paid_tax_incl` / ' . $rate . ' AS value_tax_incl, cur.`iso_code` AS currency,
                os.`paid`, os.`shipped`, os.`delivery`, os.`color` AS state_color, osl.`name` AS state_name,
                c.`name` AS carrier_name, cu.`firstname`, cu.`lastname`, cu.`company`'
            . ($status === 'payment_error' ? ', ' . $laterOrder . ' AS later_order' : '')
            . $joins . '
            LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $langId . '
            LEFT JOIN ' . ReadOnlyDb::t('carrier') . ' c ON c.`id_carrier` = o.`id_carrier`
            LEFT JOIN ' . ReadOnlyDb::t('currency') . ' cur ON cur.`id_currency` = o.`id_currency`'
            . $condition
            . ($beforeId > 0 ? ' AND o.`id_order` < ' . (int) $beforeId : '') . '
            ORDER BY o.`id_order` DESC
            LIMIT ' . ($limit + 1));

        $more = count($rows) > $limit;
        $orders = [];
        $now = time();
        foreach (array_slice($rows, 0, $limit) as $row) {
            $changed = strtotime((string) $row['date_upd']);
            $orders[] = [
                'id_order' => (int) $row['id_order'],
                'reference' => (string) $row['reference'],
                'date_add' => (string) $row['date_add'],
                'state' => (string) $row['state_name'],
                'id_state' => (int) $row['current_state'],
                'state_color' => (string) $row['state_color'],
                'paid' => (bool) $row['paid'],
                'shipped' => (bool) $row['shipped'] || (bool) $row['delivery'] || in_array((int) $row['current_state'], $shippedStates, true),
                'cancelled' => in_array((int) $row['current_state'], $cancelled, true),
                'hours_in_state' => $changed === false ? null : max(0, (int) floor(($now - $changed) / 3600)),
                'total_paid_tax_incl' => round((float) $row['total_paid_tax_incl'], 2),
                'currency' => (string) $row['currency'],
                'value_tax_incl' => round((float) $row['value_tax_incl'], 2),
                'payment' => (string) $row['payment'],
                'carrier' => (string) $row['carrier_name'],
                'customer' => trim($row['firstname'] . ' ' . $row['lastname']),
                'company' => (string) $row['company'],
            ];
            if ($status === 'payment_error') {
                $orders[count($orders) - 1]['later_order'] = (int) $row['later_order'];
            }
        }

        return [
            'shops' => $scope->describe(),
            'period' => $period,
            'currency' => $context->defaultCurrencyIso(),
            'status' => $status,
            'total' => $total,
            'total_value' => round((float) $sums['value'], 2),
            'not_recovered' => $status === 'payment_error' ? (int) $sums['not_recovered'] : null,
            'thresholds' => ['stuck_hours' => $stuckHours, 'awaiting_hours' => $awaitingHours],
            'orders' => $orders,
            'more' => $more,
            'next_before_id' => $more && !empty($orders) ? $orders[count($orders) - 1]['id_order'] : null,
            'untrusted_fields' => ['orders.customer', 'orders.company'],
        ];
    }

    /**
     * Shop local time some hours ago, as a SQL literal (zadanie: no NOW() in SQL).
     *
     * @param int $hours
     *
     * @return string
     */
    private function hoursAgo($hours)
    {
        return '\'' . pSQL(date('Y-m-d H:i:s', time() - (int) $hours * 3600)) . '\'';
    }

    /**
     * Search condition for "q": order id, reference prefix, customer name or e-mail.
     *
     * @return string|null
     *
     * @throws InvalidParamsException
     */
    private function search(array $params)
    {
        if (!isset($params['q']) || $params['q'] === '') {
            return null;
        }
        $q = $params['q'];
        if (!is_string($q) || preg_match('/[\x00-\x1F\x7F]/', $q) || !preg_match('/^.{2,64}$/u', trim($q))) {
            throw new InvalidParamsException('Parameter "q" must be 2-64 printable characters.');
        }
        $q = trim($q);
        $like = pSQL(str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q));
        $or = [
            'o.`reference` LIKE \'' . $like . '%\'',
            'cu.`email` LIKE \'%' . $like . '%\'',
            'cu.`lastname` LIKE \'' . $like . '%\'',
            'cu.`firstname` LIKE \'' . $like . '%\'',
            'CONCAT(cu.`firstname`, \' \', cu.`lastname`) LIKE \'%' . $like . '%\'',
            'cu.`company` LIKE \'%' . $like . '%\'',
        ];
        if (ctype_digit($q)) {
            $or[] = 'o.`id_order` = ' . (int) $q;
        }

        return '(' . implode(' OR ', $or) . ')';
    }
}
