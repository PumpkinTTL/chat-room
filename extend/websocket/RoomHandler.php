<?php

namespace extend\websocket;

use app\service\RoomUserService;

/**
 * 房间处理器
 * 负责房间的加入、离开和广播功能
 */
class RoomHandler
{
    /**
     * 处理加入房间请求
     * 
     * @param object $connection Workerman 连接对象
     * @param array $msg 消息数据
     * @param ConnectionManager $connManager 连接管理器
     * @param object $worker Workerman Worker 对象
     * @return bool 是否成功加入
     */
    public static function handleJoin($connection, array $msg, ConnectionManager $connManager, $worker)
    {
        $connData = $connManager->get($connection->id);
        
        if (!$connData || !($connData['authed'] ?? false)) {
            $connection->send(json_encode(['type' => 'error', 'msg' => '请先认证']));
            return false;
        }

        $roomId = (int)($msg['room_id'] ?? 0);
        if ($roomId <= 0) {
            $connection->send(json_encode(['type' => 'error', 'msg' => '房间ID无效']));
            return false;
        }

        $userId = $connData['user_id'];
        $nickname = $connData['nickname'];

        // 验证用户是否已加入该房间（数据库层面）
        try {
            $isInRoom = RoomUserService::isUserInRoom($roomId, $userId);
            if (!$isInRoom) {
                $connection->send(json_encode(['type' => 'error', 'msg' => '您未加入此房间']));
                echo "[" . date('H:i:s') . "] 用户 {$userId} 未加入房间 {$roomId}\n";
                return false;
            }
        } catch (\Exception $e) {
            echo "[" . date('H:i:s') . "] 检查房间成员失败: " . $e->getMessage() . "\n";
            $connection->send(json_encode(['type' => 'error', 'msg' => '检查房间成员失败']));
            return false;
        }

        // 离开旧房间
        $oldRoomId = $connData['room_id'] ?? null;
        if ($oldRoomId && $oldRoomId != $roomId) {
            self::leaveOldRoom($connection, $oldRoomId, $userId, $nickname, $connManager, $worker);
        }

        // 检查该用户是否已有其他连接在新房间
        $userAlreadyInNewRoom = false;
        $roomConns = $connManager->getByRoom($roomId);
        foreach ($roomConns as $connId => $conn) {
            if ($connId != $connection->id && $conn['user_id'] == $userId) {
                $userAlreadyInNewRoom = true;
                break;
            }
        }

        // 更新房间
        $connManager->update($connection->id, ['room_id' => $roomId]);

        // 写入 Redis
        try {
            $redis = RedisHelper::getRedis();
            if ($redis) {
                $redis->sAdd("ws:room:{$roomId}:users", $userId);
                $redis->hMSet("ws:room:{$roomId}:user:{$userId}", [
                    'nick_name' => $nickname,
                    'join_time' => time()
                ]);
                $redis->set("ws:user:{$userId}:room", $roomId);
            }
        } catch (\Exception $e) {
            echo "[" . date('H:i:s') . "] 加入房间 Redis 操作失败: " . $e->getMessage() . "\n";
        }

        // 获取在线用户（按用户ID去重，包含头像信息）
        $onlineUserMap = [];
        $roomConns = $connManager->getByRoom($roomId);
        foreach ($roomConns as $conn) {
            if ($conn['user_id']) {
                $onlineUserMap[$conn['user_id']] = [
                    'user_id' => $conn['user_id'],
                    'nick_name' => $conn['nickname'],
                    'avatar' => $conn['avatar'] ?? ''
                ];
            }
        }
        $onlineUsers = array_values($onlineUserMap);

        $connection->send(json_encode([
            'type' => 'room_joined',
            'room_id' => $roomId,
            'users' => $onlineUsers,
            'online_count' => count($onlineUsers)
        ]));

        // 只有当用户之前不在房间时才广播加入消息
        if (!$userAlreadyInNewRoom) {
            self::broadcastToRoom($roomId, [
                'type' => 'user_joined',
                'room_id' => $roomId,
                'user_id' => $userId,
                'nickname' => $nickname,
                'avatar' => $connData['avatar'] ?? '',
                'online_count' => count($onlineUsers)
            ], $connManager, $worker, $connection->id);
        }

        echo "[" . date('H:i:s') . "] {$nickname} 加入房间 {$roomId} (连接#{$connection->id})\n";
        
        return true;
    }

    /**
     * 离开旧房间
     */
    private static function leaveOldRoom($connection, $oldRoomId, $userId, $nickname, ConnectionManager $connManager, $worker)
    {
        // 检查该用户是否还有其他连接在旧房间
        $userStillInOldRoom = false;
        $oldRoomConns = $connManager->getByRoom($oldRoomId);
        foreach ($oldRoomConns as $connId => $conn) {
            if ($connId != $connection->id && $conn['user_id'] == $userId) {
                $userStillInOldRoom = true;
                break;
            }
        }

        if (!$userStillInOldRoom) {
            try {
                $redis = RedisHelper::getRedis();
                if ($redis) {
                    $redis->sRem("ws:room:{$oldRoomId}:users", $userId);
                    $redis->del("ws:room:{$oldRoomId}:user:{$userId}");
                }
            } catch (\Exception $e) {
                echo "[" . date('H:i:s') . "] 离开旧房间 Redis 操作失败: " . $e->getMessage() . "\n";
            }

            // 计算旧房间在线人数
            $oldRoomUserIds = $connManager->getRoomUserIds($oldRoomId);

            self::broadcastToRoom($oldRoomId, [
                'type' => 'user_left',
                'room_id' => $oldRoomId,
                'user_id' => $userId,
                'nickname' => $nickname,
                'online_count' => count($oldRoomUserIds)
            ], $connManager, $worker, $connection->id);
        }
    }

    /**
     * 广播消息到房间（排除指定连接）
     * 
     * @param int $roomId 房间ID
     * @param array $data 要广播的数据
     * @param ConnectionManager $connManager 连接管理器
     * @param object $worker Workerman Worker 对象
     * @param int|null $excludeConnId 要排除的连接ID
     */
    public static function broadcastToRoom($roomId, array $data, ConnectionManager $connManager, $worker, $excludeConnId = null)
    {
        $message = json_encode($data);
        $roomConns = $connManager->getByRoom($roomId);
        
        foreach ($roomConns as $connId => $connData) {
            if ($excludeConnId && $connId == $excludeConnId) {
                continue;
            }
            
            if (isset($worker->connections[$connId])) {
                $worker->connections[$connId]->send($message);
            }
        }
    }

    /**
     * 广播消息到房间（排除指定用户的所有连接）
     * 
     * @param int $roomId 房间ID
     * @param array $data 要广播的数据
     * @param ConnectionManager $connManager 连接管理器
     * @param object $worker Workerman Worker 对象
     * @param int $excludeUserId 要排除的用户ID
     */
    public static function broadcastToRoomExcludeUser($roomId, array $data, ConnectionManager $connManager, $worker, $excludeUserId)
    {
        $message = json_encode($data);
        $roomConns = $connManager->getByRoom($roomId);
        
        foreach ($roomConns as $connId => $connData) {
            if ($connData['user_id'] == $excludeUserId) {
                continue;
            }
            
            if (isset($worker->connections[$connId])) {
                $worker->connections[$connId]->send($message);
            }
        }
    }
}
