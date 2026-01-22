<?php

namespace extend\websocket;

/**
 * Redis 连接管理助手
 * 负责 Redis 连接的创建、维护和健康检查
 */
class RedisHelper
{
    /**
     * 全局 Redis 连接实例
     */
    private static $redis = null;
    
    /**
     * 最后一次检查时间
     */
    private static $lastCheck = 0;
    
    /**
     * 获取 Redis 连接
     * 自动处理连接失效和重连
     * 
     * @return \Redis|null
     */
    public static function getRedis()
    {
        $now = time();
        
        try {
            // 如果 Redis 连接存在且最近检查过，直接返回
            if (self::$redis !== null && ($now - self::$lastCheck) < 5) {
                return self::$redis;
            }
            
            // 检查连接是否有效
            if (self::$redis !== null) {
                try {
                    self::$redis->ping();
                    self::$lastCheck = $now;
                    return self::$redis;
                } catch (\Exception $e) {
                    // 连接失效，重新连接
                    self::$redis = null;
                }
            }
            
            // 创建新连接
            $config = config('cache.stores.redis');
            self::$redis = new \Redis();
            self::$redis->connect(
                $config['host'] ?? '127.0.0.1',
                $config['port'] ?? 6379,
                3 // 3秒超时
            );
            
            if (!empty($config['password'])) {
                self::$redis->auth($config['password']);
            }
            
            self::$redis->select($config['select'] ?? 0);
            self::$lastCheck = $now;
            
            return self::$redis;
        } catch (\Exception $e) {
            echo "[" . date('H:i:s') . "] Redis 连接失败: " . $e->getMessage() . "\n";
            self::$redis = null;
            return null;
        }
    }
    
    /**
     * 清理所有 WebSocket 相关的 Redis 键
     * 
     * @return int 清理的键数量
     */
    public static function clearWebSocketKeys()
    {
        try {
            $redis = self::getRedis();
            if (!$redis) {
                return 0;
            }
            
            $keys = $redis->keys('ws:*');
            if (empty($keys)) {
                return 0;
            }
            
            $redis->del($keys);
            return count($keys);
        } catch (\Exception $e) {
            echo "[" . date('H:i:s') . "] 清理 Redis 键失败: " . $e->getMessage() . "\n";
            return 0;
        }
    }
    
    /**
     * 重置连接（用于测试或强制重连）
     */
    public static function reset()
    {
        self::$redis = null;
        self::$lastCheck = 0;
    }
}
