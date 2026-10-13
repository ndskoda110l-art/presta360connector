<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Compat;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Text
{
    /**
     * Free text written by customers or employees: strip HTML, normalise whitespace, truncate.
     * The Presta360 service treats the result as untrusted data.
     *
     * @param string|null $text
     * @param int $maxChars
     *
     * @return string
     */
    public static function clean($text, $maxChars)
    {
        $text = (string) $text;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}]+/u', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", (string) $text);
        $text = trim((string) $text);

        if (function_exists('mb_strlen')) {
            if (mb_strlen($text, 'UTF-8') > $maxChars) {
                $text = rtrim(mb_substr($text, 0, $maxChars, 'UTF-8')) . '…';
            }
        } elseif (strlen($text) > $maxChars) {
            $cut = @iconv('UTF-8', 'UTF-8//IGNORE', substr($text, 0, $maxChars));
            $text = rtrim($cut === false ? '' : $cut) . '…';
        }

        return $text;
    }
}
