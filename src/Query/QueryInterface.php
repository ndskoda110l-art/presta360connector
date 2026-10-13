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

/**
 * A named, read-only query from the contract register (zadanie, príloha B).
 * Implementations build SQL only from validated parameters and return plain arrays
 * with explicit int/float/string casts.
 */
interface QueryInterface
{
    /**
     * @return string e.g. "orders.summary"
     */
    public function name();

    /**
     * @param array $params
     * @param QueryContext $context
     *
     * @return array
     *
     * @throws \Presta360\Connector\Api\InvalidParamsException
     * @throws \Presta360\Connector\Api\QueryFailedException
     */
    public function run(array $params, QueryContext $context);
}
