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
use Presta360\Connector\Compat\Text;
use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Shop\ShopScope;

/**
 * One customer service thread as the back office shows it (zadanie D20): who wrote, about which
 * order or product, and the conversation of the customer and the shop's employees.
 *
 * Private employee notes (private = 1) are never returned. Message text is untrusted: HTML is
 * stripped and long texts are truncated; attachments are reported only as a flag.
 */
class ServiceThread implements QueryInterface
{
    /** @var int */
    private static $MAX_MESSAGES = 100;

    /** @var int */
    private static $MESSAGE_MAX_CHARS = 4000;

    public function name()
    {
        return 'service.thread';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);
        $id = Params::int($params, 'id_customer_thread', 0, 0, PHP_INT_MAX);
        if ($id === 0) {
            throw new InvalidParamsException('Parameter "id_customer_thread" is required.');
        }

        $thread = $db->row('SELECT ct.`id_customer_thread`, ct.`id_shop`, ct.`id_customer`, ct.`id_order`, ct.`id_product`,
                ct.`id_contact`, ct.`email`, ct.`status`, ct.`date_add`, ct.`date_upd`,
                cu.`firstname`, cu.`lastname`, cu.`company`, o.`reference` AS order_reference,
                cl.`name` AS contact_name, pl.`name` AS product_name
            FROM ' . ReadOnlyDb::t('customer_thread') . ' ct
            LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = ct.`id_customer`
            LEFT JOIN ' . ReadOnlyDb::t('orders') . ' o ON o.`id_order` = ct.`id_order`
            LEFT JOIN ' . ReadOnlyDb::t('contact_lang') . ' cl ON cl.`id_contact` = ct.`id_contact` AND cl.`id_lang` = ' . (int) $langId . '
            LEFT JOIN ' . ReadOnlyDb::t('product_lang') . ' pl ON pl.`id_product` = ct.`id_product` AND pl.`id_lang` = ' . (int) $langId . ' AND pl.`id_shop` = ct.`id_shop`
            WHERE ct.`id_customer_thread` = ' . (int) $id . '
              AND ct.`id_shop` IN (' . ShopScope::inList($scope->shopIds()) . ')');
        if (empty($thread)) {
            return ['shops' => $scope->describe(), 'found' => false];
        }

        $total = (int) $db->value('SELECT COUNT(*) FROM ' . ReadOnlyDb::t('customer_message') . ' cm
            WHERE cm.`id_customer_thread` = ' . (int) $id . ' AND cm.`private` = 0');
        // The latest messages, shown oldest first like a conversation.
        $rows = $db->select('SELECT cm.`id_customer_message`, cm.`id_employee`, cm.`message`, cm.`file_name`, cm.`date_add`,
                e.`firstname` AS employee_firstname
            FROM ' . ReadOnlyDb::t('customer_message') . ' cm
            LEFT JOIN ' . ReadOnlyDb::t('employee') . ' e ON e.`id_employee` = cm.`id_employee`
            WHERE cm.`id_customer_thread` = ' . (int) $id . ' AND cm.`private` = 0
            ORDER BY cm.`id_customer_message` DESC
            LIMIT ' . (int) self::$MAX_MESSAGES);

        $messages = [];
        foreach (array_reverse($rows) as $row) {
            $byShop = (int) $row['id_employee'] > 0;
            $messages[] = [
                'id' => (int) $row['id_customer_message'],
                'from' => $byShop ? 'shop' : 'customer',
                'employee' => $byShop ? (string) $row['employee_firstname'] : '',
                'date_add' => (string) $row['date_add'],
                'message' => Text::clean($row['message'], self::$MESSAGE_MAX_CHARS),
                'has_attachment' => trim((string) $row['file_name']) !== '',
            ];
        }

        $last = empty($messages) ? null : $messages[count($messages) - 1];
        $lastAt = $last === null ? false : strtotime($last['date_add']);

        return [
            'shops' => $scope->describe(),
            'found' => true,
            'thread' => [
                'id_customer_thread' => (int) $thread['id_customer_thread'],
                'status' => (string) $thread['status'],
                'opened_at' => (string) $thread['date_add'],
                'updated_at' => (string) $thread['date_upd'],
                'contact' => (string) $thread['contact_name'],
                'id_customer' => (int) $thread['id_customer'],
                'customer' => trim($thread['firstname'] . ' ' . $thread['lastname']),
                'company' => (string) $thread['company'],
                'email' => (string) $thread['email'],
                'id_order' => (int) $thread['id_order'],
                'order_reference' => (string) $thread['order_reference'],
                'id_product' => (int) $thread['id_product'],
                'product' => (string) $thread['product_name'],
                'last_from' => $last === null ? null : $last['from'],
                'waiting_hours' => $last !== null && $last['from'] === 'customer' && $lastAt !== false ? max(0, (int) floor((time() - $lastAt) / 3600)) : null,
            ],
            'messages' => $messages,
            'messages_total' => $total,
            'messages_truncated' => $total > count($messages),
            'untrusted_fields' => ['thread.customer', 'thread.company', 'thread.email', 'thread.product', 'messages'],
        ];
    }
}
