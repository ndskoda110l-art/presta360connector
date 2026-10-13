<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Auth;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Replay protection: each nonce may be used once within TTL seconds.
 * Stored in the shop's own database (no shop data, only random request identifiers).
 */
class NonceStore
{
    const TTL = 900;

    /**
     * @param mixed $nonce
     *
     * @return bool
     */
    public static function isValidFormat($nonce)
    {
        return is_string($nonce) && preg_match('/^[A-Za-z0-9_-]{16,64}$/', $nonce) === 1;
    }

    /**
     * @param \Db $db master connection (writes)
     * @param string $nonce
     * @param int $now
     *
     * @return bool true when the nonce was not used before
     */
    public static function claim($db, $nonce, $now)
    {
        if (!self::isValidFormat($nonce)) {
            return false;
        }

        $table = '`' . _DB_PREFIX_ . \Presta360connector::NONCE_TABLE . '`';
        if (mt_rand(1, 20) === 1) {
            $db->execute('DELETE FROM ' . $table . ' WHERE `created_at` < ' . (int) ($now - self::TTL), false);
        }

        $inserted = $db->execute(
            'INSERT IGNORE INTO ' . $table . ' (`nonce`, `created_at`) VALUES (\'' . pSQL($nonce) . '\', ' . (int) $now . ')',
            false
        );

        return $inserted && (int) $db->Affected_Rows() === 1;
    }
}
