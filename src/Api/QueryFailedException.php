<?php
/**
 * Presta360 Connector
 *
 * @author    Presta360
 * @copyright 2026 Presta360
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 */

namespace Presta360\Connector\Api;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * A database query failed. The message is internal and is never sent to the caller.
 */
class QueryFailedException extends \Exception
{
    /** @var int */
    private $dbErrorNumber = 0;

    /**
     * @param string $message
     * @param int $dbErrorNumber
     * @param \Exception|null $previous
     */
    public function __construct($message, $dbErrorNumber = 0, $previous = null)
    {
        parent::__construct($message, 0, $previous);
        $this->dbErrorNumber = (int) $dbErrorNumber;
    }

    /**
     * @return int
     */
    public function getDbErrorNumber()
    {
        return $this->dbErrorNumber;
    }
}
