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
 * One order as the manager sees it in BO (zadanie D13): header, lines, delivery and invoice
 * address, carrier with tracking, state history and non-private messages.
 *
 * Customer data is personal data passed through in transit only (zadanie D6, P6); texts typed by
 * customers are listed in "untrusted_fields". Amounts stay in the order currency, as in BO.
 */
class OrdersDetail implements QueryInterface
{
    /** @var int */
    private static $MAX_LINES = 200;

    /** @var int */
    private static $MAX_HISTORY = 50;

    /** @var int */
    private static $MAX_MESSAGES = 20;

    /** @var int */
    private static $MESSAGE_MAX_CHARS = 600;

    public function name()
    {
        return 'orders.detail';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $scope = $context->shopScope($params);
        $langId = $context->langId($params);
        $shops = ShopScope::inList($scope->orderShopIds());

        $idOrder = Params::int($params, 'id_order', 0, 0, PHP_INT_MAX);
        $reference = isset($params['reference']) ? $params['reference'] : null;
        if ($reference !== null && (!is_string($reference) || !preg_match('/^[A-Za-z0-9_#.\-]{1,64}$/', $reference))) {
            throw new InvalidParamsException('Parameter "reference" must be 1-64 letters, digits or _#.-');
        }
        if ($idOrder === 0 && $reference === null) {
            throw new InvalidParamsException('Parameter "id_order" or "reference" is required.');
        }

        // A reference can be shared by the orders of one split cart: the first one is detailed.
        $where = $idOrder > 0 ? 'o.`id_order` = ' . (int) $idOrder : 'o.`reference` = \'' . pSQL($reference) . '\'';
        $ids = $db->select('SELECT o.`id_order` FROM ' . ReadOnlyDb::t('orders') . ' o
            WHERE ' . $where . ' AND o.`id_shop` IN (' . $shops . ') ORDER BY o.`id_order` ASC LIMIT 10');
        if (empty($ids)) {
            return ['shops' => $scope->describe(), 'found' => false];
        }
        $id = (int) $ids[0]['id_order'];
        $related = [];
        foreach ($ids as $row) {
            if ((int) $row['id_order'] !== $id) {
                $related[] = (int) $row['id_order'];
            }
        }

        $o = $db->row('SELECT o.`id_order`, o.`reference`, o.`id_shop`, o.`date_add`, o.`date_upd`, o.`invoice_date`, o.`invoice_number`,
                o.`delivery_date`, o.`current_state`, o.`payment`, o.`module`, o.`valid`, o.`gift`, o.`recyclable`,
                o.`total_products`, o.`total_products_wt`, o.`total_shipping_tax_incl`, o.`total_discounts_tax_incl`,
                o.`total_wrapping_tax_incl`, o.`total_paid_tax_incl`, o.`total_paid_tax_excl`, o.`total_paid_real`,
                o.`id_customer`, o.`id_address_delivery`, o.`id_address_invoice`, o.`id_carrier`,
                cur.`iso_code` AS currency, os.`paid`, os.`shipped`, os.`delivery`, os.`color` AS state_color,
                osl.`name` AS state_name, c.`name` AS carrier_name,
                cu.`firstname`, cu.`lastname`, cu.`email`, cu.`company`, cu.`date_add` AS customer_since
            FROM ' . ReadOnlyDb::t('orders') . ' o
            LEFT JOIN ' . ReadOnlyDb::t('currency') . ' cur ON cur.`id_currency` = o.`id_currency`
            LEFT JOIN ' . ReadOnlyDb::t('order_state') . ' os ON os.`id_order_state` = o.`current_state`
            LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = o.`current_state` AND osl.`id_lang` = ' . (int) $langId . '
            LEFT JOIN ' . ReadOnlyDb::t('carrier') . ' c ON c.`id_carrier` = o.`id_carrier`
            LEFT JOIN ' . ReadOnlyDb::t('customer') . ' cu ON cu.`id_customer` = o.`id_customer`
            WHERE o.`id_order` = ' . $id);

        $stateChanged = strtotime((string) $o['date_upd']);
        $customerOrders = (int) $db->value('SELECT COUNT(*) FROM ' . ReadOnlyDb::t('orders') . '
            WHERE `id_customer` = ' . (int) $o['id_customer'] . ' AND `valid` = 1 AND `id_shop` IN (' . $shops . ')');

        $lines = [];
        $lineRows = $db->select('SELECT od.`product_id`, od.`product_attribute_id`, od.`product_name`, od.`product_reference`,
                od.`product_quantity`, od.`product_quantity_refunded`, od.`product_quantity_return`,
                od.`unit_price_tax_incl`, od.`total_price_tax_incl`, od.`total_price_tax_excl`
            FROM ' . ReadOnlyDb::t('order_detail') . ' od
            WHERE od.`id_order` = ' . $id . '
            ORDER BY od.`id_order_detail` ASC
            LIMIT ' . (self::$MAX_LINES + 1));
        foreach (array_slice($lineRows, 0, self::$MAX_LINES) as $row) {
            $lines[] = [
                'id_product' => (int) $row['product_id'],
                'id_product_attribute' => (int) $row['product_attribute_id'],
                'name' => (string) $row['product_name'],
                'reference' => (string) $row['product_reference'],
                'quantity' => (int) $row['product_quantity'],
                'refunded' => (int) $row['product_quantity_refunded'],
                'returned' => (int) $row['product_quantity_return'],
                'unit_price_tax_incl' => round((float) $row['unit_price_tax_incl'], 2),
                'total_tax_incl' => round((float) $row['total_price_tax_incl'], 2),
                'total_tax_excl' => round((float) $row['total_price_tax_excl'], 2),
            ];
        }

        $tracking = $db->value('SELECT oc.`tracking_number` FROM ' . ReadOnlyDb::t('order_carrier') . ' oc
            WHERE oc.`id_order` = ' . $id . ' AND oc.`tracking_number` <> \'\'
            ORDER BY oc.`id_order_carrier` DESC LIMIT 1');

        $history = [];
        foreach ($db->select('SELECT oh.`id_order_state`, oh.`date_add`, oh.`id_employee`, osl.`name`, os.`color`
            FROM ' . ReadOnlyDb::t('order_history') . ' oh
            LEFT JOIN ' . ReadOnlyDb::t('order_state') . ' os ON os.`id_order_state` = oh.`id_order_state`
            LEFT JOIN ' . ReadOnlyDb::t('order_state_lang') . ' osl ON osl.`id_order_state` = oh.`id_order_state` AND osl.`id_lang` = ' . (int) $langId . '
            WHERE oh.`id_order` = ' . $id . '
            ORDER BY oh.`date_add` DESC, oh.`id_order_history` DESC
            LIMIT ' . self::$MAX_HISTORY) as $row) {
            $history[] = [
                'id_state' => (int) $row['id_order_state'],
                'state' => (string) $row['name'],
                'color' => (string) $row['color'],
                'date' => (string) $row['date_add'],
                'by_employee' => (int) $row['id_employee'] > 0,
            ];
        }

        $messages = [];
        foreach ($db->select('SELECT cm.`message`, cm.`date_add`, cm.`id_employee`
            FROM ' . ReadOnlyDb::t('customer_message') . ' cm
            INNER JOIN ' . ReadOnlyDb::t('customer_thread') . ' ct ON ct.`id_customer_thread` = cm.`id_customer_thread`
            WHERE ct.`id_order` = ' . $id . ' AND cm.`private` = 0
            ORDER BY cm.`date_add` DESC, cm.`id_customer_message` DESC
            LIMIT ' . self::$MAX_MESSAGES) as $row) {
            $messages[] = [
                'from' => (int) $row['id_employee'] > 0 ? 'shop' : 'customer',
                'date' => (string) $row['date_add'],
                'text' => Text::clean($row['message'], self::$MESSAGE_MAX_CHARS),
            ];
        }

        return [
            'shops' => $scope->describe(),
            'found' => true,
            'order' => [
                'id_order' => $id,
                'reference' => (string) $o['reference'],
                'related_orders' => $related,
                'date_add' => (string) $o['date_add'],
                'invoice_date' => (string) $o['invoice_date'],
                'invoice_number' => (int) $o['invoice_number'],
                'delivery_date' => (string) $o['delivery_date'],
                'state' => (string) $o['state_name'],
                'id_state' => (int) $o['current_state'],
                'state_color' => (string) $o['state_color'],
                'paid' => (bool) $o['paid'],
                'shipped' => (bool) $o['shipped'],
                'delivered' => (bool) $o['delivery'],
                'valid' => (bool) $o['valid'],
                'hours_in_state' => $stateChanged === false ? null : max(0, (int) floor((time() - $stateChanged) / 3600)),
                'payment' => (string) $o['payment'],
                'module' => (string) $o['module'],
                'gift' => (bool) $o['gift'],
                'currency' => (string) $o['currency'],
                'totals' => [
                    'products_tax_excl' => round((float) $o['total_products'], 2),
                    'products_tax_incl' => round((float) $o['total_products_wt'], 2),
                    'shipping_tax_incl' => round((float) $o['total_shipping_tax_incl'], 2),
                    'discounts_tax_incl' => round((float) $o['total_discounts_tax_incl'], 2),
                    'wrapping_tax_incl' => round((float) $o['total_wrapping_tax_incl'], 2),
                    'paid_tax_incl' => round((float) $o['total_paid_tax_incl'], 2),
                    'paid_tax_excl' => round((float) $o['total_paid_tax_excl'], 2),
                    'paid_real' => round((float) $o['total_paid_real'], 2),
                ],
            ],
            'customer' => [
                'id_customer' => (int) $o['id_customer'],
                'name' => trim($o['firstname'] . ' ' . $o['lastname']),
                'email' => (string) $o['email'],
                'company' => (string) $o['company'],
                'since' => (string) $o['customer_since'],
                'valid_orders' => $customerOrders,
            ],
            'delivery' => [
                'carrier' => (string) $o['carrier_name'],
                'id_carrier' => (int) $o['id_carrier'],
                'tracking_number' => $tracking === null ? '' : $tracking,
                'address' => $this->address($db, (int) $o['id_address_delivery'], $langId),
            ],
            'invoice_address' => (int) $o['id_address_invoice'] === (int) $o['id_address_delivery']
                ? null
                : $this->address($db, (int) $o['id_address_invoice'], $langId),
            'lines' => $lines,
            'lines_truncated' => count($lineRows) > self::$MAX_LINES,
            'history' => $history,
            'messages' => $messages,
            'untrusted_fields' => ['customer', 'delivery.address', 'invoice_address', 'messages'],
        ];
    }

    /**
     * @param ReadOnlyDb $db
     * @param int $idAddress
     * @param int $langId
     *
     * @return array|null
     */
    private function address(ReadOnlyDb $db, $idAddress, $langId)
    {
        if ($idAddress <= 0) {
            return null;
        }
        $a = $db->row('SELECT a.`firstname`, a.`lastname`, a.`company`, a.`vat_number`, a.`address1`, a.`address2`,
                a.`postcode`, a.`city`, a.`phone`, a.`phone_mobile`, cl.`name` AS country
            FROM ' . ReadOnlyDb::t('address') . ' a
            LEFT JOIN ' . ReadOnlyDb::t('country_lang') . ' cl ON cl.`id_country` = a.`id_country` AND cl.`id_lang` = ' . (int) $langId . '
            WHERE a.`id_address` = ' . (int) $idAddress);
        if ($a === null) {
            return null;
        }

        return [
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
}
