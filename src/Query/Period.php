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

/**
 * Period boundaries in the shop's local time.
 *
 * PrestaShop sets PHP's default timezone to PS_TIMEZONE and writes dates with PHP, while MySQL
 * NOW() uses the server timezone. Boundaries are therefore computed here and sent to SQL as
 * literals; queries never use NOW() or CURDATE(). "to" is exclusive.
 */
class Period
{
    const PRESETS = 'today,yesterday,last_7_days,last_30_days,this_week,last_week,this_month,last_month,this_year,last_year';

    /**
     * @param array $params "period" preset, or "from" and "to" as "Y-m-d H:i:s" local time
     * @param int $maxDays
     * @param int|null $now unix timestamp (tests)
     *
     * @return array ['preset' => string, 'from' => string, 'to' => string, 'timezone' => string]
     *
     * @throws InvalidParamsException
     */
    public static function resolve(array $params, $maxDays, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        if (isset($params['from']) || isset($params['to'])) {
            $from = isset($params['from']) ? $params['from'] : null;
            $to = isset($params['to']) ? $params['to'] : null;
            if (!Params::isDateTime($from) || !Params::isDateTime($to)) {
                throw new InvalidParamsException('Parameters "from" and "to" must be "Y-m-d H:i:s" in shop local time.');
            }
            $preset = 'custom';
        } else {
            $preset = Params::enum($params, 'period', explode(',', self::PRESETS), 'today');
            list($from, $to) = self::preset($preset, $now);
        }

        $fromTs = strtotime($from);
        $toTs = strtotime($to);
        if ($fromTs === false || $toTs === false || $toTs <= $fromTs) {
            throw new InvalidParamsException('Parameter "to" must be after "from".');
        }
        if (($toTs - $fromTs) > ($maxDays * 86400 + 3600)) {
            throw new InvalidParamsException(sprintf('The period may span at most %d days.', $maxDays));
        }

        return ['preset' => $preset, 'from' => $from, 'to' => $to, 'timezone' => date_default_timezone_get()];
    }

    /**
     * @param int $days
     * @param int|null $now
     *
     * @return string local datetime $days days ago (start of the current hour precision kept)
     */
    public static function daysAgo($days, $now = null)
    {
        $now = $now === null ? time() : (int) $now;

        return date('Y-m-d H:i:s', $now - (int) $days * 86400);
    }

    private static function preset($preset, $now)
    {
        $y = (int) date('Y', $now);
        $m = (int) date('n', $now);
        $d = (int) date('j', $now);
        $dow = (int) date('N', $now);

        switch ($preset) {
            case 'yesterday':
                return [self::day($y, $m, $d - 1), self::day($y, $m, $d)];
            case 'last_7_days':
                return [self::day($y, $m, $d - 6), self::day($y, $m, $d + 1)];
            case 'last_30_days':
                return [self::day($y, $m, $d - 29), self::day($y, $m, $d + 1)];
            case 'this_week':
                return [self::day($y, $m, $d - $dow + 1), self::day($y, $m, $d + 1)];
            case 'last_week':
                return [self::day($y, $m, $d - $dow - 6), self::day($y, $m, $d - $dow + 1)];
            case 'this_month':
                return [self::day($y, $m, 1), self::day($y, $m, $d + 1)];
            case 'last_month':
                return [self::day($y, $m - 1, 1), self::day($y, $m, 1)];
            case 'this_year':
                return [self::day($y, 1, 1), self::day($y, $m, $d + 1)];
            case 'last_year':
                return [self::day($y - 1, 1, 1), self::day($y, 1, 1)];
            default:
                return [self::day($y, $m, $d), self::day($y, $m, $d + 1)];
        }
    }

    private static function day($y, $m, $d)
    {
        return date('Y-m-d H:i:s', mktime(0, 0, 0, $m, $d, $y));
    }
}
