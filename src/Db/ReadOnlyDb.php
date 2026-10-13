<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Db;

if (!defined('_PS_VERSION_')) {
    exit;
}

use Presta360\Connector\Api\QueryFailedException;

/**
 * Read access to the shop database for the duration of one request.
 *
 * The shop has a single DB user, so read-only cannot be enforced by grants. Defence in depth:
 * only named SELECT queries exist, the session is switched to READ ONLY and a statement
 * timeout is applied (MySQL 5.7.8+: max_execution_time in ms, MariaDB 10.1+: max_statement_time in s).
 */
class ReadOnlyDb
{
    private static $TIMEOUT_SECONDS = 5;

    /** @var \Db */
    private $db;

    /** @var string */
    private $version = '';

    /** @var bool */
    private $isMariaDb = false;

    /** @var bool */
    private $readOnly = false;

    /** @var string|null "mysql"|"mariadb" when a timeout was set */
    private $timeout;

    /**
     * @param \Db|null $db
     */
    public function __construct($db = null)
    {
        $this->db = $db !== null ? $db : \Db::getInstance((bool) _PS_USE_SQL_SLAVE_);
    }

    public function begin()
    {
        $this->version = (string) $this->db->getValue('SELECT VERSION()', false);
        $this->isMariaDb = stripos($this->version, 'mariadb') !== false;
        $this->readOnly = $this->exec('SET SESSION TRANSACTION READ ONLY');

        $number = $this->numericVersion();
        if ($this->isMariaDb) {
            if (version_compare($number, '10.1.1', '>=') && $this->exec('SET SESSION max_statement_time = ' . (int) self::$TIMEOUT_SECONDS)) {
                $this->timeout = 'mariadb';
            }
        } elseif (version_compare($number, '5.7.8', '>=') && $this->exec('SET SESSION max_execution_time = ' . (int) (self::$TIMEOUT_SECONDS * 1000))) {
            $this->timeout = 'mysql';
        }
    }

    public function end()
    {
        if ($this->readOnly) {
            $this->exec('SET SESSION TRANSACTION READ WRITE');
            $this->readOnly = false;
        }
        if ($this->timeout === 'mariadb') {
            $this->exec('SET SESSION max_statement_time = 0');
        } elseif ($this->timeout === 'mysql') {
            $this->exec('SET SESSION max_execution_time = 0');
        }
        $this->timeout = null;
    }

    /**
     * @param string $sql a SELECT statement built from validated parameters
     *
     * @return array
     *
     * @throws QueryFailedException
     */
    public function select($sql)
    {
        try {
            $rows = $this->db->executeS($sql, true, false);
        } catch (\Exception $e) {
            throw new QueryFailedException('select failed', $this->errorNumber(), $e);
        }

        if ($rows === false) {
            $error = $this->errorNumber();
            if ($error !== 0) {
                throw new QueryFailedException('select failed', $error);
            }

            return [];
        }

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param string $sql
     *
     * @return array|null first row or null
     */
    public function row($sql)
    {
        $rows = $this->select($sql);

        return isset($rows[0]) ? $rows[0] : null;
    }

    /**
     * @param string $sql
     *
     * @return string|null first column of the first row
     */
    public function value($sql)
    {
        $row = $this->row($sql);
        if ($row === null) {
            return null;
        }
        $value = reset($row);

        return $value === null || $value === false ? null : (string) $value;
    }

    /**
     * @param string $name table name without prefix
     *
     * @return string quoted, prefixed table name
     */
    public static function t($name)
    {
        return '`' . _DB_PREFIX_ . $name . '`';
    }

    /**
     * @return string
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string "mariadb"|"mysql"
     */
    public function getEngine()
    {
        return $this->isMariaDb ? 'mariadb' : 'mysql';
    }

    /**
     * @return array
     */
    public function getSafeguards()
    {
        return ['read_only_session' => $this->readOnly, 'statement_timeout' => $this->timeout !== null];
    }

    private function numericVersion()
    {
        return preg_match('/(\d+\.\d+\.\d+)/', $this->version, $m) ? $m[1] : '0.0.0';
    }

    private function exec($sql)
    {
        try {
            return (bool) $this->db->execute($sql, false);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function errorNumber()
    {
        try {
            return (int) $this->db->getNumberError();
        } catch (\Exception $e) {
            return 0;
        }
    }
}
