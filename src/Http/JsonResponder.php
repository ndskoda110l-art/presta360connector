<?php

/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Http;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Sends JSON without relying on ajaxDie() (removed in 9.0) or ajaxRender() (1.7.5+ only).
 * Any buffered output (notices in dev mode, other modules) is discarded first.
 */
class JsonResponder
{
    /**
     * @param int $status
     * @param array $payload
     */
    public static function send($status, array $payload)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if (!headers_sent()) {
            http_response_code((int) $status);
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, max-age=0');
            header('X-Content-Type-Options: nosniff');
            header('X-Robots-Tag: noindex, nofollow');
        }

        echo self::encode($payload);
    }

    /**
     * @param array $payload
     *
     * @return string
     */
    public static function encode(array $payload)
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        if (defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $json = json_encode($payload, $flags);
        if ($json === false) {
            $json = json_encode(self::toUtf8($payload), $flags);
        }

        return $json === false ? '{"ok":false,"error":{"code":"encoding_failed","message":"Response encoding failed."}}' : $json;
    }

    private static function toUtf8($value)
    {
        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::toUtf8($item);
            }

            return $out;
        }
        if (is_string($value)) {
            if (function_exists('mb_convert_encoding')) {
                return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
            }
            $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);

            return $clean === false ? '' : $clean;
        }

        return $value;
    }
}
