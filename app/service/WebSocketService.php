<?php

namespace app\service;

use think\facade\Cache;

/**
 * WebSocket 在线用户管理服务
 * 用于 HTTP API 读取 Redis 中的在线状态
 */
class WebSocketService
{
    /**
     * 获取 Redis 实例
     */
    private static function getRedis()
    {
        try {
            return Cache::store('redis')->handler();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 获取房间在线用户列表
     * @param int $roomId 房间ID
     * @return array
     */
    public static function getRoomUsers($roomId)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return [];
        }

        try {
            $userIds = $redis->sMembers("ws:room:{$roomId}:users");
            if (empty($userIds)) {
                return [];
            }

            $users = [];
            foreach ($userIds as $userId) {
                $info = $redis->hGetAll("ws:room:{$roomId}:user:{$userId}");
                if ($info) {
                    $users[] = [
                        'user_id' => $userId,
                        'nick_name' => $info['nick_name'] ?? '未知',
                        'join_time' => $info['join_time'] ?? 0
                    ];
                }
            }
            return $users;
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 获取房间在线人数
     * @param int $roomId 房间ID
     * @return int
     */
    public static function getRoomOnlineCount($roomId)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return 0;
        }

        try {
            return (int) $redis->sCard("ws:room:{$roomId}:users");
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * 获取用户当前所在房间
     * @param int $userId 用户ID
     * @return int|null
     */
    public static function getUserRoom($userId)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return null;
        }

        try {
            $roomId = $redis->get("ws:user:{$userId}:room");
            return $roomId ? (int) $roomId : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * 检查用户是否在线（在某个房间内）
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isUserOnline($userId)
    {
        return self::getUserRoom($userId) !== null;
    }

    /**
     * 检查用户是否在指定房间内（WebSocket 在线）
     * @param int $roomId 房间ID
     * @param int $userId 用户ID
     * @return bool
     */
    public static function isUserInRoom($roomId, $userId)
    {
        $redis = self::getRedis();
        if (!$redis) {
            return false;
        }

        try {
            return $redis->sIsMember("ws:room:{$roomId}:users", $userId);
        } catch (\Exception $e) {
            return false;
        }
    }
}
