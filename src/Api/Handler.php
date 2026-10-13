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

use Presta360\Connector\Auth\HmacVerifier;
use Presta360\Connector\Auth\NonceStore;
use Presta360\Connector\Db\ReadOnlyDb;
use Presta360\Connector\Http\Request;
use Presta360\Connector\Query\QueryContext;
use Presta360\Connector\Query\Registry;

/**
 * Validates a signed request, runs one named read-only query and builds the response.
 * Returns [httpStatus, payload]. Error payloads never contain SQL, paths or stack traces.
 */
class Handler
{
    private static $MAX_BODY_BYTES = 65536;

    /** @var \Presta360connector */
    private $module;

    /**
     * @param \Presta360connector $module
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * @return array [int status, array payload]
     */
    public function handle(Request $request)
    {
        $started = microtime(true);
        $queryName = null;

        try {
            if ($request->getMethod() !== 'POST') {
                return $this->error(405, 'method_not_allowed', 'Use POST.');
            }

            $raw = $request->getBody();
            if ($raw === '' || strlen($raw) > self::$MAX_BODY_BYTES) {
                return $this->error(400, 'bad_request', 'Missing or oversized body.');
            }

            $data = json_decode($raw, true);
            if (!is_array($data)) {
                return $this->error(400, 'bad_request', 'Body must be a JSON object.');
            }

            $ts = isset($data['ts']) ? $data['ts'] : null;
            $verifier = new HmacVerifier($this->module->getActiveSecrets());
            if (!$verifier->verify($raw, $request->getHeader('X-P360-Signature'), $ts, time())) {
                return $this->error(401, 'unauthorized', 'Invalid signature.');
            }

            $version = isset($data['v']) ? (int) $data['v'] : 0;
            if ($version < 1 || $version > \Presta360connector::$CONTRACT) {
                return $this->error(400, 'unsupported_contract', 'Unsupported contract version.');
            }

            $nonce = isset($data['nonce']) ? $data['nonce'] : null;
            if (!NonceStore::isValidFormat($nonce)) {
                return $this->error(400, 'bad_request', 'Invalid nonce.');
            }
            if (!NonceStore::claim(\Db::getInstance(), $nonce, time())) {
                return $this->error(401, 'replay', 'Nonce already used.');
            }

            $queryName = isset($data['query']) && is_string($data['query']) ? $data['query'] : '';
            $query = Registry::get($queryName);
            if ($query === null) {
                return $this->error(404, 'unknown_query', 'Unknown query.', ['available' => Registry::names()]);
            }

            $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];

            $db = new ReadOnlyDb();
            $db->begin();
            try {
                $result = $query->run($params, new QueryContext($db, $this->module));
            } catch (\Exception $e) {
                $db->end();
                throw $e;
            }
            $db->end();

            return [200, [
                'ok' => true,
                'contract' => \Presta360connector::$CONTRACT,
                'module' => $this->module->version,
                'query' => $queryName,
                'took_ms' => (int) round((microtime(true) - $started) * 1000),
                'result' => $result,
            ]];
        } catch (InvalidParamsException $e) {
            return $this->error(422, 'invalid_params', $e->getMessage());
        } catch (QueryFailedException $e) {
            $this->log('query "' . $queryName . '" failed (db error ' . $e->getDbErrorNumber() . ')');

            return $this->error(500, 'query_failed', 'The query failed on this shop.');
        } catch (\Exception $e) {
            $this->log('internal error ' . get_class($e));

            return $this->error(500, 'internal', 'Internal error.');
        } catch (\Throwable $e) {
            $this->log('internal error ' . get_class($e));

            return $this->error(500, 'internal', 'Internal error.');
        }
    }

    private function error($status, $code, $message, array $extra = [])
    {
        $error = array_merge(['code' => $code, 'message' => $message], $extra);

        return [$status, [
            'ok' => false,
            'contract' => \Presta360connector::$CONTRACT,
            'module' => $this->module->version,
            'error' => $error,
        ]];
    }

    private function log($message)
    {
        try {
            if (class_exists('PrestaShopLogger')) {
                \PrestaShopLogger::addLog('Presta360: ' . $message, 2, null, 'Presta360connector', null, true);
            }
        } catch (\Exception $e) {
            // Logging must never break the response.
        }
    }
}
