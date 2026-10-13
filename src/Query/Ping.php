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
use Presta360\Connector\Shop\ShopScope;

/**
 * Versions, shops and capabilities. The Presta360 service stores this as shop configuration
 * and adapts tools to it (e.g. no visit statistics when statsdata is not active).
 */
class Ping implements QueryInterface
{
    public function name()
    {
        return 'ping';
    }

    public function run(array $params, QueryContext $context)
    {
        $db = $context->db();
        $probe = new Probe($db);
        $modules = $probe->modules(['statsdata', 'statssearch', 'productcomments', 'ps_cashondelivery', 'cashondelivery']);

        $shops = [];
        foreach (ShopScope::loadShops($db) as $shop) {
            $shops[] = $shop;
        }

        $langId = (int) \Configuration::get('PS_LANG_DEFAULT');

        return [
            'platform' => [
                'ps_version' => _PS_VERSION_,
                'php_version' => PHP_VERSION,
                'db_engine' => $db->getEngine(),
                'db_version' => $db->getVersion(),
                'db_prefix' => _DB_PREFIX_,
                'timezone' => date_default_timezone_get(),
                'local_time' => date('Y-m-d H:i:s'),
                'currency_default' => $context->defaultCurrencyIso(),
                'lang_default' => (string) \Language::getIsoById($langId),
                'shop_enabled' => (bool) \Configuration::get('PS_SHOP_ENABLE'),
                'multistore_active' => (bool) \Configuration::get('PS_MULTISHOP_FEATURE_ACTIVE') && count($shops) > 1,
            ],
            'module' => [
                'version' => $context->module()->version,
                'contract' => \Presta360connector::$CONTRACT,
                'queries' => Registry::names(),
                'safeguards' => $db->getSafeguards(),
            ],
            'shops' => $shops,
            'capabilities' => [
                'columns' => $probe->optionalColumns(),
                'modules' => $modules,
                'visits' => [
                    'available' => $modules['statsdata'],
                    'last_recorded' => $probe->lastConnection(),
                    'page_views_enabled' => (bool) \Configuration::get('PS_STATSDATA_PAGESVIEWS'),
                    'customer_page_views_enabled' => (bool) \Configuration::get('PS_STATSDATA_CUSTOMER_PAGESVIEWS'),
                ],
                'purchase_price_coverage_90d' => $probe->purchasePriceCoverage(Period::daysAgo(90)),
            ],
            'tables' => $probe->tables(),
        ];
    }
}
