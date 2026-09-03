<?php
// 防止直接访问；本文件不连接数据库，不输出任何内容。
if (!defined('SYSTEM')) { exit(); }

class PlayurlCache {
	private const KEY_WHERE = '`area` = :area AND `type` = :type AND `cache_type` = :cache_type AND `cid` = :cid AND `ep_id` = :ep_id';
	private $database;
	private $redis;

	public function __construct($database, $redis = null) {
		$this->database = $database;
		$this->redis = $redis;
	}

	// 仅检查 JSON 和业务码，不重新编码，以保留 URL、转义、换行及大整数。
	public static function responseCode($body) {
		if (!is_string($body) || $body === '') {
			return null;
		}
		$data = json_decode($body, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($data) || !isset($data['code'])) {
			return null;
		}
		$code = $data['code'];
		if (is_int($code)) {
			return $code;
		}
		if (is_string($code) && preg_match('/^-?\d+$/D', $code)) {
			return (int) $code;
		}
		return null;
	}

	private static function hasIdentifier(array $key) {
		return $key['cid'] !== '' || $key['ep_id'] !== '';
	}

	private static function redisKey(array $key) {
		return implode('-', [$key['area'], $key['type'], $key['cache_type'], $key['cid'], $key['ep_id']]);
	}

	private static function logFailure($operation) {
		// 异常正文可能包含 SQL、密钥或播放 URL，只记录操作名称。
		error_log('[playurl-cache] ' . $operation . ' failed; bypassing cache');
	}

	// null 表示未命中；不尝试用字符串替换“修复”旧的损坏 JSON。
	public function read(array $key, $now) {
		if (!self::hasIdentifier($key)) {
			return null;
		}
		try {
			if ($this->redis !== null) {
				$redisKey = self::redisKey($key);
				$body = $this->redis->get($redisKey);
				$ttl = $this->redis->ttl($redisKey);
				// -1 无过期时间、-2 不存在、-3 连接失败均不能当作有效播放缓存。
				if ($ttl <= 0) {
					return null;
				}
			} else {
				// 兼容旧表中的重复记录：只读取最新的一条，不随机取到更旧的结果。
				$stmt = $this->database->prepare('SELECT `cache`, `expired_time` FROM `cache` WHERE ' . self::KEY_WHERE . ' ORDER BY `id` DESC LIMIT 1');
				if (!$stmt || !$stmt->execute($key)) {
					self::logFailure('MySQL read');
					return null;
				}
				$row = $stmt->fetch(PDO::FETCH_ASSOC);
				$stmt->closeCursor();
				if (!$row || (int) $row['expired_time'] <= $now) {
					return null;
				}
				$body = $row['cache'];
			}
			return self::responseCode($body) === null ? null : $body;
		} catch (Throwable $error) {
			self::logFailure('read');
			return null;
		}
	}

	public function write(array $key, $body, $ttl, $now) {
		if (!self::hasIdentifier($key) || $ttl <= 0 || self::responseCode($body) === null) {
			return false;
		}
		try {
			$expiresAt = $now + $ttl;
			if ($this->redis !== null) {
				return $this->redis->add(self::redisKey($key), $body, $expiresAt);
			}

			// 不依赖 MySQL UPDATE 的 rowCount：内容完全相同时它也可能返回 0。
			$stmt = $this->database->prepare('SELECT `id` FROM `cache` WHERE ' . self::KEY_WHERE . ' ORDER BY `id` DESC LIMIT 1');
			if (!$stmt || !$stmt->execute($key)) {
				self::logFailure('MySQL lookup before write');
				return false;
			}
			$exists = $stmt->fetch(PDO::FETCH_ASSOC) !== false;
			$stmt->closeCursor();
			if ($exists) {
				// 覆盖同一完整缓存键的旧/坏记录，包括旧版本留下的重复行。
				$sql = 'UPDATE `cache` SET `expired_time` = :expired_time, `cache` = :cache WHERE ' . self::KEY_WHERE;
			} else {
				$sql = 'INSERT INTO `cache` (`expired_time`, `area`, `type`, `cache_type`, `cid`, `ep_id`, `cache`) VALUES (:expired_time, :area, :type, :cache_type, :cid, :ep_id, :cache)';
			}
			$params = $key;
			$params['expired_time'] = $expiresAt;
			$params['cache'] = $body;
			$stmt = $this->database->prepare($sql);
			if (!$stmt || !$stmt->execute($params)) {
				self::logFailure('MySQL write');
				return false;
			}
			return true;
		} catch (Throwable $error) {
			self::logFailure('write');
			return false;
		}
	}
}

// 使用同一个缓存键读写，不能再根据响应 vip_status 改变会员分组。
function playurl_cache_key() {
	global $member_type, $cache_type;
	return [
		'area' => (string) AREA,
		'type' => (int) $member_type,
		'cache_type' => (string) $cache_type,
		'cid' => (string) CID,
		'ep_id' => (string) EP_ID,
	];
}

function playurl_cache_store() {
	global $dbh;
	$redis = REDIS_ON ? new redisFunc(REDIS_HOST, REDIS_PORT, REDIS_PASS) : null;
	return new PlayurlCache($dbh, $redis);
}

// 保留入口协议：命中时直接输出并结束；未命中时让入口继续请求上游。
function get_cache() {
	$body = playurl_cache_store()->read(playurl_cache_key(), time());
	if ($body !== null) {
		exit($body);
	}
	return '';
}

function write_cache() {
	global $output;
	$code = PlayurlCache::responseCode($output);
	if ($code === null) {
		return;
	}
	// 保留现有的成功/错误缓存时长设置；无效 JSON 永远不缓存。
	switch ($code) {
		case 0: $ttl = CACHE_TIME; break;
		case -10403: $ttl = CACHE_TIME_10403; break;
		case -404: $ttl = CACHE_TIME_404; break;
		case -412: $ttl = CACHE_TIME_412; break;
		default: $ttl = CACHE_TIME_OTHER;
	}
	playurl_cache_store()->write(playurl_cache_key(), $output, (int) $ttl, time());
}
