<?php
/*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
 */

/**
 *
 * @package CRM
 * @copyright CiviCRM LLC https://civicrm.org/licensing
 */
class CRM_Utils_Cache_Redis implements CRM_Utils_Cache_Interface {

  // TODO Consider native implementation.
  use CRM_Utils_Cache_NaiveMultipleTrait;
  // TODO Native implementation
  use CRM_Utils_Cache_NaiveHasTrait;

  const DEFAULT_HOST    = 'localhost';
  const DEFAULT_PORT    = 6379;
  const DEFAULT_TIMEOUT = 3600;
  const DEFAULT_PREFIX  = '';

  /**
   * The default timeout to use
   *
   * @var int
   */
  protected $_timeout = self::DEFAULT_TIMEOUT;

  /**
   * The prefix prepended to cache keys.
   *
   * If we are using the same redis instance for multiple CiviCRM
   * installs, we must have a unique prefix for each install to prevent
   * the keys from clobbering each other.
   *
   * @var string
   */
  protected $_prefix = self::DEFAULT_PREFIX;

  /**
   * The actual redis object
   *
   * @var Redis
   */
  protected $_cache;

  /**
   * Connection config (for reconnect after stale pconnect).
   *
   * @var array
   */
  protected $_connectConfig = [];

  /**
   * Create a connection. If a connection already exists, re-use it.
   *
   * @param array $config
   * @return Redis
   */
  public static function connect($config) {
    $host = $config['host'] ?? self::DEFAULT_HOST;
    $port = $config['port'] ?? self::DEFAULT_PORT;
    $socket = $config['socket'] ?? '';
    // Ugh.
    $pass = CRM_Utils_Constant::value('CIVICRM_DB_CACHE_PASSWORD');
    $user = CRM_Utils_Constant::value('CIVICRM_DB_CACHE_USERNAME');
    if (!empty($socket)) {
      $id = implode(':', ['connect', $socket /* $pass is constant */]);
    }
    else {
      $id = implode(':', ['connect', $host, $port /* $pass is constant */]);
    }
    if (!isset(Civi::$statics[__CLASS__][$id])) {
      // Ideally, we'd track the connection in the service-container, but the
      // cache connection is boot-critical.
      $redis = new Redis();
      if (!empty($socket)) {
        if (!$redis->pconnect($socket)) {
          // Don't use fatal here since we can go in an infinite loop.
          echo 'Could not connect to Redis server using socket';
          CRM_Utils_System::civiExit();
        }
      }
      else {
        $persistent = (defined('CIVICRM_DB_CACHE_REDIS_PERSISTENT') && CIVICRM_DB_CACHE_REDIS_PERSISTENT)
          || (defined('WP_REDIS_PERSISTENT') && WP_REDIS_PERSISTENT);
        $connectMethod = $persistent ? 'pconnect' : 'connect';

        $retryMs = defined('CIVICRM_DB_CACHE_REDIS_RETRY_INTERVAL')
          ? (int) CIVICRM_DB_CACHE_REDIS_RETRY_INTERVAL
          : 250;
        $connTimeout = 2.0;
        $readTimeout = 2.0;

        if ($persistent) {
          $database = 0;
          $passwordHash = ($user || $pass) ? hash('sha256', json_encode([$user, $pass])) : '';
          $persistentId = sprintf('%s:%s:%s:%s', $host, $port, $database, $passwordHash);
          $args = [$host, $port, $connTimeout, $persistentId, $retryMs, $readTimeout];
        }
        else {
          $args = [$host, $port, $connTimeout, NULL, $retryMs, $readTimeout];
        }

        if (!$redis->{$connectMethod}(...$args)) {
          // Don't use fatal here since we can go in an infinite loop.
          echo 'Could not connect to redisd server';
          CRM_Utils_System::civiExit();
        }
      }
      if ($user && $pass) {
        $redis->auth([$user, $pass]);
      }
      elseif ($pass) {
        $redis->auth($pass);
      }
      Civi::$statics[__CLASS__][$id] = $redis;
    }
    return Civi::$statics[__CLASS__][$id];
  }

  /**
   * @param Redis $redis
   */
  protected static function closeRedis($redis) {
    try {
      @$redis->close();
    }
    catch (Throwable $e) {
      // ignore — connection may already be dead
    }
  }

  /**
   * Run a cache op; on stale pconnect, close + reconnect once.
   *
   * @template T
   * @param callable():T $fn
   * @return T
   */
  protected function withRedis(callable $fn) {
    try {
      return $fn();
    }
    catch (RedisException $e) {
      self::closeRedis($this->_cache);
      $host = $this->_connectConfig['host'] ?? self::DEFAULT_HOST;
      $port = $this->_connectConfig['port'] ?? self::DEFAULT_PORT;
      $socket = $this->_connectConfig['socket'] ?? '';
      if (!empty($socket)) {
        unset(Civi::$statics[__CLASS__][implode(':', ['connect', $socket])]);
      }
      else {
        unset(Civi::$statics[__CLASS__][implode(':', ['connect', $host, $port])]);
      }
      $this->_cache = self::connect($this->_connectConfig);
      return $fn();
    }
  }

  /**
   * Constructor
   *
   * @param array $config
   *   An array of configuration params.
   *
   * @return \CRM_Utils_Cache_Redis
   */
  public function __construct($config) {
    if (isset($config['timeout'])) {
      $this->_timeout = $config['timeout'];
    }
    if (isset($config['prefix'])) {
      $this->_prefix = $config['prefix'];
    }
    if (defined('CIVICRM_DEPLOY_ID')) {
      $this->_prefix = CIVICRM_DEPLOY_ID . '_' . $this->_prefix;
    }

    $this->_connectConfig = $config;
    $this->_cache = self::connect($config);
  }

  /**
   * @param $key
   * @param $value
   * @param null|int|\DateInterval $ttl
   *
   * @return bool
   * @throws Exception
   */
  public function set($key, $value, $ttl = NULL) {
    CRM_Utils_Cache::assertValidKey($key);
    if (is_int($ttl) && $ttl <= 0) {
      return $this->delete($key);
    }
    $ttl = CRM_Utils_Date::convertCacheTtl($ttl, self::DEFAULT_TIMEOUT);
    return $this->withRedis(function () use ($key, $value, $ttl) {
      if (!$this->_cache->setex($this->_prefix . $key, $ttl, serialize($value))) {
        if (PHP_SAPI === 'cli' || (Civi\Core\Container::isContainerBooted() && CRM_Core_Permission::check('view debug output'))) {
          throw new CRM_Utils_Cache_CacheException("Redis set ($key) failed: " . $this->_cache->getLastError());
        }
        else {
          Civi::log()->error("Redis set ($key) failed: " . $this->_cache->getLastError());
          throw new CRM_Utils_Cache_CacheException("Redis set ($key) failed");
        }
        return FALSE;
      }
      return TRUE;
    });
  }

  /**
   * @param $key
   * @param mixed $default
   *
   * @return mixed
   */
  public function get($key, $default = NULL) {
    CRM_Utils_Cache::assertValidKey($key);
    return $this->withRedis(function () use ($key, $default) {
      $result = $this->_cache->get($this->_prefix . $key);
      return ($result === FALSE) ? $default : unserialize($result);
    });
  }

  /**
   * @param $key
   *
   * @return bool
   */
  public function delete($key) {
    CRM_Utils_Cache::assertValidKey($key);
    return $this->withRedis(function () use ($key) {
      $this->_cache->del($this->_prefix . $key);
      return TRUE;
    });
  }

  /**
   * @return bool
   */
  public function flush() {
    // FIXME: Ideally, we'd map each prefix to a different 'hash' object in Redis,
    // and this would be simpler. However, that needs to go in tandem with a
    // more general rethink of cache expiration/TTL.

    return $this->withRedis(function () {
      $keys = $this->_cache->keys($this->_prefix . '*');
      if ($keys !== FALSE) {
        $this->_cache->del($keys);
      }
      return TRUE;
    });
  }

  public function clear() {
    return $this->flush();
  }

  /**
   * {@inheritdoc}
   */
  public function garbageCollection() {
    return FALSE;
  }

}
