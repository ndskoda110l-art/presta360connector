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

    /** Most queries one "batch" request may carry (zadanie D18). */
    public static $BATCH_MAX_QUERIES = 5;

    /** After this many seconds of a batch no further query is started (zadanie D18). */
    public static $BATCH_BUDGET_SECONDS = 20;

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
            $params = isset($data['params']) && is_array($data['params']) ? $data['params'] : [];
            if ($queryName === 'batch') {
                return $this->success($queryName, $started, $this->batch($params, $started));
            }
            $query = Registry::get($queryName);
            if ($query === null) {
                return $this->error(404, 'unknown_query', 'Unknown query.', ['available' => Registry::names()]);
            }

            $db = new ReadOnlyDb();
            $db->begin();
            try {
                $result = $query->run($params, new QueryContext($db, $this->module));
            } catch (\Exception $e) {
                $db->end();
                throw $e;
            }
            $db->end();

            return $this->success($queryName, $started, $result);
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

    /**
     * Several named queries in one signed request, run one after another in one read-only session
     * (zadanie D18). Each item gets its own result or error; one failing item does not stop the others.
     *
     * @param float $started
     *
     * @return array ['results' => array]
     *
     * @throws InvalidParamsException
     */
    private function batch(array $params, $started)
    {
        $items = isset($params['queries']) ? $params['queries'] : null;
        if (!is_array($items) || empty($items) || count($items) > self::$BATCH_MAX_QUERIES
            || array_keys($items) !== range(0, count($items) - 1)) {
            throw new InvalidParamsException(sprintf('Parameter "queries" must be a list of 1-%d queries.', self::$BATCH_MAX_QUERIES));
        }

        $results = [];
        $db = new ReadOnlyDb();
        $db->begin();
        try {
            foreach ($items as $index => $item) {
                $item = is_array($item) ? $item : [];
                $id = isset($item['id']) && is_string($item['id']) && preg_match('/^[A-Za-z0-9_.-]{1,32}$/', $item['id']) ? $item['id'] : (string) $index;
                $name = isset($item['query']) && is_string($item['query']) ? $item['query'] : '';
                $params = isset($item['params']) && is_array($item['params']) ? $item['params'] : [];
                $results[] = $this->batchItem($db, $id, $name, $params, $started);
            }
        } catch (\Exception $e) {
            $db->end();
            throw $e;
        }
        $db->end();

        return ['results' => $results];
    }

    /**
     * @param string $id
     * @param string $name
     * @param float $started
     *
     * @return array
     */
    private function batchItem(ReadOnlyDb $db, $id, $name, array $params, $started)
    {
        $base = ['id' => $id, 'query' => $name];
        if (microtime(true) - $started > self::$BATCH_BUDGET_SECONDS) {
            return $base + ['ok' => false, 'error' => ['code' => 'budget_exceeded', 'message' => 'Not run: the batch time limit was reached.']];
        }
        $query = $name === 'batch' || $name === 'ping' ? null : Registry::get($name);
        if ($query === null) {
            return $base + ['ok' => false, 'error' => ['code' => 'unknown_query', 'message' => 'Unknown query.']];
        }
        $itemStarted = microtime(true);
        try {
            $result = $query->run($params, new QueryContext($db, $this->module));
        } catch (InvalidParamsException $e) {
            return $base + ['ok' => false, 'error' => ['code' => 'invalid_params', 'message' => $e->getMessage()]];
        } catch (QueryFailedException $e) {
            $this->log('query "' . $name . '" failed (db error ' . $e->getDbErrorNumber() . ')');

            return $base + ['ok' => false, 'error' => ['code' => 'query_failed', 'message' => 'The query failed on this shop.']];
        }

        return $base + ['ok' => true, 'took_ms' => (int) round((microtime(true) - $itemStarted) * 1000), 'result' => $result];
    }

    /**
     * @param string $queryName
     * @param float $started
     *
     * @return array [int status, array payload]
     */
    private function success($queryName, $started, array $result)
    {
        return [200, [
            'ok' => true,
            'contract' => \Presta360connector::$CONTRACT,
            'module' => $this->module->version,
            'query' => $queryName,
            'took_ms' => (int) round((microtime(true) - $started) * 1000),
            'result' => $result,
        ]];
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
