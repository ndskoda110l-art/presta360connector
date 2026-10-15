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

use Presta360\Connector\Compat\Text;
use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Shop\ShopScope;

/**
 * Customer service threads waiting for the shop (zadanie: "Kto čaká na odpoveď").
 *
 * status = "unanswered" (default): open/pending threads whose latest non-private message is
 * from the customer (id_employee = 0). status = "open": all open/pending threads.
 * customer_thread has no index on date_upd/status, so the scan is bounded by max_age_days.
 * Message text is untrusted: HTML stripped and truncated.
 *
 * sort = "oldest" (default, longest waiting first) or "newest" (latest message first, paged with
 * before_id = last_message_id of the previous page; module 0.7.0, zadanie D20).
 */
class ServiceInbox implements QueryInterface
{
    private static $MESSAGE_MAX_CHARS = 300;

    public function name()
    {
        return 'service.inbox';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $status = Params::enum($params, 'status', ['unanswered', 'open'], 'unanswered');
        $olderThanHours = Params::int($params, 'older_than_hours', 0, 0, 24 * 365);
        $maxAgeDays = Params::int($params, 'max_age_days', 60, 1, 365);
        $limit = Params::int($params, 'limit', 20, 1, 50);
        $sort = Params::enum($params, 'sort', ['oldest', 'newest'], 'oldest');
        $beforeId = Params::int($params, 'before_id', 0, 0, PHP_INT_MAX);
        $scope = $context->shopScope($params);

        $since = Period::daysAgo($maxAgeDays);
        $where = ' WHERE ct.`id_shop` IN (' . ShopScope::inList($scope->shopIds()) . ')
              AND ct.`status` IN (\'open\', \'pending1\', \'pending2\')
              AND ct.`date_upd` >= \'' . pSQL($since) . '\'';
        if ($status === 'unanswered') {
            $where .= ' AND cm.`id_employee` = 0';
        }
        if ($olderThanHours > 0) {
            $where .= ' AND cm.`date_add` <= \'' . pSQL(date('Y-m-d H:i:s', time() - $olderThanHours * 3600)) . '\'';
        }

        $from = ' FROM ' . ReadOnlyDb::t('customer_thread') . ' ct
            INNER JOIN ' . ReadOnlyDb::t('customer_message') . ' cm ON cm.`id_customer_message` = (
                SELECT MAX(cm2.`id_customer_message`) FROM ' . ReadOnlyDb::t('customer_message') . ' cm2
                WHERE cm2.`id_customer_thread` = ct.`id_customer_thread` AND cm2.`private` = 0
            )';

        $count = (int) $db->value('SELECT COUNT(*)' . $from . $where);
        $page = $sort === 'newest' && $beforeId > 0 ? ' AND cm.`id_customer_message` < ' . (int) $beforeId : '';

        $rows = $db->select(
            'SELECT ct.`id_customer_thread`, ct.`id_customer`, ct.`id_order`, ct.`email`, ct.`status`, ct.`date_add`,
                    cm.`id_customer_message`, cm.`message`, cm.`date_add` AS last_message_at, cm.`id_employee`,
                    cu.`firstname`, cu.`lastname`, o.`reference` AS order_reference'
            . $from . '
            LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = ct.`id_customer`
            LEFT JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = ct.`id_order`'
            . $where . $page . '
            ORDER BY ' . ($sort === 'newest' ? 'cm.`id_customer_message` DESC' : 'cm.`date_add` ASC') . '
            LIMIT ' . ((int) $limit + 1)
        );
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);

        $now = time();
        $threads = [];
        foreach ($rows as $row) {
            $last = strtotime((string) $row['last_message_at']);
            $threads[] = [
                'id_customer_thread' => (int) $row['id_customer_thread'],
                'status' => (string) $row['status'],
                'opened_at' => (string) $row['date_add'],
                'last_message_id' => (int) $row['id_customer_message'],
                'last_message_at' => (string) $row['last_message_at'],
                'waiting_hours' => $last === false ? null : max(0, (int) floor(($now - $last) / 3600)),
                'last_from' => (int) $row['id_employee'] > 0 ? 'employee' : 'customer',
                'id_customer' => (int) $row['id_customer'],
                'customer' => trim($row['firstname'] . ' ' . $row['lastname']),
                'email' => (string) $row['email'],
                'id_order' => (int) $row['id_order'],
                'order_reference' => (string) $row['order_reference'],
                'last_message' => Text::clean($row['message'], self::$MESSAGE_MAX_CHARS),
            ];
        }

        return [
            'shops' => $scope->describe(),
            'status' => $status,
            'since' => $since,
            'count' => $count,
            'threads' => $threads,
            'sort' => $sort,
            'truncated' => $count > count($threads),
            'more' => $more,
            'next_before_id' => $sort === 'newest' && $more && !empty($threads) ? $threads[count($threads) - 1]['last_message_id'] : null,
            'untrusted_fields' => ['last_message', 'customer', 'email'],
        ];
    }
}
