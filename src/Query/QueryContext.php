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

class QueryContext
{
    /** @var ReadOnlyDb */
    private $db;

    /** @var \Presta360connector */
    private $module;

    /**
     * @param ReadOnlyDb $db
     * @param \Presta360connector $module
     */
    public function __construct(ReadOnlyDb $db, $module)
    {
        $this->db = $db;
        $this->module = $module;
    }

    /**
     * @return ReadOnlyDb
     */
    public function db()
    {
        return $this->db;
    }

    /**
     * @return \Presta360connector
     */
    public function module()
    {
        return $this->module;
    }

    /**
     * Shops covered by a query, from the optional "id_shop" parameter.
     *
     * @param array $params
     *
     * @return \Presta360\Connector\Shop\ShopScope
     *
     * @throws InvalidParamsException
     */
    public function shopScope(array $params)
    {
        return \Presta360\Connector\Shop\ShopScope::resolve($this->db, Params::shop($params), $this->module->getRequestShopId());
    }

    /**
     * Language for labels (state names, product names): optional "lang" ISO code, else the shop default.
     *
     * @param array $params
     *
     * @return int
     *
     * @throws InvalidParamsException
     */
    public function langId(array $params)
    {
        if (isset($params['lang'])) {
            if (!is_string($params['lang']) || !preg_match('/^[a-z]{2}$/', $params['lang'])) {
                throw new InvalidParamsException('Parameter "lang" must be a 2-letter ISO code.');
            }
            $id = (int) \Language::getIdByIso($params['lang']);
            if ($id > 0) {
                return $id;
            }
        }

        return (int) \Configuration::get('PS_LANG_DEFAULT');
    }

    /**
     * @return string ISO code of the default currency (all amounts are converted to it)
     */
    public function defaultCurrencyIso()
    {
        $id = (int) \Configuration::get('PS_CURRENCY_DEFAULT');
        $iso = $this->db->value('SELECT `iso_code` FROM ' . ReadOnlyDb::t('currency') . ' WHERE `id_currency` = ' . $id);

        return $iso === null ? '' : $iso;
    }
}
