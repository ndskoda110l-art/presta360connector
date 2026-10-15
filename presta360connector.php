<?php
/**
 * Presta360 Connector
 *
 * Read-only connector between a PrestaShop shop and the Presta360 service.
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

if (!defined('P360_CONNECTOR_AUTOLOAD')) {
    define('P360_CONNECTOR_AUTOLOAD', true);
    spl_autoload_register(function ($class) {
        $prefix = 'Presta360\\Connector\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $file = dirname(__FILE__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}

use Presta360\Connector\Compat\Random;

class Presta360connector extends Module
{
    /** Version of the request/response contract shared with the Presta360 service. */
    public static $CONTRACT = 1;

    private static $CONF_SECRET = 'P360_SECRET';
    private static $CONF_SECRET_PREV = 'P360_SECRET_PREV';
    private static $CONF_SECRET_PREV_UNTIL = 'P360_SECRET_PREV_UNTIL';
    private static $CONF_SECRET_REVEAL = 'P360_SECRET_REVEAL';
    public static $NONCE_TABLE = 'presta360_nonce';
    private static $PREVIOUS_SECRET_TTL = 86400;
    private static $SOURCE_URL = 'https://github.com/ndskoda110l-art/presta360connector';

    public function __construct()
    {
        $this->name = 'presta360connector';
        $this->tab = 'administration';
        $this->version = '0.9.0';
        $this->author = 'Presta360';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.6.1.11', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Presta360 Connector');
        $this->description = $this->l('Read-only connector that lets shop managers ask about their PrestaShop in Claude.');
    }

    public function install()
    {
        if (PHP_VERSION_ID < 50600) {
            $this->_errors[] = $this->l('Your PHP version is too old. PHP 5.6 or newer is required.');

            return false;
        }

        return parent::install()
            && $this->installNonceTable()
            && $this->rotateSecret(false);
    }

    public function uninstall()
    {
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . self::$NONCE_TABLE . '`');
        foreach ([self::$CONF_SECRET, self::$CONF_SECRET_PREV, self::$CONF_SECRET_PREV_UNTIL, self::$CONF_SECRET_REVEAL] as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    /**
     * Secrets accepted for request signatures: the current one and, during rotation, the previous one.
     *
     * @return string[]
     */
    public function getActiveSecrets()
    {
        $secrets = [];
        $current = Configuration::getGlobalValue(self::$CONF_SECRET);
        if (is_string($current) && $current !== '') {
            $secrets[] = $current;
        }
        $previous = Configuration::getGlobalValue(self::$CONF_SECRET_PREV);
        $until = (int) Configuration::getGlobalValue(self::$CONF_SECRET_PREV_UNTIL);
        if (is_string($previous) && $previous !== '' && $until > time()) {
            $secrets[] = $previous;
        }

        return $secrets;
    }

    /**
     * @return int shop resolved by PrestaShop for the current request (0 when unknown)
     */
    public function getRequestShopId()
    {
        return is_object($this->context->shop) ? (int) $this->context->shop->id : 0;
    }

    public function getContent()
    {
        $notice = '';
        if (Tools::isSubmit('submitP360Regenerate')) {
            if ($this->rotateSecret(true)) {
                $notice = $this->displayConfirmation($this->l('A new secret was generated.'));
            }
        }

        return $notice . $this->renderConfigurationPage();
    }

    private function installNonceTable()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . self::$NONCE_TABLE . '` (
            `nonce` VARCHAR(64) NOT NULL,
            `created_at` INT(10) UNSIGNED NOT NULL,
            PRIMARY KEY (`nonce`),
            KEY `created_at` (`created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=ascii';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * @param bool $keepPrevious keep the old secret valid for PREVIOUS_SECRET_TTL seconds
     *
     * @return bool
     */
    private function rotateSecret($keepPrevious)
    {
        try {
            $secret = Random::hex(32);
        } catch (Exception $e) {
            $this->_errors[] = $e->getMessage();

            return false;
        }

        $old = Configuration::getGlobalValue(self::$CONF_SECRET);
        if ($keepPrevious && is_string($old) && $old !== '') {
            Configuration::updateGlobalValue(self::$CONF_SECRET_PREV, $old);
            Configuration::updateGlobalValue(self::$CONF_SECRET_PREV_UNTIL, (string) (time() + self::$PREVIOUS_SECRET_TTL));
        }

        return Configuration::updateGlobalValue(self::$CONF_SECRET, $secret)
            && Configuration::updateGlobalValue(self::$CONF_SECRET_REVEAL, '1');
    }

    private function getEndpointUrl()
    {
        $shop = $this->context->shop;

        return Tools::getShopDomainSsl(true) . $shop->physical_uri
            . 'index.php?fc=module&module=' . $this->name . '&controller=api';
    }

    private function renderConfigurationPage()
    {
        $reveal = Configuration::getGlobalValue(self::$CONF_SECRET_REVEAL) === '1';
        $secret = (string) Configuration::getGlobalValue(self::$CONF_SECRET);
        if ($reveal) {
            Configuration::updateGlobalValue(self::$CONF_SECRET_REVEAL, '0');
        }

        // Texts are translated here (legacy $this->l works on 1.6 to 9.x); the template only escapes and prints.
        $this->context->smarty->assign([
            'p360' => [
                'action' => AdminController::$currentIndex . '&configure=' . $this->name
                    . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                'endpoint' => $this->getEndpointUrl(),
                'secret' => $reveal ? $secret : '',
                'module_version' => $this->version,
                'contract' => (int) self::$CONTRACT,
                'ps_version' => _PS_VERSION_,
                'php_version' => PHP_VERSION,
                'source_url' => self::$SOURCE_URL,
                't' => [
                    'connection' => $this->l('Connection details'),
                    'copy_both' => $this->l('Copy both values into the Presta360 admin to connect this shop.'),
                    'endpoint' => $this->l('Endpoint URL'),
                    'secret' => $this->l('Secret key'),
                    'shown_once' => $this->l('The secret is shown only once. Store it now; if you lose it, generate a new one.'),
                    'hidden' => $this->l('The secret is hidden. Generate a new one if you need to reconnect.'),
                    'confirm' => $this->l('Are you sure?'),
                    'regenerate_help' => $this->l('Generating a new secret disconnects Presta360 until you paste the new secret. The previous secret keeps working for 24 hours.'),
                    'regenerate' => $this->l('Generate new secret'),
                    'status' => $this->l('Status'),
                    'module_version' => $this->l('Module version'),
                    'contract' => $this->l('Contract version'),
                    'ps_version' => $this->l('PrestaShop version'),
                    'php_version' => $this->l('PHP version'),
                    'what' => $this->l('What this module does'),
                    'what_queries' => $this->l('Runs only predefined read-only queries (sales, orders to ship, low stock, customer messages) and returns aggregated results to Presta360.'),
                    'what_safety' => $this->l('Never modifies your shop data, does not store shop data outside your shop, and accepts only signed requests.'),
                    'source' => $this->l('Source code'),
                ],
            ],
        ]);

        return $this->display(__FILE__, 'views/templates/admin/configure.tpl');
    }
}
